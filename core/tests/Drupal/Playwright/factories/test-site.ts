import { execFile } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { readdir, readFile, rmdir, unlink } from 'node:fs/promises';
import { resolve } from 'node:path';
import { promisify } from 'node:util';

const run = promisify(execFile);
const coreRoot = resolve(__dirname, '../../../..');
const repositoryRoot = resolve(coreRoot, '..');
const prefixPattern = /^test[0-9]{8}$/;

export interface SiteOptions {
  profile?: string;
  langcode?: string;
  setupFile?: string;
}

export interface TestSite {
  readonly baseURL: string;
  readonly routingCookie: {
    name: string;
    value: string;
    domain: string;
    path: string;
  };
  adminLoginURL(): Promise<string>;
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
async function testSiteCommand(args: string[]): Promise<string> {
  const command = ['php', './scripts/test-site.php', ...args];
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

function registryDirectory(): string {
  const id = process.env.DRUPAL_PLAYWRIGHT_RUN_ID ?? '';
  if (!/^[a-f0-9-]{36}$/.test(id)) {
    throw new Error('The Playwright test site registry was not initialized.');
  }
  return `sites/simpletest/.playwright/${id}`;
}

async function cleanupSite(record: string): Promise<void> {
  const path = resolve(repositoryRoot, record);
  let prefix: string;
  try {
    prefix = await readFile(path, 'utf8');
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return;
    }
    throw error;
  }
  if (!prefixPattern.test(prefix)) {
    throw new Error(
      `Refusing to tear down an invalid test site prefix in ${record}.`,
    );
  }
  await testSiteCommand(['tear-down', prefix, `--db-url=${databaseURL()}`]);
  await unlink(path);
}

export async function installSite(options: SiteOptions): Promise<TestSite> {
  const url = testBaseURL();
  const directory = registryDirectory();
  const record = `${directory}/${randomUUID()}`;
  const install = await testSiteCommand([
    'install',
    '--json',
    `--fixture-prefix-file=${record}`,
    `--base-url=${url.href}`,
    `--db-url=${databaseURL()}`,
    `--install-profile=${options.profile ?? 'playwright_testing'}`,
    `--langcode=${options.langcode ?? 'en'}`,
    ...(options.setupFile ? [`--setup-file=${options.setupFile}`] : []),
  ]);

  const {
    db_prefix: prefix,
    site_path: sitePath,
    user_agent: userAgent,
  } = JSON.parse(install);
  const registeredPrefix = await readFile(
    resolve(repositoryRoot, record),
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
    async adminLoginURL() {
      const link = await testSiteCommand([
        'user-login',
        '1',
        `--site-path=${sitePath}`,
      ]);
      // The console returns a root-relative route. Retain Drupal's base path.
      if (!/^\/user\/reset\//.test(link)) {
        throw new Error(
          'test-site.php returned an invalid administrator login link.',
        );
      }
      return new URL(link.slice(1), url).href;
    },
    cleanup: () => cleanupSite(record),
  };
}

export async function sweepOrphanedSites(): Promise<void> {
  const directory = registryDirectory();
  let records: string[];
  try {
    records = await readdir(resolve(repositoryRoot, directory));
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return;
    }
    throw error;
  }
  const results = await Promise.allSettled(
    records.map((record) => cleanupSite(`${directory}/${record}`)),
  );
  const failures = results.flatMap((result) =>
    result.status === 'rejected' ? [result.reason] : [],
  );
  if (failures.length) {
    throw new AggregateError(
      failures,
      'Could not tear down every Playwright test site.',
    );
  }
  await rmdir(resolve(repositoryRoot, directory));
}
