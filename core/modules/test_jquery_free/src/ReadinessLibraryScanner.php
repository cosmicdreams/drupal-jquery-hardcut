<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Traces jQuery dependencies in the library graph for each installed theme.
 */
final class ReadinessLibraryScanner {

  // Core library IDs from 447e9c9df2f, Drupal 8–11 history, and the hard cut.
  private const JQUERY_LIBRARIES = [
    'drupal.ajax.jquery',
    'drupal.jquery.position',
    'drupal.states.jquery',
    'internal.jquery.form',
    'internal.jquery_ui',
    'jquery',
    'jquery.cookie',
    'jquery.farbtastic',
    'jquery.form',
    'jquery.intrinsic',
    'jquery.joyride',
    'jquery.once',
    'jquery.once.bc',
    'jquery.ui',
    'jquery.ui.accordion',
    'jquery.ui.autocomplete',
    'jquery.ui.button',
    'jquery.ui.checkboxradio',
    'jquery.ui.controlgroup',
    'jquery.ui.datepicker',
    'jquery.ui.dialog',
    'jquery.ui.draggable',
    'jquery.ui.droppable',
    'jquery.ui.effects.blind',
    'jquery.ui.effects.bounce',
    'jquery.ui.effects.clip',
    'jquery.ui.effects.core',
    'jquery.ui.effects.drop',
    'jquery.ui.effects.explode',
    'jquery.ui.effects.fade',
    'jquery.ui.effects.fold',
    'jquery.ui.effects.highlight',
    'jquery.ui.effects.puff',
    'jquery.ui.effects.pulsate',
    'jquery.ui.effects.scale',
    'jquery.ui.effects.shake',
    'jquery.ui.effects.size',
    'jquery.ui.effects.slide',
    'jquery.ui.effects.transfer',
    'jquery.ui.menu',
    'jquery.ui.mouse',
    'jquery.ui.position',
    'jquery.ui.progressbar',
    'jquery.ui.resizable',
    'jquery.ui.selectable',
    'jquery.ui.selectmenu',
    'jquery.ui.slider',
    'jquery.ui.sortable',
    'jquery.ui.spinner',
    'jquery.ui.tabs',
    'jquery.ui.tooltip',
    'jquery.ui.touch-punch',
    'jquery.ui.widget',
    'tabbable.jquery.shim',
  ];

  public function __construct(
    private readonly ReadinessLibraryParser $parser,
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeInitializationInterface $themeInitialization,
  ) {}

