<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\test_jquery_free\ReadinessScanner;

// cspell:ignore rescan

/**
 * Refreshes the saved jQuery readiness report.
 */
final class ReadinessRescanForm implements FormInterface, ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly ReadinessScanner $scanner,
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
      '#value' => new TranslatableMarkup('Rescan'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->scanner->scan(rescan: TRUE);
    $form_state->setRedirect('test_jquery_free.report');
  }

  /**
   * Keeps the scanner service out of the form cache.
   */
  public function __serialize(): array {
    return [];
  }

  /**
   * Restores the scanner when the form is read from the cache.
   */
  public function __unserialize(array $data): void {
    $this->scanner = \Drupal::service(ReadinessScanner::class);
  }

}
