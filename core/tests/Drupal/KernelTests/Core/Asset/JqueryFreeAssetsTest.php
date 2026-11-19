<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Prevents new jQuery references in JavaScript assets of core libraries.
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
        foreach ($library['js'] as $asset) {
          $path = $asset['data'];
          if ($asset['type'] !== 'file' || isset($seen[$path]) || in_array($path, self::GENERATED_ASSETS, TRUE)) {
            continue;
          }
          $seen[$path] = TRUE;
          $this->assertFileIsReadable("$this->root/$path");
          if (self::referencesJquery(file_get_contents("$this->root/$path"))) {
            $actual[] = $path;
          }
        }
      }
    }
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
