<?php

declare(strict_types=1);

namespace Drupal\tabledrag_test\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Provides test-only table library alterations.
 */
final class TableDragTestHooks {

  /**
   * Implements hook_library_info_alter().
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'core' && \Drupal::state()->get('tabledrag_test.late_bridge')) {
      unset($libraries['jquery']['js']['misc/jquery.tabledrag.bc.js']);
    }
  }

}
