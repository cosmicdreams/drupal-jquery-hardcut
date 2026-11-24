<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Finds jQuery references in enabled contributed and custom extensions.
 */
final class ReadinessScanner {

  private const WARNING = '@extension depends on jQuery (@reason). jQuery is removed from Drupal core; test this module with test_jquery_free enabled. See https://www.drupal.org/node/CHANGE_RECORD.';

  public function __construct(
    private readonly string $root,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Scans libraries and scripts and logs one warning per affected extension.
   *
   * @return array<string, list<array{source: string, line: ?int, reason: string}>>
   *   Findings grouped by extension machine name.
   */
  public function scan(): array {
    $extensions = $this->moduleHandler->getModuleList() + $this->themeHandler->listInfo();
    ksort($extensions);
    $findings = [];

    foreach ($extensions as $name => $extension) {
      $path = $extension->getPath();
      if (str_starts_with($path . '/', 'core/')) {
        continue;
      }

      $libraries = $this->libraryDiscovery->getLibrariesByExtension($name);
      ksort($libraries);
      foreach ($libraries as $library => $definition) {
        foreach ($definition['dependencies'] as $dependency) {
          // Includes removed names such as core/jquery.once and
          // core/internal.jquery.form, even when discovery cannot resolve them.
          if (preg_match('~^core/(?:internal\.)?jquery(?:[._][\w.-]+)?$~D', $dependency)) {
            $findings[$name][] = [
              'source' => "$name/$library",
              'line' => NULL,
              'reason' => "Library depends on $dependency",
            ];
          }
        }
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
              'reason' => 'Possible jQuery reference',
            ];
          }
        }
      }

      if (isset($findings[$name])) {
        $reasons = array_unique(array_column($findings[$name], 'reason'));
        $this->logger->warning(self::WARNING, [
          '@extension' => $name,
          '@reason' => implode(', ', $reasons),
        ]);
      }
    }

    return $findings;
  }

  /**
   * Returns one report row per finding, ordered by extension.
   *
   * @return list<array{extension: string, source: string, line: ?int, reason: string}>
   *   Findings with extension names included in each row.
   */
  public function rows(): array {
    $rows = [];
    foreach ($this->scan() as $extension => $findings) {
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
