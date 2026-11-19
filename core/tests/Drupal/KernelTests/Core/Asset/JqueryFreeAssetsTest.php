<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
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
              $source = file_get_contents($file);
              $references_jquery = $references_jquery || match ($type) {
                'js' => self::referencesJquery($source),
                'css' => self::referencesJqueryCss($source),
              };
            }
            if ($references_jquery) {
              $actual[] = $path;
            }
          }
        }
      }
    }
    $actual = array_merge($actual, $this->phpAssetReferences());
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
    preg_match_all('~(?:@import\s+|url\(\s*)["\']?([^"\'\s;)]+)~i', $source, $matches);
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
   * @return string[]
   *   Relative paths of PHP source files containing legacy asset references.
   */
  private function phpAssetReferences(): array {
    $sources = new Finder()
      ->files()
      ->in(["$this->root/core/lib", "$this->root/core/modules", "$this->root/core/profiles", "$this->root/core/themes"])
      ->exclude('tests')
      ->notName('*.api.php')
      ->name('/\.(?:php|inc|module|install|profile|theme)$/');

    $paths = [];
    foreach ($sources as $file) {
      $path = substr($file->getPathname(), strlen($this->root) + 1);
      if (self::isNightwatchPath($path)) {
        continue;
      }
      foreach (token_get_all($file->getContents()) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
          $literal = substr($token[1], 1, -1);
          if (in_array($literal, self::JQUERY_LIBRARIES, TRUE) || self::isLegacyAssetPath($literal)) {
            $paths[] = $path;
            break;
          }
        }
      }
    }
    return $paths;
  }

  /**
   * Matches jQuery spellings in JavaScript, ignoring source comments.
   */
  private static function referencesJquery(string $source): bool {
    // Consume literals before looking for comments so their slash sequences
    // do not hide the rest of a line. Preserve the literals for the scan.
    $tokens = <<<'REGEX'
      ~
        "(?:[^"\\\r\n]++|\\.)*+"
        | '(?:[^'\\\r\n]++|\\.)*+'
        | `(?:[^`\\]++|\\.)*+`
        | (?:(?<=[=(,:;!&|?{\[])|(?<=\breturn))\h*\K
          /(?![/*])(?:[^/\[\\\r\n]++|\\[^\r\n]|\[(?:[^\]\\\r\n]++|\\[^\r\n])*+\])++/[a-z]*+
        | (?<comment>//[^\r\n]*|/\*.*?\*/)
      ~sx
      REGEX;
    $source = preg_replace_callback($tokens, static fn (array $match): string => isset($match['comment']) ? ' ' : $match[0], $source);
    if ($source === NULL) {
      throw new \RuntimeException(preg_last_error_msg());
    }
    return preg_match('/(?<![\w$])(?:jQuery(?![\w$])|\$[.(])/', $source) === 1;
  }

}
