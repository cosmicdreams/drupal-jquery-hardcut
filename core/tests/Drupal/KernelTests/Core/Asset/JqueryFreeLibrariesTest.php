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
 * Checks core libraries and source files for jQuery asset references.
 */
#[Group('Asset')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
class JqueryFreeLibrariesTest extends KernelTestBase {

  use CoreLibraryDiscoveryTrait;

  /**
   * Matches jQuery-family library names.
   */
  private const JQUERY_LIBRARY = '~(?:^|[/.])jquery(?:[./_-]|$)~i';

  /**
   * Matches local jQuery and jQuery UI paths and package URLs.
   */
  private const JQUERY_ASSET_PATH = '~(?:^|/|@)jquery(?:[./_@?#-]|$)~i';

  /**
   * Checks resolved definitions, including theme overrides and library hooks.
   */
  public function testCoreLibraries(): void {
    foreach ($this->coreLibrariesByTheme() as $theme => $libraries) {
      $this->assertArrayHasKey('core/drupal', $libraries, $theme);
      foreach ($libraries as $id => $definition) {
        $this->assertLibraryName($id, $theme);
        foreach ($definition['dependencies'] ?? [] as $dependency) {
          $this->assertLibraryName($dependency, "$theme: $id");
        }
        foreach (array_merge($definition['js'] ?? [], $definition['css'] ?? []) as $asset) {
          $this->assertAssetPath($asset['data'], "$theme: $id");
        }
      }
    }
  }

  /**
   * Checks library declarations for extensions used by tests.
   */
  public function testTestLibraries(): void {
    foreach ($this->testLibraryDeclarations() as $path => $libraries) {
      foreach ($libraries as $name => $definition) {
        $this->assertLibraryName($name, $path);
        foreach ($definition['dependencies'] ?? [] as $dependency) {
          $this->assertLibraryName($dependency, "$path: $name");
        }
        $css = array_merge(...array_values($definition['css'] ?? []));
        foreach (array_keys(($definition['js'] ?? []) + $css) as $asset) {
          $this->assertAssetPath($asset, "$path: $name");
        }
      }
    }
  }

  /**
   * Checks PHP string literals, including individual render attachments.
   */
  public function testPhpLibraryReferences(): void {
    $files = $this->coreFiles('/\.(?:php|inc|module|install|profile|theme|engine)$/')
      ->exclude('assets/vendor');
    // The canary exercises discovery of legacy IDs by adding them through
    // these test hooks. Keep the rest of core and the fixtures checked.
    $canary_fixture_references = [
      'modules/test_jquery_free/tests/modules/jqf_alter/src/Hook/AlterHooks.php:core/jquery' => TRUE,
      'modules/test_jquery_free/tests/themes/jqf_extend/src/Hook/ExtendHooks.php:core/jquery.ui.dialog' => TRUE,
    ];
    $references = [];
    foreach ($files as $file) {
      $path = $file->getRelativePathname();
      foreach (token_get_all($file->getContents()) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
          $literal = substr($token[1], 1, -1);
          $library = preg_match('~^[a-z0-9_]+/(?:internal\.)?jquery(?:[./_-]|$)~i', $literal);
          $asset = preg_match('~^(?:\S+\.(?:[cm]?js|css)(?:[?#].*)?|(?:https?:)?//\S+)$~i', $literal)
            && preg_match(self::JQUERY_ASSET_PATH, $literal);
          if (($library || $asset) && !isset($canary_fixture_references["$path:$literal"])) {
            $references[] = "$path:{$token[2]} references $literal";
          }
        }
      }
    }
    $this->assertSame([], $references, implode("\n", $references));
  }

  /**
   * Checks literal Twig library attachments.
   */
  public function testTwigLibraryReferences(): void {
    foreach ($this->coreFiles('*.twig')->exclude('assets/vendor') as $file) {
      $source = preg_replace('~\{#.*?#\}~s', '', $file->getContents());
      preg_match_all("~\\battach_library\\s*\\(\\s*(['\"])([^'\"]+)\\1~", $source, $matches);
      foreach ($matches[2] as $library) {
        $this->assertLibraryName($library, $file->getRelativePathname());
      }
    }
  }

  /**
   * Checks libraries attached by theme metadata.
   */
  public function testThemeLibraryReferences(): void {
    foreach ($this->coreFiles('*.info.yml') as $file) {
      $info = Yaml::decode($file->getContents());
      if (($info['type'] ?? NULL) === 'theme') {
        foreach ($info['libraries'] ?? [] as $library) {
          // This canary fixture exercises direct theme library attachments.
          if ($file->getRelativePathname() === 'modules/test_jquery_free/tests/themes/jqf_global/jqf_global.info.yml' && $library === 'core/tabbable.jquery.shim') {
            continue;
          }
          $this->assertLibraryName($library, $file->getRelativePathname());
        }
      }
    }
  }

  /**
   * Checks CSS imports and URLs in shipped and test stylesheets.
   */
  public function testStylesheetReferences(): void {
    $references = [];
    foreach ($this->coreFiles('*.css') as $file) {
      $source = preg_replace('~/\*.*?\*/~s', '', $file->getContents());
      preg_match_all('~(?:@import\s+(?=["\'])|url\(\s*)["\']?([^"\'\s;)]+)~i', $source, $matches);
      foreach ($matches[1] as $url) {
        if (preg_match(self::JQUERY_ASSET_PATH, $url) === 1) {
          $references[] = "{$file->getRelativePathname()} references $url";
        }
      }
    }
    $this->assertSame([], $references, implode("\n", $references));
  }

  /**
   * Finds core files outside local development dependencies.
   */
  private function coreFiles(string $name): Finder {
    return (new Finder())
      ->files()
      ->in("$this->root/core")
      ->exclude('node_modules')
      ->name($name);
  }

  /**
   * Asserts that a library does not use a jQuery-family name.
   */
  private function assertLibraryName(string $name, string $location): void {
    $this->assertDoesNotMatchRegularExpression(self::JQUERY_LIBRARY, $name, $location);
  }

  /**
   * Asserts that an asset path does not reference jQuery.
   */
  private function assertAssetPath(string $path, string $location): void {
    $this->assertDoesNotMatchRegularExpression(self::JQUERY_ASSET_PATH, $path, $location);
  }

}
