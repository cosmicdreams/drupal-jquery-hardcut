import { execFile } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { mkdir, readdir, readFile, rmdir, stat } from 'node:fs/promises';
import { resolve } from 'node:path';
import { promisify } from 'node:util';

const run = promisify(execFile);
const coreRoot = resolve(__dirname, '../../../..');
const repositoryRoot = resolve(coreRoot, '..');
const prefixPattern = /^test[0-9]{8}$/;
const uuidPattern = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/;
const staleRunAge = 24 * 60 * 60 * 1000;
const installTimeout = process.env.DRUPAL_TEST_INSTALL_TIMEOUT || '40';
const installTimeoutSeconds = Number(installTimeout);
if (
  !/^[1-9][0-9]*$/.test(installTimeout) ||
  !Number.isSafeInteger(installTimeoutSeconds * 1000 + 20_000)
) {
  throw new Error(
    'DRUPAL_TEST_INSTALL_TIMEOUT must be a positive integer in seconds.',
  );
}

// Leave time for the install deadline, kill grace, and registry operations.
export const siteFixtureTimeout = installTimeoutSeconds * 1000 + 20_000;
const registryCommand = [
  'env',
  `DRUPAL_TEST_INSTALL_TIMEOUT=${installTimeout}`,
  'sh',
  './tests/Drupal/Playwright/support/test-site-registry.sh',
];

export interface SiteOptions {
  profile?: string;
  langcode?: string;
  setupFile?: string;
  modules?: readonly string[];
}

export interface TestSite {
  readonly baseURL: string;
  readonly routingCookie: {
    name: string;
    value: string;
    domain: string;
    path: string;
  };
  loginURL(uid: number): Promise<string>;
  cleanup(): Promise<void>;
}

export function testBaseURL(): URL {
  const url = new URL(process.env.DRUPAL_TEST_BASE_URL ?? '');
  const { hostname } = url;
  if (
    !['http:', 'https:'].includes(url.protocol) ||
    !(
      ['localhost', '127.0.0.1', '[::1]', 'web'].includes(hostname) ||
      hostname.endsWith('.ddev.site')
    ) ||
    url.username ||
    url.password ||
    url.search ||
    url.hash
  ) {
    throw new Error('DRUPAL_TEST_BASE_URL must identify a local test server.');
  }
  url.pathname = `${url.pathname.replace(/\/+$/, '')}/`;
  return url;
}

function databaseURL(): string {
  const dbURL = process.env.DRUPAL_TEST_DB_URL;
  if (!dbURL || new URL(dbURL).hash) {
    throw new Error('Set DRUPAL_TEST_DB_URL to an unprefixed test database.');
  }
  return dbURL;
}

/**
 * Runs PHP inside DDEV locally and directly in the GitLab job's PHP image.
 */
async function containerCommand(command: string[]): Promise<string> {
  const inContainer =
    process.env.GITLAB_CI === 'true' || process.env.IS_DDEV_PROJECT === 'true';
  const invocation = inContainer
    ? command
    : [
        'ddev',
        'exec',
        '--quiet',
        '--dir',
        '/var/www/html/core',
        '--raw',
        '--',
        ...command,
      ];
  const [executable, ...parameters] = invocation;
  const { stdout } = await run(executable, parameters, {
    cwd: coreRoot,
    maxBuffer: 10 * 1024 * 1024,
  });
  return stdout.trim();
}

function registryRoot(): string {
  const scope = createHash('sha256').update(databaseURL()).digest('hex');
  return `sites/simpletest/.playwright/${scope}`;
}

function registryDirectory(): string {
  const id = process.env.DRUPAL_PLAYWRIGHT_RUN_ID ?? '';
  if (!uuidPattern.test(id)) {
    throw new Error('The Playwright test site registry was not initialized.');
  }
  return `${registryRoot()}/${id}`;
}

async function recordStat(record: string) {
  try {
    return await stat(resolve(repositoryRoot, record));
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
      throw error;
    }
    return null;
  }
}

