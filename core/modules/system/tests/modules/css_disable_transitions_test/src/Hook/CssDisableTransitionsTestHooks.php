<?php

declare(strict_types=1);

namespace Drupal\css_disable_transitions_test\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for css_disable_transitions_test.
 */
class CssDisableTransitionsTestHooks {

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    // Unconditionally attach an asset to the page.
    $attachments['#attached']['library'][] = 'css_disable_transitions_test/testing.css_disable_transitions_test';
  }

  /**
   * Implements hook_library_info_alter().
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    // The ordinary fixture uses CSS. Disable effects for explicit legacy
    // consumers after their distribution has loaded.
    if ($extension === 'core' && !empty($libraries['jquery']['js'])) {
      $path = '/core/modules/system/tests/modules/css_disable_transitions_test/js/disable_transitions.theme.js';
      $libraries['jquery']['js'][$path] = ['preprocess' => FALSE];
    }
  }

}
