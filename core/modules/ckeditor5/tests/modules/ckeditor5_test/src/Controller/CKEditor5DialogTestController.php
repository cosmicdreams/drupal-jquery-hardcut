<?php

declare(strict_types=1);

namespace Drupal\ckeditor5_test\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;

/**
 * Provides controller for testing CKEditor in modal and non-modal dialogs.
 */
class CKEditor5DialogTestController {

  /**
   * Builds links to the node form in modal and non-modal dialogs.
   *
   * @return array
   *   A render array.
   */
  public function testDialog() {
    foreach (['Add Node' => TRUE, 'Add Node Without Modal' => FALSE] as $title => $modal) {
      $build[] = [
        '#type' => 'link',
        '#title' => $title,
        '#url' => Url::fromRoute('entity.node.add_form', ['node_type' => 'page']),
        '#attributes' => [
          'class' => ['use-ajax'],
          'data-dialog-type' => $modal ? 'modal' : 'dialog',
          'data-dialog-options' => Json::encode([
            'width' => 700,
            'autoResize' => TRUE,
          ]),
        ],
      ];
    }
    $build['#attached']['library'][] = 'core/drupal.dialog.ajax';
    return $build;
  }

}
