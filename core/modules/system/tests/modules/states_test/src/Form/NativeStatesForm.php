<?php

declare(strict_types=1);

namespace Drupal\states_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a native CSS-selector copy of the Nightwatch States fixture.
 *
 * @internal
 */
final class NativeStatesForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'states_test_native_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'states_test/deprecation_spy';

    $form['select'] = [
      '#type' => 'select',
      '#title' => 'select 1',
      '#options' => [0 => 0, 1 => 1, 2 => 2],
    ];
    $form['number'] = [
      '#type' => 'number',
      '#title' => 'enter 1',
    ];
    $form['textfield'] = [
      '#type' => 'textfield',
      '#title' => 'textfield',
      '#states' => [
        'visible' => [
          ['select[name="select"]' => ['value' => '1']],
          'or',
          ['input[name="number"]' => ['value' => '1']],
        ],
      ],
    ];

    $form['number_trigger'] = [
      '#type' => 'number',
      '#title' => 'Number trigger',
    ];
    $form['item_visible_when_number_trigger_filled_by_spinner'] = [
      '#type' => 'item',
      '#title' => 'Item visible when number trigger filled by spinner widget',
      '#states' => [
        'visible' => [
          'input[name="number_trigger"]' => ['filled' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
