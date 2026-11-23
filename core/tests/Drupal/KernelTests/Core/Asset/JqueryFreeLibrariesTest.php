<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Asset\LibraryDependencyResolver;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Prevents new jQuery dependencies in resolved core library closures.
 *
 * The baseline fixture records reachable libraries per theme and
 * library. Empty lists mean none of the tracked dependencies are reachable.
 * To regenerate it, run this test with JQUERY_FREE_REGENERATE=1. Keep the
 * baseline for compatibility work and remove entries from the separate
 * allow-list as the libraries stop depending on jQuery.
 */
#[Group('Asset')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
class JqueryFreeLibrariesTest extends KernelTestBase {

  use CoreLibraryDiscoveryTrait;

  /**
   * Checks the jQuery dependencies against the shrinking allow-list.
   */
  public function testLibraryClosures(): void {
    $map = $this->buildClosureMap();
    $fixture = __DIR__ . '/fixtures/jquery-library-closures.yml';
    if (getenv('JQUERY_FREE_REGENERATE') === '1') {
      $contents = "# Resolved jQuery libraries by theme and library.\n";
      foreach ($map as $key => $closure) {
        $contents .= "$key: [" . implode(', ', $closure) . "]\n";
      }
      $this->assertNotFalse(file_put_contents($fixture, $contents));
      $this->assertSame($map, Yaml::decode(file_get_contents($fixture)));
      return;
    }

    $baseline = Yaml::decode(file_get_contents($fixture));
    $new_edges = [];
    foreach ($map as $library => $closure) {
      $added = array_diff($closure, $baseline[$library] ?? []);
      if ($added) {
        $new_edges[] = "$library reaches new jQuery libraries or widgets: " . implode(', ', $added);
      }
    }
    $this->assertSame([], $new_edges, implode("\n", $new_edges));

    $allowed = Yaml::decode(file_get_contents(__DIR__ . '/fixtures/jquery-library-allow-list.yml')) ?? [];
    $actual = array_keys(array_filter($map, static fn (array $closure): bool => (bool) array_intersect(self::JQUERY_LIBRARIES, $closure)));

    $messages = [];
    foreach (array_diff($actual, $allowed) as $library) {
      $messages[] = "$library has a new jQuery dependency: " . implode(', ', array_intersect(self::JQUERY_LIBRARIES, $map[$library]));
    }
    foreach (array_diff($allowed, $actual) as $library) {
      $messages[] = "Remove '- $library' from jquery-library-allow-list.yml.";
    }
    $this->assertSame($allowed, $actual, implode("\n", $messages));
  }

  /**
   * Checks each ordinary test declaration against the pinned core closures.
   */
  public function testOrdinaryTestLibraryClosures(): void {
    $declarations = iterator_to_array($this->testLibraryDeclarations());
    $this->assertNotEmpty($declarations);
    $definitions = [];
    foreach ($declarations as $path => $extension_libraries) {
      $extension = basename($path, '.libraries.yml');
      $definitions[$extension] = ($definitions[$extension] ?? []) + $extension_libraries;
    }

    $actual = [];
    foreach ($this->coreLibrariesByTheme() as $theme => $libraries) {
      $discovery = $this->container->get('library.discovery');
      // Expose declarations to the dependency resolver without enabling their
      // extensions. Use production library lookups for other dependencies.
      foreach ($declarations as $path => $extension_libraries) {
        $extension = basename($path, '.libraries.yml');
        $registry = $this->createStub(LibraryDiscoveryInterface::class);
        $registry->method('getLibraryByName')
          ->willReturnCallback(static fn ($name, $id) => ($name === $extension ? $extension_libraries[$id] ?? NULL : NULL)
            ?? $definitions[$name][$id]
            ?? $discovery->getLibraryByName($name, $id));
        $resolver = new LibraryDependencyResolver($registry);
        foreach (array_keys($extension_libraries) as $id) {
          $closure = $resolver->getLibrariesWithDependencies(["$extension/$id"]);
          $matches = self::trackedDependencies($closure);
          if ($matches) {
            $actual["$path:$id"][$theme] = $matches;
          }
        }
      }
    }
    ksort($actual, SORT_STRING);

    $allowed = Yaml::decode(file_get_contents(__DIR__ . '/fixtures/jquery-test-library-allow-list.yml')) ?? [];
    $this->assertSame(array_column($allowed, 'themes', 'fixture'), $actual);
  }

  /**
   * Builds the map of resolved jQuery dependencies and widget assets.
   *
   * @return array<string, string[]>
   *   Sorted entries keyed by theme and library ID.
   */
  private function buildClosureMap(): array {
    $map = [];
    foreach ($this->coreLibrariesByTheme() as $theme => $libraries) {
      $resolver = $this->container->get('library.dependency_resolver');
      foreach (array_keys($libraries) as $library) {
        $closure = $resolver->getLibrariesWithDependencies([$library]);
        $map["$theme/$library"] = self::trackedDependencies($closure);
      }
    }
    ksort($map, SORT_STRING);
    return $map;
  }

  /**
   * Returns the legacy libraries in a resolved closure.
   *
   * @param string[] $closure
   *   The resolved library IDs.
   *
   * @return string[]
   *   The sorted legacy IDs.
   */
  private static function trackedDependencies(array $closure): array {
    $matches = array_intersect(self::JQUERY_LIBRARIES, $closure);
    sort($matches, SORT_STRING);
    return $matches;
  }

}
