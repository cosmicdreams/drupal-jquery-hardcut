<?php

declare(strict_types=1);

namespace Drupal\jqf_extend\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Adds legacy dependencies to fixture libraries.
 */
final class ExtendHooks {

  /**
   * Implements hook_library_info_alter().
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'core') {
      $libraries['drupalSettings']['dependencies'][] = 'core/jquery.ui.dialog';
    }
  }

}
