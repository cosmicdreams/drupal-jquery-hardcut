<?php

declare(strict_types=1);

namespace Drupal\states_test\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Url;

/**
 * Provides States markup with separately attached legacy selectors.
 *
 * @internal
 */
final class StatesTestController {

  /**
   * Builds a States fixture without the general form libraries.
   */
  public function form(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'states-test-content'],
      '#theme_wrappers' => [
        'container',
        'form' => ['#attributes' => ['id' => 'states-test-form']],
      ],
      '#attached' => ['library' => ['core/drupal.ajax']],
      'first' => [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'name' => 'choice[1]',
          'type' => 'checkbox',
          'class' => ['states-test-choice'],
        ],
      ],
      'second' => [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'name' => 'choice[2]',
          'type' => 'checkbox',
          'class' => ['states-test-choice'],
          'data-states-test-pseudo' => 'yes',
        ],
      ],
      'dependent' => [
        '#type' => 'container',
        '#attributes' => ['id' => 'states-test-native', 'class' => ['js-form-wrapper']],
        '#states' => [
          'visible' => [
            ':is(input[name="choice[1]"], input[data-unused=":eq(0)"]):not(.unused)' => ['checked' => TRUE],
          ],
        ],
        'text' => ['#plain_text' => 'Native dependent'],
      ],
      'load' => [
        '#type' => 'link',
        '#title' => 'Load legacy States',
        '#url' => Url::fromRoute('states_test.legacy'),
        '#attributes' => ['class' => ['use-ajax']],
      ],
      'remove_dependent' => [
        '#type' => 'link',
        '#title' => 'Remove native dependent',
        '#url' => Url::fromRoute('states_test.remove_dependent'),
        '#attributes' => ['class' => ['use-ajax']],
      ],
      'replace_dependee' => [
        '#type' => 'link',
        '#title' => 'Replace first dependee',
        '#url' => Url::fromRoute('states_test.replace_dependee'),
        '#attributes' => ['class' => ['use-ajax']],
      ],
    ];
  }

  /**
   * Removes the native dependent element itself via Ajax.
   */
  public function removeDependent(): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new RemoveCommand('#states-test-native'));
    return $response;
  }

  /**
   * Replaces the first dependee checkbox while its dependent stays.
   */
  public function replaceDependee(): AjaxResponse {
    $replacement = [
      '#type' => 'html_tag',
      '#tag' => 'input',
      '#attributes' => [
        'name' => 'choice[1]',
        'type' => 'checkbox',
        'class' => ['states-test-choice'],
        'data-replaced' => 'yes',
      ],
    ];
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('input[name="choice[1]"]', $replacement));
    return $response;
  }

  /**
   * Adds legacy conditions to the existing form.
   */
  public function legacy(): AjaxResponse {
    $fragment = [
      '#type' => 'container',
      '#attributes' => ['id' => 'states-test-legacy'],
      '#attached' => ['library' => ['states_test/legacy_pseudo']],
      'positional' => [
        '#type' => 'container',
        '#attributes' => ['id' => 'states-test-positional', 'class' => ['js-form-wrapper']],
        '#states' => [
          'visible' => ['.states-test-choice:eq(1)' => ['checked' => TRUE]],
        ],
        'text' => ['#plain_text' => 'Positional dependent'],
      ],
      'custom' => [
        '#type' => 'container',
        '#attributes' => ['id' => 'states-test-custom', 'class' => ['js-form-wrapper']],
        '#states' => [
          'visible' => ['.states-test-choice:statesTest' => ['checked' => TRUE]],
        ],
        'text' => ['#plain_text' => 'Custom dependent'],
      ],
    ];
    $response = new AjaxResponse();
    $response->addCommand(new AppendCommand('#states-test-content', $fragment));
    return $response;
  }

}
