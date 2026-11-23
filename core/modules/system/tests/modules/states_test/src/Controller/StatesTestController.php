<?php

declare(strict_types=1);

namespace Drupal\states_test\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Url;

/**
 * Provides States markup for browser tests.
 *
 * @internal
 */
final class StatesTestController {

  /**
   * Builds a States fixture without the general form libraries.
   */
  public function form(): array {
    $first_choice = ':is(input[name="choice[1]"], input[data-unused=":eq(0)"]):not(.unused)';
    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'states-test-content'],
      '#theme_wrappers' => [
        'container',
        'form' => ['#attributes' => ['id' => 'states-test-form']],
      ],
      '#attached' => ['library' => ['core/drupal.ajax']],
      // Start the group selector with the checkbox used only by the group.
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
      'first' => [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'name' => 'choice[1]',
          'type' => 'checkbox',
          'class' => ['states-test-choice'],
        ],
      ],
      'dependent' => [
        '#type' => 'container',
        '#attributes' => ['id' => 'states-test-native', 'class' => ['js-form-wrapper']],
        '#states' => [
          'visible' => [
            $first_choice => ['checked' => TRUE],
          ],
        ],
        'text' => ['#plain_text' => 'Native dependent'],
      ],
      'group_dependent' => [
        '#type' => 'container',
        '#attributes' => ['id' => 'states-test-group', 'class' => ['js-form-wrapper']],
        '#states' => [
          'visible' => [
            '.states-test-choice' => ['checked' => TRUE],
          ],
        ],
        'text' => ['#plain_text' => 'Group dependent'],
      ],
      'second_dependent' => [
        '#type' => 'container',
        '#attributes' => ['id' => 'states-test-second', 'class' => ['js-form-wrapper']],
        '#states' => [
          'visible' => [
            $first_choice => ['checked' => TRUE],
          ],
        ],
        'text' => ['#plain_text' => 'Second dependent'],
      ],
      'remove_dependent' => [
        '#type' => 'link',
        '#title' => 'Remove group dependent',
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
   * Removes the dependent that watches both checkboxes.
   */
  public function removeDependent(): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new RemoveCommand('#states-test-group'));
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

}
