<?php

declare(strict_types=1);

namespace Drupal\jqf_alter\Hook;

use Drupal\Core\Hook\Attribute\Hook;

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
      $libraries['once']['dependencies'][] = 'core/jquery';
    }
    if ($extension === 'jqf_clean') {
      $libraries['recipient']['dependencies'][] = 'core/jquery';
    }
  }

}
