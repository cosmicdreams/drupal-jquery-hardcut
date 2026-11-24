<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ensures core libraries do not declare, depend on, or ship jQuery.
 */
#[Group('Asset')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
class JqueryFreeLibrariesTest extends KernelTestBase {

  use CoreLibraryDiscoveryTrait;

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
          $this->assertDoesNotMatchRegularExpression('~(?:^|/)jquery(?:[./@-]|$)~i', $asset['data'], "$theme: $id");
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
      }
    }
  }

  /**
   * Asserts that a library does not use a jQuery-family name.
   */
  private function assertLibraryName(string $name, string $location): void {
    $this->assertDoesNotMatchRegularExpression('~(?:^|[/.])jquery(?:[./_-]|$)~i', $name, $location);
  }

}
