<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

/**
 * Prevents new jQuery references in core assets and literal PHP attachments.
 */
#[Group('Asset')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
class JqueryFreeAssetsTest extends KernelTestBase {

  use CoreLibraryDiscoveryTrait;

  /**
   * Placeholder assets whose paths are replaced by a JavaScript alter hook.
   */
  private const GENERATED_ASSETS = [
    'core/assets/vendor/ckeditor5/translation.js',
    'core/modules/locale/locale.translation.js',
  ];

  /**
   * Checks the sorted asset allow-list.
   */
  public function testJqueryReferences(): void {
    $allowed = Yaml::decode(file_get_contents(__DIR__ . '/fixtures/jquery-asset-allow-list.yml')) ?? [];
    $legacy_js = self::legacyJavascriptPaths();
    $seen = [];
    $actual = [];
    foreach ($this->coreLibrariesByTheme() as $libraries) {
      foreach ($libraries as $library) {
        foreach (['js', 'css'] as $type) {
          foreach ($library[$type] as $asset) {
            $path = $asset['data'];
            if (isset($seen[$path])
              || self::isNightwatchPath($path)
              || in_array($path, self::GENERATED_ASSETS, TRUE)) {
              continue;
            }
            $seen[$path] = TRUE;
            $references_jquery = self::isLegacyAssetPath($path);
            if ($asset['type'] === 'file') {
              $file = "$this->root/$path";
              // Umami already references this missing file on the baseline.
              if ($path === 'core/profiles/demo_umami/themes/umami/css/classy/components/indented.css' && !is_file($file)) {
                continue;
              }
              $this->assertFileIsReadable($file);
              $references_jquery = $references_jquery || match ($type) {
                'js' => isset($legacy_js[$path]),
                'css' => self::referencesJqueryCss(file_get_contents($file)),
              };
            }
            if ($references_jquery) {
              $actual[] = $path;
            }
          }
        }
      }
    }
    $actual = array_merge($actual, array_keys($this->phpAssetReferences(test_sources: FALSE)));
    sort($actual, SORT_STRING);

    $messages = [];
    foreach (array_diff($actual, $allowed) as $path) {
      $messages[] = "$path references jQuery.";
    }
    foreach (array_diff($allowed, $actual) as $path) {
      $messages[] = "Remove '- $path' from jquery-asset-allow-list.yml.";
    }
    $this->assertSame($allowed, $actual, implode("\n", $messages));
  }

  /**
   * Checks the assets declared by ordinary test extensions.
   */
  public function testOrdinaryTestLibraryAssets(): void {
    $legacy_js = self::legacyJavascriptPaths();
    $actual = [];
    foreach ($this->testLibraryDeclarations() as $path => $libraries) {
      foreach ($libraries as $id => $definition) {
        $css = array_merge(...array_values($definition['css'] ?? []));
        foreach (['js' => $definition['js'] ?? [], 'css' => $css] as $type => $assets) {
          foreach ($assets as $uri => $options) {
            $references_jquery = self::isLegacyAssetPath($uri);
            if (($options['type'] ?? NULL) !== 'external' && !str_contains($uri, '://') && !str_starts_with($uri, '//')) {
              $asset_path = str_starts_with($uri, '/') ? substr($uri, 1) : dirname($path) . '/' . $uri;
              $asset_path = Path::canonicalize(preg_split('/[?#]/', $asset_path, 2)[0]);
              if (self::isNightwatchPath($asset_path)) {
                continue;
              }
              $file = "$this->root/$asset_path";
              // Some attachment fixtures use synthetic paths to test sorting
              // or URL handling. Scan shipped bytes where there is a file.
              if (is_file($file)) {
                $this->assertFileIsReadable($file);
                $references_jquery = $references_jquery || match ($type) {
                  'js' => isset($legacy_js[$asset_path]),
                  'css' => self::referencesJqueryCss(file_get_contents($file)),
                };
              }
            }
            if ($references_jquery) {
              $actual["$path:$id"][] = $uri;
            }
          }
        }
      }
    }
    foreach ($actual as &$uris) {
      sort($uris, SORT_STRING);
    }
    unset($uris);
    ksort($actual, SORT_STRING);
    $allowed = Yaml::decode(file_get_contents(__DIR__ . '/fixtures/jquery-test-asset-allow-list.yml')) ?? [];
    $this->assertSame(array_column($allowed, 'assets', 'fixture'), $actual);
  }

