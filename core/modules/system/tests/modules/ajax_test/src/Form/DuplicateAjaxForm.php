<?php

declare(strict_types=1);

namespace Drupal\ajax_test\Form;

use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Tests replacing one copy of an Ajax form.
 *
 * @internal
 */
final class DuplicateAjaxForm extends FormBase {

  use AjaxFormHelperTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ajax_test_duplicate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update copy'),
      '#ajax' => ['callback' => '::ajaxSubmit'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (trim((string) $form_state->getValue('name')) === '') {
      $form_state->setErrorByName('name', $this->t('Enter a name.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('form:has(input[name="form_build_id"][value="' . $form['#build_id'] . '"])', $form));
    return $response;
  }

}
