<?php

declare(strict_types=1);

namespace Drupal\form_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\form_test\FormTestObject;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller routines for form_test routes.
 */
class FormTestController extends ControllerBase {

  /**
   * Returns two instances of the node form.
   *
   * @return string
   *   A HTML-formatted string with the double node form page content.
   */
  public function twoFormInstances() {
    $user = $this->currentUser();
    $values = [
      'uid' => $user->id(),
      'name' => $user->getAccountName(),
      'type' => 'page',
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ];
    $node1 = $this->entityTypeManager()->getStorage('node')->create($values);
    $node2 = clone($node1);
    $return['node_form_1'] = $this->entityFormBuilder()->getForm($node1);
    $return['node_form_2'] = $this->entityFormBuilder()->getForm($node2);
    return $return;
  }

  /**
   * Emulate legacy AHAH-style ajax callback.
   *
   * Drupal 6 AHAH callbacks used to operate directly on forms retrieved using
   * \Drupal::formBuilder()->getCache() and stored using
   * \Drupal::formBuilder()->setCache() after manipulation. This callback helps
   * testing whether \Drupal::formBuilder()->setCache() prevents resaving of
   * immutable forms.
   */
  public function storageLegacyHandler($form_build_id) {
    $form_state = new FormState();
    $form = $this->formBuilder()->getCache($form_build_id, $form_state);
    $result = [
      'form' => $form,
      'form_state' => $form_state,
    ];
    $form['#poisoned'] = TRUE;
    $form_state->set('poisoned', TRUE);
    $this->formBuilder()->setCache($form_build_id, $form, $form_state);
    return new JsonResponse($result);
  }

  /**
   * Returns a form and a button that has the form attribute.
   *
   * @return array
   *   A render array containing the form and the button.
   */
  public function buttonWithFormAttribute(): array {
    $return['form'] = $this->formBuilder()->getForm(FormTestObject::class);
    $return['button'] = [
      '#type' => 'submit',
      '#value' => 'Attribute Button',
      '#attributes' => [
        'form' => 'form-test-form-test-object',
      ],
    ];
    return $return;
  }

  /**
   * Renders a dropbutton with a primary and secondary action.
   */
  public function dropbutton(Request $request): array {
    $build = [
      'actions' => [
        '#type' => 'dropbutton',
        '#links' => [
          'primary' => ['title' => 'Primary action', 'url' => Url::fromRoute('<front>')],
          'secondary' => ['title' => 'Secondary action', 'url' => Url::fromRoute('<front>')],
        ],
      ],
      '#cache' => ['contexts' => ['url.query_args:legacy']],
    ];
    $this->attachLegacyJquery($build, $request);
    return $build;
  }

  /**
   * Renders the markup needed by locale and text form behaviors.
   */
  public function adminBehaviors(Request $request): array {
    $build = [
      '#type' => 'inline_template',
      '#template' => <<<'HTML'
        <form id="locale-translate-edit-form">
          <table><tbody>
            <tr><td><div class="js-form-item"><input name="first-translation"></div></td></tr>
            <tr><td><div class="js-form-item"><input name="second-translation"></div></td></tr>
          </tbody></table>
        </form>
        <form id="locale-translation-status-form">
          <table><tbody><tr>
            <td><a href="#updates" class="description">
              <span class="locale-translation-update__prefix">Show description</span>
            </a><div id="updates" class="requirements">Translation details</div></td>
          </tr></tbody></table>
        </form>
        <form id="locale-translate-import-form">
          <input type="file" class="file-import-input">
          <select class="langcode-input"><option value="en">English</option><option value="fr">French</option></select>
        </form>
        <fieldset id="translation-columns">
          <input name="translation-columns[]" type="checkbox" value="body">
          <input name="translation-columns[]" type="checkbox" value="format">
        </fieldset>
        <div class="js-text-format-wrapper">
          <div class="js-text-summary-wrapper">
            <label for="empty-summary">Summary</label>
            <textarea id="empty-summary" class="js-text-summary"></textarea>
          </div>
          <div class="js-form-type-textarea">
            <label for="full-text" class="visually-hidden">Full text</label>
            <textarea id="full-text"></textarea>
          </div>
        </div>
        <div class="js-text-format-wrapper">
          <div class="js-text-summary-wrapper">
            <label for="existing-summary">Summary</label>
            <textarea id="existing-summary" class="js-text-summary">Existing summary</textarea>
          </div>
          <div class="js-form-type-textarea"><textarea></textarea></div>
        </div>
        <div id="copy-field-fixture">
          <input id="copy-source-field" type="text">
          <input id="copy-target-field" type="text">
        </div>
        <div class="node-preview-container"></div>
        <a id="preview-leave-link" href="/preview-target">Leave preview</a>
        HTML,
      '#attached' => [
        'library' => [
          'form_test/admin_behaviors',
          'locale/drupal.locale.admin',
          'content_translation/drupal.content_translation.admin',
          'system/drupal.system',
        ],
        'drupalSettings' => [
          'contentTranslationDependentOptions' => [
            'dependent_selectors' => ['translation-columns' => ['body']],
          ],
          'copyFieldValue' => [
            'copy-source-field' => ['copy-target-field'],
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:legacy', 'url.query_args:preview'],
      ],
    ];
    if ($request->query->getBoolean('preview')) {
      $build['#attached']['library'][] = 'node/drupal.node.preview';
    }
    $this->attachLegacyJquery($build, $request);
    return $build;
  }

  /**
   * Attaches the explicit jQuery library when requested by query parameter.
   */
  private function attachLegacyJquery(array &$build, Request $request): void {
    if ($request->query->getBoolean('legacy')) {
      $build['#attached']['library'][] = 'core/jquery';
    }
  }

}
