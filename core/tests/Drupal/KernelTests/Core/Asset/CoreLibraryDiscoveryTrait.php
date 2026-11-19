<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ExtensionDiscovery;

/**
 * Discovers core libraries under each core theme.
 */
trait CoreLibraryDiscoveryTrait {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Discover profile-provided extensions as the installer does before a
    // profile has been selected.
    $container->setParameter('install_profile', NULL);
  }

  /**
   * Yields library definitions keyed by library ID for each active theme.
   *
   * @return \Generator<string, array>
   *   Resolved definitions, keyed by theme name.
   */
  protected function coreLibrariesByTheme(): \Generator {
    $discovery = new ExtensionDiscovery($this->root);
    // Include extensions shipped inside any core profile.
    $discovery->setProfileDirectories([]);
    // The scans exclude all test extensions, including Nightwatch fixtures.
    $is_core = static fn (Extension $extension): bool => str_starts_with($extension->getPath(), 'core/');
    $modules = array_filter($discovery->scan('module', FALSE), $is_core);
    $profiles = array_filter($discovery->scan('profile', FALSE), $is_core);
    $themes = array_filter($discovery->scan('theme', FALSE), $is_core);

    // Enable hooks and services without running installation hooks. Profiles
    // are enabled as modules so their libraries can be discovered as well.
    $this->enableModules(array_keys($modules + $profiles));

    $extensions = ['core'];
    foreach ($modules + $profiles + $themes as $name => $extension) {
      if (is_file("$this->root/{$extension->getPath()}/$name.libraries.yml")) {
        $extensions[] = $name;
      }
    }

    // The module extension list normally knows only the active profile.
    $module_list = $this->container->get('extension.list.module');
    foreach ($profiles as $name => $profile) {
      $module_list->setPathname($name, $profile->getPathname());
    }

    // Register the themes without running installation hooks.
    $storage = $this->container->get('config.storage');
    $extension_config = $storage->read('core.extension');
    $extension_config['theme'] = array_fill_keys(array_keys($themes), 0);
    $storage->write('core.extension', $extension_config);
    $this->container->get('config.factory')->reset('core.extension');
    $this->container->get('theme_handler')->reset();

    $theme_initialization = $this->container->get('theme.initialization');
    $theme_manager = $this->container->get('theme.manager');
    $library_discovery = $this->container->get('library.discovery');
    foreach (array_keys($themes) as $theme) {
      $active_theme = $theme_initialization->initTheme($theme);
      $this->assertSame($theme, $active_theme->getName());
      $theme_manager->setActiveTheme($active_theme);
      $library_discovery->clear();

      $libraries = [];
      foreach ($extensions as $extension) {
        foreach ($library_discovery->getLibrariesByExtension($extension) as $name => $definition) {
          $libraries["$extension/$name"] = $definition;
        }
      }
      yield $theme => $libraries;
    }
  }

  /**
   * Identifies paths outside the Nightwatch-free ratchets.
   */
  protected static function isNightwatchPath(string $path): bool {
    return preg_match('#^core/(?:tests/Drupal/Nightwatch/|(?:.*/)?tests/(?:src/Nightwatch/|(?:modules|profiles)/nightwatch_[^/]+/)|profiles/tests/nightwatch_[^/]+/)#', $path) === 1;
  }

}
