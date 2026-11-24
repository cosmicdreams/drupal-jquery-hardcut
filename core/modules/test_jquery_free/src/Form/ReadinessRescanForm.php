<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\test_jquery_free\ReadinessScanner;

/**
 * Refreshes the saved jQuery readiness report.
 */
final class ReadinessRescanForm extends FormBase {

  public function __construct(
    protected ReadinessScanner $scanner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'test_jquery_free_rescan';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rescan'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->scanner->scan(rescan: TRUE);
    $form_state->setRedirect('test_jquery_free.report');
  }

}
