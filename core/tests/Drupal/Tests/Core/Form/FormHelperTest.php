<?php

declare(strict_types=1);

namespace Drupal\Tests\Core\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drupal\Core\Form\FormHelper.
 */
#[CoversClass(FormHelper::class)]
#[Group('Form')]
class FormHelperTest extends UnitTestCase {

  /**
   * Tests rewriting the #states selectors.
   */
  public function testRewriteStatesSelector(): void {

    // Simple selectors.
    $value = ['value' => 'medium'];
    $form['foo']['#states'] = [
      'visible' => [
        'select[name="fields[foo-id][settings_edit_form][settings][image_style]"]' => $value,
      ],
    ];
    FormHelper::rewriteStatesSelector($form, 'fields[foo-id][settings_edit_form]', 'options');
    $expected_selector = 'select[name="options[settings][image_style]"]';
    $this->assertSame($form['foo']['#states']['visible'][$expected_selector], $value, 'The #states selector was not properly rewritten.');

    // Complex selectors.
    $form = [];
    $form['bar']['#states'] = [
      'visible' => [
        [
          ':input[name="menu[type]"]' => ['value' => 'normal'],
        ],
        [
          ':input[name="menu[type]"]' => ['value' => 'tab'],
        ],
        ':input[name="menu[type]"]' => ['value' => 'default tab'],
      ],
      // Example from https://www.drupal.org/node/1464758
      'disabled' => [
        '[name="menu[options][dependee_1]"]' => ['value' => 'ON'],
        [
          ['[name="menu[options][dependee_2]"]' => ['value' => 'ON']],
          ['[name="menu[options][dependee_3]"]' => ['value' => 'ON']],
        ],
        [
          ['[name="menu[options][dependee_4]"]' => ['value' => 'ON']],
          'xor',
          ['[name="menu[options][dependee_5]"]' => ['value' => 'ON']],
        ],
      ],
    ];
    $expected['bar']['#states'] = [
      'visible' => [
        [
          ':input[name="options[type]"]' => ['value' => 'normal'],
        ],
        [
          ':input[name="options[type]"]' => ['value' => 'tab'],
        ],
        ':input[name="options[type]"]' => ['value' => 'default tab'],
      ],
      'disabled' => [
        '[name="options[options][dependee_1]"]' => ['value' => 'ON'],
        [
          ['[name="options[options][dependee_2]"]' => ['value' => 'ON']],
          ['[name="options[options][dependee_3]"]' => ['value' => 'ON']],
        ],
        [
          ['[name="options[options][dependee_4]"]' => ['value' => 'ON']],
          'xor',
          ['[name="options[options][dependee_5]"]' => ['value' => 'ON']],
        ],
      ],
    ];
    FormHelper::rewriteStatesSelector($form, 'menu', 'options');
    $this->assertSame($expected, $form, 'The #states selectors were properly rewritten.');

    // Legacy search strings with a leading :input still match CSS selectors
    // that no longer carry :input.
    $legacy_form = [
      'bare' => [
        '#states' => [
          'visible' => [
            '[name="options[operator]"]' => ['value' => 'between'],
          ],
        ],
      ],
      'element' => [
        '#states' => [
          'visible' => [
            'select[name="config_type"]' => ['value' => 'system.simple'],
          ],
        ],
      ],
      'is_group' => [
        '#states' => [
          'visible' => [
            [
              ':is(select, input)[name="op"]' => ['value' => 'contains'],
            ],
          ],
        ],
      ],
      'bare_input_noop' => [
        '#states' => [
          'visible' => [
            '[name="untouched"]' => ['checked' => TRUE],
          ],
        ],
      ],
    ];
    FormHelper::rewriteStatesSelector(
      $legacy_form['bare'],
      ':input[name="options[operator]"]',
      ':input[name="options[group_info][group_items][1][operator]"]',
    );
    $this->assertSame(
      ['[name="options[group_info][group_items][1][operator]"]' => ['value' => 'between']],
      $legacy_form['bare']['#states']['visible'],
    );
    FormHelper::rewriteStatesSelector(
      $legacy_form['element'],
      ':input[name="config_type"]',
      ':input[name="wrapper[config_type]"]',
    );
    $this->assertSame(
      ['select[name="wrapper[config_type]"]' => ['value' => 'system.simple']],
      $legacy_form['element']['#states']['visible'],
    );
    FormHelper::rewriteStatesSelector(
      $legacy_form['is_group'],
      ':input[name="op"]',
      '[name="exposed_op"]',
    );
    $this->assertSame(
      [[':is(select, input)[name="exposed_op"]' => ['value' => 'contains']]],
      $legacy_form['is_group']['#states']['visible'],
    );
    FormHelper::rewriteStatesSelector(
      $legacy_form['element'],
      ':input[name="wrapper[config_type]"]',
      'select[name="replaced_config_type"]',
    );
    $this->assertSame(
      ['select[name="replaced_config_type"]' => ['value' => 'system.simple']],
      $legacy_form['element']['#states']['visible'],
    );
    // A bare :input search string must not fall back to matching empty string.
    FormHelper::rewriteStatesSelector($legacy_form['bare_input_noop'], ':input', 'select');
    $this->assertSame(
      ['[name="untouched"]' => ['checked' => TRUE]],
      $legacy_form['bare_input_noop']['#states']['visible'],
    );

    // Token boundary: class/custom names ending in a tag name must not be
    // rewritten by the :input fallback, while combinator-separated selectors
    // still match.
    $boundary_form = [
      'class_suffix_full' => [
        '#states' => [
          'visible' => [
            '.my-input[name="foo"]' => ['checked' => TRUE],
          ],
        ],
      ],
      'class_suffix_attr' => [
        '#states' => [
          'visible' => [
            '.my-input[name="foo"]' => ['checked' => TRUE],
          ],
        ],
      ],
      'custom_tag_suffix' => [
        '#states' => [
          'visible' => [
            'my-select[name="foo"]' => ['checked' => TRUE],
          ],
        ],
      ],
      'combinator_descendant' => [
        '#states' => [
          'visible' => [
            'form .wrapper input[name="foo"]' => ['checked' => TRUE],
          ],
        ],
      ],
      'combinator_child_bare' => [
        '#states' => [
          'visible' => [
            'div > [name="foo"]' => ['checked' => TRUE],
          ],
        ],
      ],
    ];
    FormHelper::rewriteStatesSelector(
      $boundary_form['class_suffix_full'],
      ':input[name="foo"]',
      'select[name="bar"]',
    );
    $this->assertSame(
      ['.my-input[name="foo"]' => ['checked' => TRUE]],
      $boundary_form['class_suffix_full']['#states']['visible'],
    );
    FormHelper::rewriteStatesSelector(
      $boundary_form['class_suffix_attr'],
      ':input[name="foo"]',
      ':input[name="bar"]',
    );
    $this->assertSame(
      ['.my-input[name="foo"]' => ['checked' => TRUE]],
      $boundary_form['class_suffix_attr']['#states']['visible'],
    );
    FormHelper::rewriteStatesSelector(
      $boundary_form['custom_tag_suffix'],
      ':input[name="foo"]',
      'select[name="bar"]',
    );
    $this->assertSame(
      ['my-select[name="foo"]' => ['checked' => TRUE]],
      $boundary_form['custom_tag_suffix']['#states']['visible'],
    );
    FormHelper::rewriteStatesSelector(
      $boundary_form['combinator_descendant'],
      ':input[name="foo"]',
      'select[name="bar"]',
    );
    $this->assertSame(
      ['form .wrapper select[name="bar"]' => ['checked' => TRUE]],
      $boundary_form['combinator_descendant']['#states']['visible'],
    );
    FormHelper::rewriteStatesSelector(
      $boundary_form['combinator_child_bare'],
      ':input[name="foo"]',
      ':input[name="bar"]',
    );
    $this->assertSame(
      ['div > [name="bar"]' => ['checked' => TRUE]],
      $boundary_form['combinator_child_bare']['#states']['visible'],
    );
  }

  /**
   * Tests process states.
   */
  #[DataProvider('providerElements')]
  public function testProcessStates(array $elements, string $key): void {
    $json = Json::encode($elements['#states']);
    FormHelper::processStates($elements);
    $this->assertEquals(['core/drupal.states'], $elements['#attached']['library']);
    $this->assertEquals($json, $elements[$key]['data-drupal-states']);
  }

  /**
   * Provides a list of elements to test.
   */
  public static function providerElements(): array {
    return [
      [
        [
          '#type' => 'date',
          '#states' => [
            'visible' => [
              ':input[name="toggle_me"]' => ['checked' => TRUE],
            ],
          ],
        ],
        '#attributes',
      ],
      [
        [
          '#type' => 'item',
          '#states' => [
            'visible' => [
              ':input[name="foo"]' => ['value' => 'bar'],
            ],
          ],
          '#markup' => '',
          '#input' => TRUE,
        ],
        '#wrapper_attributes',
      ],
    ];
  }

}
