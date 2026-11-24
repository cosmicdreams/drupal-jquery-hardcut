<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Asset\Exception\InvalidLibraryFileException;
use Drupal\Core\Asset\LibraryDiscoveryParser;
use Drupal\Core\Theme\ActiveTheme;

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
   * Malformed dependencies and their authors for the current extension.
   */
  private array $malformed = [];

  /**
   * The extension whose alter callback is running or threw an exception.
   */
  private ?string $alteringExtension = NULL;

  /**
   * Creates a parser with a private theme manager for the given theme.
   */
  public function forTheme(ActiveTheme $theme): self {
    $parser = clone $this;
    $parser->themeManager = clone $this->themeManager;
    $parser->themeManager->setActiveTheme($theme);
    return $parser;
  }

  /**
   * Checks whether a value has the extension/library form of a library ID.
   */
  public static function isLibraryId(mixed $value): bool {
    return is_string($value) && preg_match('~^[^/\s]+/[^/\s]+$~D', $value) === 1;
  }

  /**
   * Returns the author of a dependency in the last parsed extension.
   */
  public function getDependencyAuthor(string $library, string $dependency, string $extension): string {
    return $this->authors[$library][$dependency] ?? $extension;
  }

  /**
   * Yields each malformed dependency and the extension that introduced it.
   */
  public function getDependencyErrors(string $library): iterable {
    foreach ($this->malformed[$library] ?? [] as $key => [$entry, $author]) {
      $reason = $entry[0]
        ? "Dependency at key $key must be a library ID."
        : 'Dependencies must be a list of library IDs.';
      yield [$author, $reason];
    }
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
    $this->malformed = [];
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

    $this->recordMalformedDependencies($libraries, $extension);
    $context2 = NULL;
    $alter = function (callable $listener, string $author) use (&$libraries, &$extension, &$context2): void {
      $before = $this->dependencies($libraries);
      $this->alteringExtension = $author;
      $listener($libraries, $extension, $context2);
      $this->alteringExtension = NULL;
      $after = $this->dependencies($libraries);
      $this->recordMalformedDependencies($libraries, $author);
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
      $entries = $definition['dependencies'] ?? [];
      foreach (is_array($entries) ? $entries : [] as $dependency) {
        if (self::isLibraryId($dependency)) {
          $dependencies[$id][$dependency] = TRUE;
        }
      }
    }
    return $dependencies;
  }

  /**
   * Preserves the author of each malformed entry across alter callbacks.
   */
  private function recordMalformedDependencies(array $libraries, string $author): void {
    $malformed = [];
    foreach ($libraries as $id => $definition) {
      if (!is_array($definition) || !array_key_exists('dependencies', $definition)) {
        continue;
      }
      $entries = $definition['dependencies'];
      $is_array = is_array($entries);
      // Keep the previous keys to look up the original author after a match.
      $previous = array_map(static fn (array $entry): array => $entry[0], $this->malformed[$id] ?? []);
      foreach ($is_array ? $entries : [$entries] as $key => $dependency) {
        if ($is_array && self::isLibraryId($dependency)) {
          continue;
        }
        $entry = [$is_array, $dependency];
        $prior_key = array_search($entry, $previous, TRUE);
        $malformed[$id][$key] = [$entry, $prior_key === FALSE ? $author : $this->malformed[$id][$prior_key][1]];
        if ($prior_key !== FALSE) {
          unset($previous[$prior_key]);
        }
      }
    }
    $this->malformed = $malformed;
  }

}
