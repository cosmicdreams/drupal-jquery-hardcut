<?php

declare(strict_types=1);

namespace Drupal\views_ui_test\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for views_ui_test.
 */
class ViewsUiTestHooks {

  /**
   * Implements hook_views_preview_info_alter().
   *
   * Add a row count row to the live preview area.
   */
  #[Hook('views_preview_info_alter')]
  public function viewsPreviewInfoAlter(&$rows, $view): void {
    $data = ['#markup' => 'Test row count'];
    $data['#attached']['library'][] = 'views_ui_test/views_ui_test.test';
    $rows['query'][] = [['data' => $data], count($view->result)];
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter().
   *
   * Make the EntityReference options widget required to enable testing of
   * ConfigExtraHandler form validation.
   *
   * @see \Drupal\views\Plugin\views\filter\EntityReference::buildExtraOptionsForm()
   * @see \Drupal\Tests\views_ui\FunctionalJavascript\Ajax\ConfigHandlerExtraFormTest::testExtraOptionsModalValidation()
   */
  #[Hook('form_views_ui_config_item_extra_form_alter')]
  public function formViewsUiConfigItemExtraFormAlter(&$form, FormStateInterface $form_state, $form_id) : void {
    if (!\Drupal::state()->get('views_ui_test.alter_views_ui_config_item_extra_form')) {
      return;
    }
    $form['options']['widget']['#required'] = TRUE;
    unset($form['options']['widget']['#default_value']);
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   *
   * Lets the dialog test attach an editor to a Views text area handler.
   */
  #[Hook('form_views_ui_config_item_form_alter')]
  public function formViewsUiConfigItemFormAlter(array &$form): void {
    if (isset($form['options']['content']) && \Drupal::state()->get('views_ui_test.enable_area_editor')) {
      unset($form['options']['content']['#editor']);
    }
  }

}
