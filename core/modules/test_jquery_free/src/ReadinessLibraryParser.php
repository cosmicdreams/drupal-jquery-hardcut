<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Asset\Exception\InvalidLibraryFileException;
use Drupal\Core\Asset\LibraryDiscoveryParser;

/**
 * Records who adds dependencies while Drupal parses a library definition.
 */
final class ReadinessLibraryParser extends LibraryDiscoveryParser {

  /**
   * The authors of altered dependency edges for the current extension.
   *
   * @var array<string, array<string, string>>
   */
  private array $authors = [];

  /**
   * The extension whose alter callback is running or threw an exception.
   */
  private ?string $alteringExtension = NULL;

  /**
   * Returns the author of a dependency in the last parsed extension.
   */
  public function getDependencyAuthor(string $library, string $dependency, string $extension): string {
    return $this->authors[$library][$dependency] ?? $extension;
  }

  /**
   * Returns the author when an alter callback threw during discovery.
   */
  public function getFailedAlterAuthor(): ?string {
    return $this->alteringExtension;
  }

  /**
   * {@inheritdoc}
   */
  protected function parseLibraryInfo($extension, $path) {
    $this->authors = [];
    $this->alteringExtension = NULL;
    // Match the core parser's initial definitions, then observe each alter
    // callback in Drupal's module and theme hook order.
    $file = "$path/$extension.libraries.yml";
    try {
      $libraries = $this->yamlCacheCollector->get($this->appRoot . '/' . $file);
    }
    catch (InvalidDataTypeException $e) {
      throw new InvalidLibraryFileException("Invalid library definition in $file: " . $e->getMessage(), 0, $e);
    }
    if ($extension === 'core') {
      $libraries = array_merge($this->librariesForComponents(), $libraries);
    }
    if ($this->moduleHandler->hasImplementations('library_info_build', $extension)) {
      $libraries = NestedArray::mergeDeep($libraries, $this->moduleHandler->invoke($extension, 'library_info_build'));
    }

    $context2 = NULL;
    $alter = function (callable $listener, string $author) use (&$libraries, &$extension, &$context2): void {
      $before = $this->dependencies($libraries);
      $this->alteringExtension = $author;
      $listener($libraries, $extension, $context2);
      $this->alteringExtension = NULL;
      $after = $this->dependencies($libraries);
      foreach ($this->authors as $id => $authors) {
        $this->authors[$id] = array_intersect_key($authors, $after[$id] ?? []);
      }
      foreach ($after as $id => $dependencies) {
        foreach (array_diff_key($dependencies, $before[$id] ?? []) as $dependency => $present) {
          $this->authors[$id][$dependency] = $author;
        }
      }
    };
    $this->moduleHandler->invokeAllWith('library_info_alter', $alter);
    $context2 = NULL;
    $this->themeManager->invokeAllWith('library_info_alter', $alter);

    return $libraries;
  }

  /**
   * Indexes dependency edges before or after a hook.
   */
  private function dependencies(array $libraries): array {
    $dependencies = [];
    foreach ($libraries as $id => $definition) {
      foreach ((array) ($definition['dependencies'] ?? []) as $dependency) {
        if (is_string($dependency)) {
          $dependencies[$id][$dependency] = TRUE;
        }
      }
    }
    return $dependencies;
  }

}
