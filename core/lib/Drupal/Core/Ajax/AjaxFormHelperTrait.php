<?php

namespace Drupal\Core\Ajax;

use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a helper to for submitting an AJAX form.
 *
 * @internal
 */
trait AjaxFormHelperTrait {

  use AjaxHelperTrait;

  /**
   * Submit form dialog #ajax callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response that display validation error messages or represents a
   *   successful submission.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    if ($form_state->hasAnyErrors()) {
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -1000,
      ];
      $form['#sorted'] = FALSE;
      $response = new AjaxResponse();
      // The submitted build ID identifies one rendered copy of the form. If
      // the response updates that ID, the update command runs before this one.
      $build_id = isset($form['#build_id_old'])
        ? $form['#build_id']
        : ($form_state->getUserInput()['form_build_id'] ?? $form['#build_id']);
      if (is_string($build_id) && preg_match('/^[a-zA-Z0-9_-]+$/D', $build_id)) {
        $response->addCommand(new ReplaceCommand('form:has(input[name="form_build_id"][value="' . $build_id . '"])', $form));
      }
    }
    else {
      $response = $this->successfulAjaxSubmit($form, $form_state);
    }
    return $response;
  }

  /**
   * Allows the form to respond to a successful AJAX submission.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response.
   */
  abstract protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state);

}
