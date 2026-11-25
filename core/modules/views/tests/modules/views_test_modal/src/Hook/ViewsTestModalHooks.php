<?php

declare(strict_types=1);

namespace Drupal\views_test_modal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\views\ViewExecutable;

/**
 * Hooks for testing Views Ajax responses.
 */
class ViewsTestModalHooks {

  /**
   * Implements hook_views_pre_render().
   */
  #[Hook('views_pre_render')]
  public function viewsPreRender(ViewExecutable $view): void {
    if ($view->id() === 'content' && \Drupal::routeMatch()->getRouteName() === 'views.ajax' && \Drupal::state()->get('views_test_modal.attach_refresh_widget')) {
      $view->element['#attached']['library'][] = 'views_test_modal/refresh_widget';
    }
  }

}