  /**
   * Returns library findings, labeled by theme when they differ.
   *
   * @param array<string, \Drupal\Core\Extension\Extension> $extensions
   *   All installed modules and themes.
   * @param list<string> $themes
   *   Installed theme machine names.
   *
   * @return array<string, list<array{source: string, line: null, theme: ?string, reason: string}>>
   *   Findings grouped by extension machine name.
   */
  public function scan(array $extensions, array $themes): array {
    $external = array_filter($extensions, static fn (Extension $extension): bool => !str_starts_with($extension->getPath() . '/', 'core/'));
    $original = $this->themeManager->hasActiveTheme() ? $this->themeManager->getActiveTheme() : NULL;
    $themes = $themes ?: ['core'];
    $scans = [];
    try {
      foreach ($themes as $name) {
        $rows = [];
        try {
          $theme = $this->themeInitialization->getActiveThemeByName($name);
          $this->themeManager->setActiveTheme($theme);
          $rows = $this->scanTheme($theme, $extensions, $external);
        }
        catch (\Throwable $e) {
          $rows[] = [$name, $name, 'library definition error: ' . $e->getMessage()];
        }
        foreach ($rows as [$extension, $source, $reason]) {
          if (isset($external[$extension])) {
            $key = json_encode([$extension, $source, $reason], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $scans[$key][$name] = TRUE;
          }
        }
      }
    }
    finally {
      if ($original !== NULL) {
        $this->themeManager->setActiveTheme($original);
      }
      else {
        $this->themeManager->resetActiveTheme();
      }
    }

    $findings = [];
    foreach ($scans as $key => $affected) {
      [$extension, $source, $reason] = json_decode($key, TRUE, flags: JSON_THROW_ON_ERROR);
      $findings[$extension][] = [
        'source' => $source,
        'line' => NULL,
        'theme' => count($affected) === count($themes) ? NULL : implode(', ', array_keys($affected)),
        'reason' => $reason,
      ];
    }
    return $findings;
  }

  /**
   * Discovers dependencies and declaration owners for a single theme.
   */
  private function scanTheme(ActiveTheme $theme, array $extensions, array $external): array {
    $theme_chain = array_reverse($theme->getBaseThemeExtensions(), TRUE) + [$theme->getName() => $theme->getExtension()];
    $extensions_by_path = [];
    foreach ($theme_chain as $name => $extension) {
      $extensions_by_path[$extension->getPath()] = $name;
    }

    $rows = [];
    $graph = [];
    $replacements = [];
    foreach (['core', ...array_keys($extensions)] as $name) {
      $override_owners = [];
      foreach ($theme->getLibrariesOverride() as $path => $overrides) {
        foreach ($overrides as $id => $override) {
          if (str_starts_with($id, "$name/")) {
            $override_owners[$id] = $extensions_by_path[$path];
          }
        }
      }
      try {
        $libraries = $this->parser->buildByExtension($name);
      }
      catch (\Throwable $e) {
        $message = $e->getMessage();
        $author = $this->parser->getFailedAlterAuthor();
        $source = $name;
        if ($author === NULL) {
          foreach ($override_owners as $id => $theme_author) {
            if (str_contains($message, $id)) {
              $author = $theme_author;
              $source = $id;
              break;
            }
          }
        }
        $rows[] = [$author ?? $name, $source, 'library definition error: ' . $message];
        continue;
      }
      foreach ($libraries as $library => $definition) {
        $id = "$name/$library";
        if (($definition['override'] ?? NULL) === FALSE) {
          continue;
        }
        $graph[$id] = [];
        if (isset($definition['override'])) {
          $author = $override_owners[$id] ?? $name;
          if (is_string($definition['override'])) {
            $replacements[$id] = [$definition['override'], $author];
          }
          else {
            $rows[] = [$author, $id, 'library definition error: Override must be a library ID or false.'];
          }
          continue;
        }
        $dependencies = $definition['dependencies'];
        if (!is_array($dependencies) || count(array_filter($dependencies, static fn (mixed $dependency): bool => is_string($dependency) && str_contains($dependency, '/'))) !== count($dependencies)) {
          $rows[] = [$name, $id, 'library definition error: dependencies must be a list of library IDs.'];
          continue;
        }
        foreach ($dependencies as $dependency) {
          $graph[$id][$dependency][$this->parser->getDependencyAuthor($library, $dependency, $name)] = TRUE;
        }
      }
    }

    // Drupal's collector folds whole-library replacements and extends into
    // the definition. Keep them as edges so the report retains the chain.
    foreach ($replacements as $id => [$target, $author]) {
      if (!isset($graph[$target])) {
        $rows[] = [$author, $id, "library definition error: The specified library $target does not exist."];
        if (!self::isJqueryLibrary($target)) {
          continue;
        }
      }
      $graph[$id][$target][$author] = TRUE;
    }
    foreach ($theme_chain as $author => $extension) {
      foreach ($extension->info['libraries-extend'] ?? [] as $id => $targets) {
        if (!isset($graph[$id]) || isset($replacements[$id])) {
          continue;
        }
        if (!is_array($targets) || !array_is_list($targets)) {
          $rows[] = [$author, $id, 'library definition error: libraries-extend must be a list of library IDs.'];
          continue;
        }
        foreach ($targets as $target) {
          if (!is_string($target) || !isset($graph[$target])) {
            $label = is_string($target) ? $target : get_debug_type($target);
            $rows[] = [$author, $id, "library definition error: Invalid library extension $label."];
            if (!is_string($target) || !self::isJqueryLibrary($target)) {
              continue;
            }
          }
          $graph[$id][$target][$author] = TRUE;
        }
      }
      if (isset($external[$author])) {
        foreach ($extension->info['libraries'] ?? [] as $id) {
          if (!is_string($id)) {
            $rows[] = [$author, $author, 'library definition error: Global libraries must be library IDs.'];
            continue;
          }
          foreach ($this->chains($graph, [], [$id]) as $chain) {
            $rows[] = [$author, $id, 'Theme attaches ' . implode(' → ', $chain)];
          }
        }
      }
    }

    foreach ($graph as $id => $dependencies) {
      $by_author = [];
      foreach ($dependencies as $dependency => $authors) {
        foreach (array_intersect_key($authors, $external) as $author => $present) {
          $by_author[$author][] = $dependency;
        }
      }
      foreach ($by_author as $author => $targets) {
        foreach ($this->chains($graph, [$id], $targets) as $chain) {
          $rows[] = [$author, $id, 'Library depends on ' . implode(' → ', $chain)];
        }
      }
    }
    return $rows;
  }

  /**
   * Finds a shortest chain to each reachable historical library ID.
   */
  private function chains(array $graph, array $prefix, array $targets): iterable {
    $seen = array_fill_keys($prefix, TRUE);
    $queue = new \SplQueue();
    foreach ($targets as $target) {
      $queue->enqueue([...$prefix, $target]);
    }
    while (!$queue->isEmpty()) {
      $chain = $queue->dequeue();
      $target = end($chain);
      if (isset($seen[$target])) {
        continue;
      }
      $seen[$target] = TRUE;
      if (self::isJqueryLibrary($target)) {
        yield $chain;
      }
      foreach (array_keys($graph[$target] ?? []) as $dependency) {
        $queue->enqueue([...$chain, $dependency]);
      }
    }
  }

  /**
   * Recognizes historical and similarly named core libraries.
   */
  private static function isJqueryLibrary(string $library): bool {
    if (!str_starts_with($library, 'core/')) {
      return FALSE;
    }
    $id = substr($library, 5);
    return in_array($id, self::JQUERY_LIBRARIES, TRUE) || preg_match('~^(?:internal\.)?jquery(?:[._][\w.-]+)?$~D', $id) === 1;
  }

}
