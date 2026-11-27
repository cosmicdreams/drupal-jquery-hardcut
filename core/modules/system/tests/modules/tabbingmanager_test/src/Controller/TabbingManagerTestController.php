<?php

declare(strict_types=1);

namespace Drupal\tabbingmanager_test\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * For testing the Tabbing Manager.
 */
class TabbingManagerTestController extends ControllerBase {

  /**
   * Provides a page with the tabbingManager library for testing tabbing manager.
   *
   * @return array
   *   The render array.
   */
  public function build(): array {
    $libraries = ['core/drupal.tabbingmanager'];
    return [
      'container' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'tabbingmanager-test-container',
        ],
        'first' => [
          '#type' => 'textfield',
          '#title' => $this->t('First'),
          '#id' => 'first',
        ],
        'second' => [
          '#type' => 'textfield',
          '#title' => $this->t('Second'),
          '#id' => 'second',
        ],
        'third' => [
          '#type' => 'textfield',
          '#title' => $this->t('Third'),
          '#id' => 'third',
        ],
      ],
      'another_container' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'tabbingmanager-test-another-container',
        ],
        'fourth' => [
          '#type' => 'textfield',
          '#title' => $this->t('Fourth'),
          '#id' => 'fourth',
        ],
        'fifth' => [
          '#type' => 'textfield',
          '#title' => $this->t('Fifth'),
          '#id' => 'fifth',
        ],
        'sixth' => [
          '#type' => 'textfield',
          '#title' => $this->t('Sixth'),
          '#id' => 'sixth',
        ],
      ],
      '#attached' => ['library' => $libraries],
    ];
  }

}
