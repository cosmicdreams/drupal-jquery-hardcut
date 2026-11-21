<?php

declare(strict_types=1);

namespace Drupal\jquery_bc_test\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a form with an Ajax select and a dependent field.
 *
 * @internal
 */
final class CompatibilityEventsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'jquery_bc_test_events';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'jquery_bc_test/explicit_jquery';
    $form['#attached']['library'][] = 'core/drupal.ajax';
    $form['choice'] = [
      '#type' => 'select',
      '#title' => $this->t('Choice'),
      '#options' => ['hide' => $this->t('Hide'), 'show' => $this->t('Show')],
      '#default_value' => 'hide',
      '#ajax' => [
        'callback' => '::selectResponse',
        'wrapper' => 'jquery-bc-test-result',
      ],
    ];
    $form['dependent'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dependent field'),
      '#states' => [
        'visible' => [':input[name="choice"]' => ['value' => 'show']],
      ],
    ];
    $form['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Compatibility Ajax link'),
      '#url' => Url::fromRoute('jquery_bc_test.link_response'),
      '#attributes' => ['class' => ['use-ajax']],
    ];
    $form['result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'jquery-bc-test-result'],
      'text' => ['#plain_text' => (string) $form_state->getValue('choice', 'hide')],
    ];
    return $form;
  }

  /**
   * Returns the updated select result.
   */
  public function selectResponse(array $form): array {
    return $form['result'];
  }

  /**
   * Updates the result without navigating away from the test form.
   */
  public function linkResponse(): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#jquery-bc-test-result', [
      '#plain_text' => $this->t('Link response'),
    ]));
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