async function cleanupSite(record: string, recover = false): Promise<void> {
  if (!(await recordStat(record))) {
    return;
  }
  try {
    await containerCommand([
      ...registryCommand,
      recover ? 'recover' : 'cleanup',
      record,
      `--db-url=${databaseURL()}`,
    ]);
  } catch (error) {
    // A concurrent sweep may already have removed the record.
    if (await recordStat(record)) {
      const { stderr } = error as { stderr?: string };
      throw new Error(
        `Could not tear down Playwright test site record ${record}. ${stderr?.trim() ?? ''}`.trim(),
      );
    }
  }
}

export async function installSite(options: SiteOptions): Promise<TestSite> {
  const url = testBaseURL();
  const record = `${registryDirectory()}/${randomUUID()}`;
  await mkdir(resolve(repositoryRoot, record), { recursive: true });
  const install = await containerCommand([
    ...registryCommand,
    'install',
    record,
    `--base-url=${url.href}`,
    `--db-url=${databaseURL()}`,
    `--install-profile=${options.profile ?? 'playwright_testing'}`,
    `--langcode=${options.langcode ?? 'en'}`,
    ...(options.modules ?? []).map((module) => `--module=${module}`),
    ...(options.setupFile ? [`--setup-file=${options.setupFile}`] : []),
  ]);

  const {
    db_prefix: prefix,
    site_path: sitePath,
    user_agent: userAgent,
  } = JSON.parse(install);
  const registeredPrefix = await readFile(
    resolve(repositoryRoot, record, 'complete'),
    'utf8',
  );
  if (
    typeof prefix !== 'string' ||
    !prefixPattern.test(prefix) ||
    registeredPrefix !== prefix ||
    sitePath !== `sites/simpletest/${prefix.slice(4)}` ||
    typeof userAgent !== 'string' ||
    !userAgent.startsWith(`simple${prefix}:`)
  ) {
    throw new Error('test-site.php returned invalid site connection details.');
  }

  return {
    baseURL: url.href,
    routingCookie: {
      name: 'SIMPLETEST_USER_AGENT',
      value: encodeURIComponent(userAgent),
      domain: url.hostname,
      path: url.pathname,
    },
    async loginURL(uid) {
      const link = await containerCommand([
        'php',
        './scripts/test-site.php',
        'user-login',
        String(uid),
        `--site-path=${sitePath}`,
      ]);
      // The console returns a root-relative route. Retain Drupal's base path.
      if (!/^\/user\/reset\//.test(link)) {
        throw new Error('test-site.php returned an invalid user login link.');
      }
      return new URL(link.slice(1), url).href;
    },
    cleanup: () => cleanupSite(record),
  };
}

async function registryEntries(directory: string): Promise<string[]> {
  try {
    const entries = await readdir(resolve(repositoryRoot, directory), {
      withFileTypes: true,
    });
    return entries
      .filter((entry) => entry.isDirectory() && uuidPattern.test(entry.name))
      .map(({ name }) => `${directory}/${name}`);
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return [];
    }
    throw error;
  }
}

async function sweepRun(directory: string, recover = false): Promise<void> {
  const records = await registryEntries(directory);
  const results = await Promise.allSettled(
    records.map((record) => cleanupSite(record, recover)),
  );
  const failures = results.flatMap((result) =>
    result.status === 'rejected' ? [result.reason] : [],
  );
  if (failures.length) {
    throw new AggregateError(
      failures,
      failures.map((error) => String(error)).join('\n'),
    );
  }
  try {
    await rmdir(resolve(repositoryRoot, directory));
  } catch (error) {
    const { code } = error as NodeJS.ErrnoException;
    if (code === 'ENOTEMPTY' && !recover) {
      const remaining = await readdir(resolve(repositoryRoot, directory));
      throw new Error(
        `Playwright test site records remain: ${remaining.map((name) => `${directory}/${name}`).join(', ')}`,
      );
    }
    if (code !== 'ENOENT' && code !== 'ENOTEMPTY') {
      throw error;
    }
  }
}

export async function sweepOrphanedSites(): Promise<void> {
  await sweepRun(registryDirectory());
}

export async function recoverStaleSites(): Promise<void> {
  // Leave other runs alone while they may still be using their installed sites.
  // Legacy records lack a database scope and an installer lock.
  for (const directory of await registryEntries(registryRoot())) {
    const record = await recordStat(directory);
    if (record && Date.now() - record.mtimeMs >= staleRunAge) {
      await sweepRun(directory, true);
    }
  }
}
