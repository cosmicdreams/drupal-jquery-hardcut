<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Records jQuery libraries and widgets reachable from core libraries.
 *
 * The fixture lists one theme and library combination per line. Empty lists
 * indicate that none of the tracked libraries or widgets are reachable.
 * To regenerate the baseline, run this test with JQUERY_FREE_REGENERATE=1.
 */
#[Group('Asset')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
class JqueryFreeLibrariesTest extends KernelTestBase {

  use CoreLibraryDiscoveryTrait;

  /**
   * The jQuery family of libraries.
   */
  private const LIBRARIES = [
    'core/drupal.jquery.position',
    'core/internal.jquery.form',
    'core/internal.jquery_ui',
    'core/jquery',
  ];

  /**
   * The jQuery UI widgets tracked in the closure map.
   */
  private const WIDGETS = [
    'core/assets/vendor/jquery.ui/ui/widgets/autocomplete-min.js' => 'jquery-ui-autocomplete',
    'core/assets/vendor/jquery.ui/ui/widgets/dialog-min.js' => 'jquery-ui-dialog',
  ];

  /**
   * Checks the baseline closure map.
   */
  public function testLibraryClosures(): void {
    $map = $this->buildClosureMap();
    $fixture = __DIR__ . '/fixtures/jquery-library-closures.yml';
    if (getenv('JQUERY_FREE_REGENERATE') === '1') {
      $contents = "# Resolved jQuery libraries and widgets by theme and library.\n";
      foreach ($map as $key => $closure) {
        $contents .= "$key: [" . implode(', ', $closure) . "]\n";
      }
      $this->assertNotFalse(file_put_contents($fixture, $contents));
    }
    $this->assertSame(Yaml::decode(file_get_contents($fixture)), $map);
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
        $matches = array_intersect(self::LIBRARIES, $closure);
        foreach ($closure as $dependency) {
          foreach ($libraries[$dependency]['js'] ?? [] as $asset) {
            if (isset(self::WIDGETS[$asset['data']])) {
              $matches[] = self::WIDGETS[$asset['data']];
            }
          }
        }
        $matches = array_values(array_unique($matches));
        sort($matches, SORT_STRING);
        $map["$theme/$library"] = $matches;
      }
    }
    ksort($map, SORT_STRING);
    return $map;
  }

}
