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
    // An explicit replacement is literal, as it was on the pinned key.
    $this->assertSame(
      [['[name="exposed_op"]' => ['value' => 'contains']]],
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
   * Tests pinned search strings still rewrite the converted core keys.
   *
   * Each case uses a key exactly as a core form now emits it, with the search
   * and replace strings an existing caller wrote against the pinned key.
   */
  #[DataProvider('providerConvertedStatesKeys')]
  public function testRewriteConvertedStatesKeys(string $key, string $search, string $replace, string $expected): void {
    $form = [
      'element' => [
        '#states' => [
          'visible' => [
            $key => ['checked' => TRUE],
          ],
        ],
      ],
    ];
    FormHelper::rewriteStatesSelector($form, $search, $replace);
    $this->assertSame([$expected => ['checked' => TRUE]], $form['element']['#states']['visible']);
  }

  /**
   * Provides converted core keys with pinned search and replace strings.
   */
  public static function providerConvertedStatesKeys(): array {
    return [
      'Install Tasks: unquoted attribute' => [
        'input[name=driver]',
        ':input[name=driver]',
        ':input[name=database_driver]',
        'input[name=database_driver]',
      ],
      'ContentEntityForm: input element' => [
        'input[name="revision"]',
        ':input[name="revision"]',
        ':input[name="wrapper[revision]"]',
        'input[name="wrapper[revision]"]',
      ],
      'ExportForm: select element' => [
        'select[name="langcode"]',
        ':input[name="langcode"]',
        ':input[name="wrapper[langcode]"]',
        'select[name="wrapper[langcode]"]',
      ],
      'Bare attribute key, as contributed forms may write it' => [
        '[name="revision"]',
        ':input[name="revision"]',
        ':input[name="wrapper[revision]"]',
        '[name="wrapper[revision]"]',
      ],
      'CommentForm: input element' => [
        'input[name="uid"]',
        ':input[name="uid"]',
        ':input[name="wrapper[uid]"]',
        'input[name="wrapper[uid]"]',
      ],
      'ConfigSingleImportForm: select element' => [
        'select[name="config_type"]',
        ':input[name="config_type"]',
        ':input[name="wrapper[config_type]"]',
        'select[name="wrapper[config_type]"]',
      ],
      'StringFilter: exposed operator group' => [
        ':is(select, input)[name="options[operator]"]',
        ':input[name="options[operator]"]',
        ':input[name="options[group_info][group_items][1][operator]"]',
        ':is(select, input)[name="options[group_info][group_items][1][operator]"]',
      ],
      'ImageToolkitForm: radio pseudo' => [
        'input[type="radio"][name="image_toolkit"]',
        ':radio[name="image_toolkit"]',
        ':radio[name="wrapper[image_toolkit]"]',
        'input[type="radio"][name="wrapper[image_toolkit]"]',
      ],
      'ImageToolkitForm: radio pseudo to CSS' => [
        'input[type="radio"][name="image_toolkit"]',
        ':radio[name="image_toolkit"]',
        'input[type="radio"][name="toolkit"]',
        'input[type="radio"][name="toolkit"]',
      ],
      'ImageToolkitForm: radio pseudo to attribute' => [
        'input[type="radio"][name="image_toolkit"]',
        ':radio[name="image_toolkit"]',
        '[name="toolkit"]',
        '[name="toolkit"]',
      ],
      'ImageToolkitForm: radio pseudo moved to a select' => [
        'input[type="radio"][name="image_toolkit"]',
        ':radio[name="image_toolkit"]',
        'select[name="toolkit"]',
        'select[name="toolkit"]',
      ],
      'Input pseudo moved to a select' => [
        'input[name="uid"]',
        ':input[name="uid"]',
        'select[name="owner"]',
        'select[name="owner"]',
      ],
      'Input pseudo leaves a quoted attribute value alone' => [
        '[data-label="see [name=foo]"]',
        ':input[name=foo]',
        ':input[name=bar]',
        '[data-label="see [name=foo]"]',
      ],
      'Input pseudo rewrites outside a quoted attribute value only' => [
        '[name="foo"][data-label=\'x [name="foo"]\']',
        ':input[name="foo"]',
        ':input[name="bar"]',
        '[name="bar"][data-label=\'x [name="foo"]\']',
      ],
      'Radio pseudo leaves a quoted attribute value alone' => [
        '[data-label=\'pick input[type="radio"][name="image_toolkit"]\']',
        ':radio[name="image_toolkit"]',
        '[name="toolkit"]',
        '[data-label=\'pick input[type="radio"][name="image_toolkit"]\']',
      ],
      'Radio pseudo leaves a checkbox key alone' => [
        'input[type="checkbox"][name="image_toolkit"]',
        ':radio[name="image_toolkit"]',
        ':radio[name="toolkit"]',
        'input[type="checkbox"][name="image_toolkit"]',
      ],
      'Radio pseudo leaves an untyped key alone' => [
        '[name="image_toolkit"]',
        ':radio[name="image_toolkit"]',
        ':radio[name="toolkit"]',
        '[name="image_toolkit"]',
      ],
      'Radio pseudo needs a token boundary' => [
        '.my-input[type="radio"][name="image_toolkit"]',
        ':radio[name="image_toolkit"]',
        ':radio[name="toolkit"]',
        '.my-input[type="radio"][name="image_toolkit"]',
      ],
    ];
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
