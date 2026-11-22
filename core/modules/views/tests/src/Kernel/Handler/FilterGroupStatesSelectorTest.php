<?php

declare(strict_types=1);

namespace Drupal\Tests\views\Kernel\Handler;

use Drupal\Core\Form\FormHelper;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\views\Kernel\ViewsKernelTestBase;
use Drupal\views\Plugin\views\filter\NumericFilter;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests grouped filter rows accept both operator #states key forms.
 */
#[Group('views')]
#[RunTestsInSeparateProcesses]
class FilterGroupStatesSelectorTest extends ViewsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $testViews = ['test_view'];

  /**
   * Tests converted and pinned operator keys in grouped filter rows.
   */
  public function testGroupRowsAcceptBothKeyForms(): void {
    $core = $this->buildGroupItem(FALSE);
    $this->assertSame(
      [['[name="options[group_info][group_items][1][operator]"]' => ['value' => 'between']]],
      array_slice($core['min']['#states']['visible'], 0, 1),
    );
    $this->assertSame('', $core['min']['#title']);
    $this->assertSame('', $core['max']['#title']);

    // A plugin or alter that still emits the pinned :input key keeps working.
    $pinned = $this->buildGroupItem(TRUE);
    $this->assertSame(
      [[':input[name="options[group_info][group_items][1][operator]"]' => ['value' => 'between']]],
      array_slice($pinned['min']['#states']['visible'], 0, 1),
    );
    $this->assertSame('', $pinned['min']['#title']);
    $this->assertSame('', $pinned['max']['#title']);
  }

  /**
   * Builds the value widgets of the first grouped filter row.
   *
   * @param bool $pinned_keys
   *   Whether the value form emits the pinned :input operator key.
   *
   * @return array
   *   The value element of group item 1.
   */
  protected function buildGroupItem(bool $pinned_keys): array {
    $view = Views::getView('test_view');
    $view->setDisplay();
    $view->displayHandlers->get('default')->overrideOption('filters', [
      'age' => [
        'id' => 'age',
        'plugin_id' => 'numeric',
        'table' => 'views_test_data',
        'field' => 'age',
        'relationship' => 'none',
        'exposed' => TRUE,
        'expose' => [
          'operator' => 'age_op',
          'label' => 'age',
          'identifier' => 'age',
        ],
        'is_grouped' => TRUE,
        'group_info' => [
          'label' => 'age',
          'identifier' => 'age',
          'default_group' => 'All',
          'group_items' => [
            1 => [
              'title' => 'Age is between 26 and 29',
              'operator' => 'between',
              'value' => ['min' => 26, 'max' => 29],
            ],
          ],
        ],
      ],
    ]);
    $view->initHandlers();
    $core = $view->filter['age'];
    $handler = new class([], 'numeric', $core->getPluginDefinition()) extends NumericFilter {

      /**
       * Whether the value form emits the pinned :input operator key.
       */
      public bool $pinnedKeys = FALSE;

      /**
       * {@inheritdoc}
       */
      protected function valueForm(&$form, FormStateInterface $form_state) {
        parent::valueForm($form, $form_state);
        if ($this->pinnedKeys) {
          FormHelper::rewriteStatesSelector($form['value'], '[name="options[operator]"]', ':input[name="options[operator]"]');
        }
      }

      /**
       * Exposes the grouped filter form builder.
       */
      public function buildGroupRows(array &$form, FormStateInterface $form_state): void {
        $this->buildExposedFiltersGroupForm($form, $form_state);
      }

    };
    $handler->pinnedKeys = $pinned_keys;
    $options = $core->options;
    $handler->init($view, $view->display_handler, $options);

    $form = ['#pre_render' => []];
    $handler->buildGroupRows($form, new FormState());
    return $form['group_info']['group_items'][1]['value'];
  }

}