  /**
   * Checks literal legacy references in test PHP, including test extensions.
   */
  public function testOrdinaryTestPhpAttachments(): void {
    $actual = $this->phpAssetReferences(test_sources: TRUE);
    $allowed = Yaml::decode(file_get_contents(__DIR__ . '/fixtures/jquery-test-php-allow-list.yml')) ?? [];
    $this->assertSame(array_column($allowed, 'literals', 'path'), $actual);
  }

  /**
   * Tests direct and URL imports of legacy stylesheets.
   */
  public function testStylesheetImports(): void {
    $this->assertTrue(self::referencesJqueryCss('@import url("jquery-ui.css");'));
    $this->assertTrue(self::referencesJqueryCss('@import url(jquery-ui.css);'));
    $this->assertTrue(self::referencesJqueryCss('@import "jquery-ui.css";'));
    $this->assertFalse(self::referencesJqueryCss('@import url("dialog.css");'));
  }

  /**
   * Matches local vendor paths and conventional remote jQuery asset names.
   */
  private static function isLegacyAssetPath(string $path): bool {
    return preg_match(
      '~(?:^|/)(?:assets/vendor/jquery[^/]*/|css/components/jquery\.ui/|misc/(?:jquery\.form|position|jquery\.drupal-bc)\.js(?:$|[?#])|jquery(?:[.-]ui)?(?:[.-]\d[\d.]*)?(?:\.min)?\.(?:js|css)(?:$|[?#]))~i',
      $path,
    ) === 1;
  }

  /**
   * Finds legacy assets referenced by stylesheet imports and URLs.
   */
  private static function referencesJqueryCss(string $source): bool {
    $source = preg_replace('~/\*.*?\*/~s', '', $source);
    preg_match_all('~(?:@import\s+(?=["\'])|url\(\s*)["\']?([^"\'\s;)]+)~i', $source, $matches);
    foreach ($matches[1] as $url) {
      if (self::isLegacyAssetPath($url) || preg_match('~(?:^|/)ui-[^/]+\.css(?:$|[?#])~i', $url)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Finds core PHP files with literal legacy library IDs or asset paths.
   *
   * Tokenization excludes comments. Computed attachments are covered by
   * resolved assets and runtime tests when the relevant routes are exercised.
   *
   * @param bool $test_sources
   *   Whether to check test source files or production source files.
   *
   * @return array<string, array<string, int>>
   *   Literal counts keyed by the relative source file path.
   */
  private function phpAssetReferences(bool $test_sources): array {
    $sources = new Finder()
      ->files()
      ->in([
        "$this->root/core/lib",
        "$this->root/core/modules",
        "$this->root/core/profiles",
        "$this->root/core/themes",
        "$this->root/core/tests",
      ])
      ->exclude('node_modules')
      ->name('/\.(?:php|inc|module|install|profile|theme|engine)$/');
    if (!$test_sources) {
      $sources->notName('*.api.php');
    }

    $paths = [];
    foreach ($sources as $file) {
      $path = substr($file->getPathname(), strlen($this->root) + 1);
      if (self::isNightwatchPath($path) || str_contains($path, '/tests/') !== $test_sources) {
        continue;
      }
      foreach (token_get_all($file->getContents()) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
          $literal = substr($token[1], 1, -1);
          if (in_array($literal, self::JQUERY_LIBRARIES, TRUE) || self::isLegacyAssetPath($literal)) {
            $paths[$path][$literal] = ($paths[$path][$literal] ?? 0) + 1;
          }
        }
      }
    }
    foreach ($paths as &$literals) {
      ksort($literals, SORT_STRING);
    }
    unset($literals);
    ksort($paths, SORT_STRING);
    return $paths;
  }

  /**
   * Returns legacy JavaScript paths checked by the Node source ratchet.
   *
   * Keep checking where these files are attached, even when an owning slice
   * still has a source exception for the file.
   *
   * @return array<string, true>
   *   Legacy files keyed by repository-relative path.
   */
  private static function legacyJavascriptPaths(): array {
    $allow_list = json_decode(file_get_contents(__DIR__ . '/fixtures/jquery-source-allow-list.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    $files = array_keys($allow_list['sources'] + $allow_list['distribution']);
    return array_fill_keys(array_map(static fn (string $path): string => "core/$path", $files), TRUE);
  }

}
