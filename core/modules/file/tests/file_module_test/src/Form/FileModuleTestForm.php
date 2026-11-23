<?php

declare(strict_types=1);

namespace Drupal\file_module_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Element\ManagedFile;

/**
 * Form controller for file_module_test module.
 *
 * @internal
 */
class FileModuleTestForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'file_module_test_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param bool $tree
   *   (optional) If the form should use #tree. Defaults to TRUE.
   * @param bool $extended
   *   (optional) If the form should use #extended. Defaults to TRUE.
   * @param bool $multiple
   *   (optional) If the form should use #multiple. Defaults to FALSE.
   * @param string $default_fids
   *   (optional) Any default file IDs to use.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $tree = TRUE, $extended = TRUE, $multiple = FALSE, $default_fids = NULL) {
    $form['#tree'] = (bool) $tree;

    $form['nested']['file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Managed <em>@type</em>', ['@type' => 'file & butter']),
      '#description' => $this->t('Upload a <em>@type</em> file', ['@type' => 'file & butter']),
      '#upload_location' => 'public://test',
      '#progress_message' => $this->t('Processing...'),
      '#extended' => (bool) $extended,
      '#size' => 13,
      '#multiple' => (bool) $multiple,
    ];
    if ($this->getRequest()->query->getBoolean('upload_progress')) {
      $form['nested']['file']['#progress_indicator'] = 'bar';
      if (!extension_loaded('uploadprogress')) {
        $form['nested']['file']['#process'] = [
          [ManagedFile::class, 'processManagedFile'],
          [static::class, 'useFileUploadEvent'],
        ];
      }
    }
    if ($default_fids) {
      $default_fids = explode(',', $default_fids);
      $form['nested']['file']['#default_value'] = $extended ? ['fids' => $default_fids] : $default_fids;
    }

    $form['textfield'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Type a value and ensure it stays'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * Exercises the upload event on servers without the UploadProgress extension.
   *
   * @param array $element
   *   The processed managed file element.
   *
   * @return array
   *   The element with the custom Ajax event.
   */
  public static function useFileUploadEvent(array $element): array {
    $element['upload_button']['#ajax']['event'] = 'fileUpload';
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($form['#tree']) {
      $uploads = $form_state->getValue(['nested', 'file']);
    }
    else {
      $uploads = $form_state->getValue('file');
    }

    if ($form['nested']['file']['#extended']) {
      $uploads = $uploads['fids'];
    }

    $fids = [];
    foreach ($uploads as $fid) {
      $fids[] = $fid;
    }

    \Drupal::messenger()->addStatus($this->t('The file ids are %fids.', ['%fids' => implode(',', $fids)]));
  }

}
