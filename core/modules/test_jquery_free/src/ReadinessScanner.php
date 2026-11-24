<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Finds jQuery references in enabled contributed and custom extensions.
 */
final class ReadinessScanner {

  private const WARNING = '@extension depends on jQuery (@reason). jQuery is removed from Drupal core; test this module with test_jquery_free enabled. See https://www.drupal.org/node/CHANGE_RECORD.';

  private const CACHE_ID = 'test_jquery_free.readiness';

  public function __construct(
    private readonly string $root,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ReadinessLibraryScanner $libraryScanner,
    private readonly CacheBackendInterface $cache,
    private readonly CacheBackendInterface $moduleCache,
    private readonly CacheBackendInterface $themeCache,
    private readonly ModuleExtensionList $moduleList,
    private readonly ThemeExtensionList $themeList,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reads the last scan without running discovery.
   *
   * @return array<string, list<array{source: string, line: ?int, theme: ?string, reason: string}>>|null
   *   Cached findings, or NULL when a new scan is needed.
   */
  public function cachedFindings(): ?array {
    $cached = $this->cache->get(self::CACHE_ID);
    if ($cached && $cached->data['extensions'] === $this->extensionCacheVersions()) {
      return $cached->data['findings'];
    }
    return NULL;
  }

  /**
   * Reads or refreshes the report and logs only when performing discovery.
   *
   * @return array<string, list<array{source: string, line: ?int, theme: ?string, reason: string}>>
   *   Findings grouped by extension machine name.
   */
  public function scan(bool $rescan = FALSE): array {
    if (!$rescan && ($findings = $this->cachedFindings()) !== NULL) {
      return $findings;
    }

    // Warm the extension list entries so resets can invalidate the report.
    $this->moduleList->getList();
    $this->themeList->getList();
    $versions = $this->extensionCacheVersions();
    $themes = $this->themeHandler->listInfo();
    ksort($themes);
    $extensions = $this->moduleHandler->getModuleList() + $themes;
    ksort($extensions);
    $findings = $this->libraryScanner->scan($extensions, array_keys($themes));

    foreach ($extensions as $name => $extension) {
      $path = $extension->getPath();
      if (str_starts_with($path . '/', 'core/')) {
        continue;
      }

      $directory = $this->root . '/' . $path;
      $files = Finder::create()
        ->in($directory)
        ->exclude(['tests', 'node_modules'])
        ->filter(static fn (\SplFileInfo $file): bool => !$file->isDir() || !glob($file->getPathname() . '/*.info.yml'), TRUE)
        ->files()
        ->name('/\.(?:js|mjs|cjs)$/i')
        ->sortByName();
      foreach ($files as $file) {
        if (!$file->isReadable()) {
          continue;
        }
        foreach (new \SplFileObject($file->getPathname()) as $index => $line) {
          if (self::referencesJquery($line)) {
            $findings[$name][] = [
              'source' => $path . '/' . $file->getRelativePathname(),
              'line' => $index + 1,
              'theme' => NULL,
              'reason' => 'Possible jQuery reference',
            ];
          }
        }
      }
    }
    ksort($findings);
    foreach ($findings as $name => $rows) {
      $this->logger->warning(self::WARNING, [
        '@extension' => $name,
        '@reason' => implode(', ', array_unique(array_column($rows, 'reason'))),
      ]);
    }

    $this->cache->set(self::CACHE_ID, [
      'findings' => $findings,
      'extensions' => $versions,
    ], Cache::PERMANENT, ['library_info', 'config:core.extension']);
    return $findings;
  }

  /**
   * Drops the saved report after an extension change.
   */
  public function invalidate(): void {
    $this->cache->delete(self::CACHE_ID);
  }

  /**
   * Tracks the cache entries explicitly deleted by extension list resets.
   */
  private function extensionCacheVersions(): array {
    return [
      'module' => $this->moduleCache->get('core.extension.list.module')->created ?? NULL,
      'theme' => $this->themeCache->get('core.extension.list.theme')->created ?? NULL,
    ];
  }

  /**
   * Returns one report row per finding, ordered by extension.
   *
   * @return list<array{extension: string, source: string, line: ?int, theme: ?string, reason: string}>
   *   Findings with extension names included in each row.
   */
  public function rows(bool $rescan = FALSE): array {
    $rows = [];
    foreach ($this->scan($rescan) as $extension => $findings) {
      foreach ($findings as $finding) {
        $rows[] = ['extension' => $extension] + $finding;
      }
    }
    return $rows;
  }

  /**
   * Checks a source line for common jQuery spellings.
   *
   * This heuristic matches tokens in comments and strings. It can mistake
   * other uses of $ for jQuery and miss aliases or split-line expressions.
   */
  public static function referencesJquery(string $line): bool {
    return (bool) preg_match('/(?<![\w$])(?:jQuery(?![\w$])|\$\h*[.(])|\bfunction\h*\(\h*\$(?![\w$])/', $line);
  }

}
