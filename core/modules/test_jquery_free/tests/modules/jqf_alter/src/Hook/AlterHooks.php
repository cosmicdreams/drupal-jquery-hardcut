<?php

declare(strict_types=1);

namespace Drupal\jqf_alter\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Theme\ThemeManager;

/**
 * Adds legacy dependencies to fixture libraries.
 */
final class AlterHooks {

  /**
   * Implements hook_library_info_alter().
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'core') {
      // Exercise discovery and template defaults during a canary scan.
      $theme_manager = \Drupal::theme();
      if ($theme_manager instanceof ThemeManager && $theme_manager->hasActiveTheme()) {
        \Drupal::service('library.discovery')->getLibrariesByExtension('jqf_script');
        $theme_manager->getDefaultTemplateVariables();
      }
      if (\Drupal::state()->get('jqf_alter.invalid_header', FALSE)) {
        $libraries['drupalSettings']['header'] = 'invalid';
      }
      $libraries['once']['dependencies'][] = 'core/jquery';
      $libraries['drupalSettings']['dependencies'][] = 'core/jquery';
      $libraries['drupalSettings']['dependencies'][] = 42;
      if ($field = \Drupal::state()->get('jqf_alter.remove_field')) {
        $library = match ($field) {
          'js' => 'htmx',
          'css' => 'drupal.fieldgroup',
          'drupalSettings' => 'drupalSettings',
          'license' => 'loadjs',
        };
        // Make drupalSettings a settings-only definition for this case.
        if ($field === 'drupalSettings') {
          unset($libraries[$library]['js'], $libraries[$library]['dependencies']);
        }
        unset($libraries[$library][$field]);
      }
    }
    if ($extension === 'jqf_clean') {
      $libraries['recipient']['dependencies'][] = 'core/jquery';
      $libraries['recipient']['dependencies'][] = 42;
    }
  }

}
