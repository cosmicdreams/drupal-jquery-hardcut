<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

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
    $references = [];
    foreach ($files as $file) {
      foreach (token_get_all($file->getContents()) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
          $literal = substr($token[1], 1, -1);
          if (preg_match('~^[a-z0-9_]+/(?:internal\.)?jquery(?:[./_-]|$)~i', $literal) === 1) {
            $references[] = "{$file->getRelativePathname()}:{$token[2]} references $literal";
          }
        }
      }
    }
    $this->assertSame([], $references, implode("\n", $references));
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
    $this->assertDoesNotMatchRegularExpression('~(?:^|[/.])jquery(?:[./_-]|$)~i', $name, $location);
  }

  /**
   * Asserts that an asset path does not reference jQuery.
   */
  private function assertAssetPath(string $path, string $location): void {
    $this->assertDoesNotMatchRegularExpression(self::JQUERY_ASSET_PATH, $path, $location);
  }

}
