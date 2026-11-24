<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Asset\Exception\IncompleteLibraryDefinitionException;
use Drupal\Core\Asset\Exception\InvalidLibraryFileException;
use Drupal\Core\Asset\Exception\LibraryDefinitionMissingLicenseException;
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
   * The authors of altered fields that library normalization validates.
   *
   * @var array<string, array<string, string>>
   */
  private array $definitionAuthors = [];

  /**
   * Malformed dependencies and their authors for the current extension.
   *
   * @var array<string, array<int|string, array{array{bool, mixed}, string}>>
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
   * Returns the declaring extension for a library, including components.
   */
  public static function libraryProvider(string $extension, string $library): string {
    if ($extension === 'core' && preg_match('/^components\.([a-z0-9_]+)--/', $library, $matches)) {
      return $matches[1];
    }
    return $extension;
  }

  /**
   * Identifies the library and author when normalization names the library.
   *
   * @return array{string, string}|null
   *   The library ID and author, when known.
   */
  public function getNormalizationFailure(string $extension, \Throwable $error): ?array {
    // Core names the library in its explicit validation exceptions.
    [$field, $pattern] = match (TRUE) {
      $error instanceof IncompleteLibraryDefinitionException => [
        'asset_or_dependencies',
        "/^Incomplete library definition for definition '([^']+)'/",
      ],
      $error instanceof LibraryDefinitionMissingLicenseException => [
        'required_license',
        "/^Missing license information in library definition for definition '([^']+)'/",
      ],
      $error instanceof \LogicException => ['header', "/^The 'header' key in the library definition '([^']+)'/"],
      $error instanceof \UnexpectedValueException => [
        'js',
        '~^The ' . preg_quote($extension, '~') . '/(\S+) library defines a positive weight~',
      ],
      default => [NULL, NULL],
    };
    if ($pattern === NULL || !preg_match($pattern, $error->getMessage(), $matches)) {
      return NULL;
    }

    $library = $matches[1];
    $author = $this->definitionAuthors[$library][$field] ?? self::libraryProvider($extension, $library);
    return ["$extension/$library", $author];
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
    $this->definitionAuthors = [];
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
      $before = $libraries;
      $this->alteringExtension = $author;
      $listener($libraries, $extension, $context2);
      $this->alteringExtension = NULL;
      $this->recordDefinitionAuthors($before, $libraries, $author);
      $before = $this->dependencies($before);
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
   * Records changes to fields checked by core library normalization.
   */
  private function recordDefinitionAuthors(array $before, array $after, string $author): void {
    $this->definitionAuthors = array_intersect_key($this->definitionAuthors, $after);
    foreach ($after as $library => $definition) {
      $previous = $before[$library] ?? [];
      // Credit the hook that makes a previously valid definition invalid.
      $failures = $this->definitionFailures($definition);
      foreach ($this->definitionFailures($previous) as $check => $was_invalid) {
        if (!$failures[$check]) {
          unset($this->definitionAuthors[$library][$check]);
        }
        elseif (!$was_invalid || !isset($before[$library])) {
          $this->definitionAuthors[$library][$check] = $author;
        }
      }
      foreach (['header', 'js'] as $field) {
        if (!isset($before[$library]) || ($definition[$field] ?? NULL) !== ($before[$library][$field] ?? NULL)) {
          $this->definitionAuthors[$library][$field] = $author;
        }
      }
    }
  }

  /**
   * Identifies normalization failures that depend on multiple fields.
   */
  private function definitionFailures(mixed $definition): array {
    return [
      'asset_or_dependencies' => !isset($definition['js']) && !isset($definition['css']) && !isset($definition['drupalSettings']) && !isset($definition['dependencies']),
      'required_license' => isset($definition['remote']) && !isset($definition['license']),
    ];
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
        $entry_author = $prior_key === FALSE ? self::libraryProvider($author, $id) : $this->malformed[$id][$prior_key][1];
        $malformed[$id][$key] = [$entry, $entry_author];
        if ($prior_key !== FALSE) {
          unset($previous[$prior_key]);
        }
      }
    }
    $this->malformed = $malformed;
  }

}
