<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Theme;

use Drupal\FunctionalJavascriptTests\TableDrag\TableDragTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests draggable tables with Admin theme.
 *
 * @see \Drupal\FunctionalJavascriptTests\TableDrag\TableDragTest
 */
#[Group('default_admin')]
#[RunTestsInSeparateProcesses]
class AdminTableDragTest extends TableDragTest {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'default_admin';

  /**
   * {@inheritdoc}
   */
  protected bool $syntheticHeaderClick = TRUE;

  /**
   * {@inheritdoc}
   */
  protected static $indentationXpathSelector = 'child::td[1]/div[contains(concat(" ", normalize-space(@class), " "), " js-tabledrag-cell-content ")]/div[contains(concat(" ", normalize-space(@class), " "), " js-indentation ")]';

  /**
   * {@inheritdoc}
   */
  protected static $tabledragChangedXpathSelector = 'child::td[1]/div[contains(concat(" ", normalize-space(@class), " "), " js-tabledrag-cell-content ")]/abbr[contains(concat(" ", normalize-space(@class), " "), " tabledrag-changed ")]';

  /**
   * Ensures that there are no duplicate tabledrag handles.
   */
  public function testNoDuplicates(): void {
    $this->drupalGet('tabledrag_test_nested');
    $this->assertCount(1, $this->findRowById(1)->findAll('css', '.tabledrag-handle'));
  }

  /**
   * Tests bare table warnings, marker output and legacy theme returns.
   */
  #[IgnoreDeprecations]
  public function testAdminTableDragThemes(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['legacy' => 1]]);
    $this->assertSame([TRUE, TRUE, TRUE, TRUE, TRUE], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const behavior = Drupal.behaviors.adminTableDrag;
        behavior.attach(null);
        behavior.attach(document.createTextNode(''));
        const settings = Drupal.tableDrag['tabledrag-test-table'].tableSettings;
        const fixture = document.createElement('form');
        const originalHandle = Drupal.theme.tableDragHandle;
        Drupal.theme.tableDragHandle = (orientation) => jQuery(originalHandle(orientation));
        document.body.append(fixture);
        const drags = ['bare-first', 'bare-second'].map((id) => {
          const table = document.createElement('table');
          table.id = id;
          table.innerHTML = '<tbody><tr class="draggable"><td>Row</td></tr></tbody>';
          fixture.append(table);
          const drag = new Drupal.tableDrag(table, settings);
          Drupal.tableDrag[id] = drag;
          return drag;
        });
        behavior.attach(jQuery(fixture), {
          tableDrag: { 'bare-first': settings, 'bare-second': settings },
        });
        Drupal.theme.tableDragHandle = originalHandle;
        const originalMarker = Drupal.theme.tableDragChangedMarker;
        const markers = [() => [], () => 'text marker'];
        const marks = drags.map((drag, index) => {
          const element = drag.table.tBodies[0].rows[0];
          element.id = drag.table.id + '-row';
          const row = new drag.row(element, '', false, 0, false);
          Drupal.theme.tableDragChangedMarker = markers[index];
          row.markChanged();
          Drupal.theme.tableDragChangedMarker = originalMarker;
          row.addChangedWarning();
          row.addChangedWarning();
          return drag.changedRowIds.has(element.id);
        });
        const scroll = document.createElement('div');
        scroll.className = 'table-scroll-wrapper';
        const table = document.createElement('table');
        scroll.append(table);
        fixture.append(scroll);
        const wrapped = new Drupal.tableDrag(table, {});
        return [
          drags.every(({ table }) => !!table.querySelector('.js-tabledrag-cell-content > .js-tabledrag-handle')),
          marks.every(Boolean) && fixture.textContent.includes('text marker'),
          fixture.querySelectorAll('.tabledrag-changed-warning').length === 2,
          drags.every(({ table }) => !table.parentElement.matches('.table-scroll-wrapper') &&
            table.previousElementSibling.previousElementSibling.matches('.tabledrag-changed-warning')),
          scroll.previousSibling === wrapped.toggleWeightButton.parentNode &&
            !fixture.textContent.includes('[object Object]'),
        ];
      })()
      JS));
  }

  /**
   * Tests inserting the original cell content into nested theme wrappers.
   */
  public function testNestedTableDragCellWrappers(): void {
    $this->drupalGet('tabledrag_test');
    $this->assertSame(['Row', TRUE], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        Drupal.theme.tableDragCellContentWrapper = () => '<div class="labels"><div class="label"><span></span></div></div>';
        Drupal.theme.tableDragCellItemsWrapper = () => '<div class="items"><div class="item"><div></div></div></div>';
        const fixture = document.body.appendChild(document.createElement('div'));
        fixture.innerHTML = '<table id="nested-wrapper"><tbody><tr class="draggable"><td><strong>Row</strong></td></tr></tbody></table>';
        Drupal.behaviors.adminTableDrag.attach(fixture, { tableDrag: { 'nested-wrapper': {} } });
        return [
          fixture.querySelector('td > .items > .item > div > .labels > .label > span > strong')?.textContent,
          Boolean(fixture.querySelector('td > .items.js-tabledrag-cell-content')),
        ];
      })()
      JS));
  }

  /**
   * Tests shared Ajax and table handler order on a bulk-selection checkbox.
   */
  #[IgnoreDeprecations]
  public function testBulkActionsAjaxHandlerOrder(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['tableselect' => 1, 'legacy_ajax' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const cases = [[false, false], [true, false], [true, true]].map(([ajaxFirst, cancel]) => {
          const trace = [];
          const fixture = document.body.appendChild(document.createElement('div'));
          fixture.innerHTML = '<form><div data-drupal-views-bulk-actions></div>' +
            '<table><thead><tr><th class="select-all"></th></tr></thead>' +
            '<tbody><tr><td class="views-field-node-bulk-form"><input type="checkbox"></td></tr></tbody></table></form>';
          const box = fixture.querySelector('td input');
          jQuery(box).on('change', () => trace.push('before'));
          const attachAjax = () => {
            const ajax = Drupal.ajax({ element: box, event: 'change', url: location.href });
            ajax.eventResponse = Drupal.ajax.useNativeCallback((element, event) => {
              trace.push('ajax');
              if (cancel) event.stopImmediatePropagation();
            });
          };
          if (ajaxFirst) attachAjax();
          const bulk = new Drupal.DefaultAdminBulkActions(fixture.querySelector('[data-drupal-views-bulk-actions]'));
          bulk.rowCheckboxHandler = () => trace.push('table');
          if (!ajaxFirst) attachAjax();
          jQuery(box).on('change', () => trace.push('after'));
          box.click();
          return trace;
        });
        return [Boolean(Drupal.ajax.commandCompatibility), cases];
      })()
      JS);
    $this->assertSame([
      TRUE,
      [
        ['before', 'table', 'ajax', 'after'],
        ['before', 'ajax', 'table', 'after'],
        ['before', 'ajax'],
      ],
    ], $result);
  }

  /**
   * Tests a theme that omits the outer cell-items wrapper.
   */
  public function testEmptyTableDragCellItemsWrapper(): void {
    $this->drupalGet('tabledrag_test');
    $this->assertEmptyWrapperParity('tableDragCellItemsWrapper', FALSE);
  }

  /**
   * Tests a theme that omits the inner cell-content wrapper.
   */
  public function testEmptyTableDragCellContentWrapper(): void {
    $this->drupalGet('tabledrag_test');
    $this->assertEmptyWrapperParity('tableDragCellContentWrapper', TRUE);
  }

  /**
   * Asserts that an omitted wrapper leaves the other wrapper and both rows.
   *
   * @param string $hook
   *   The theme hook to replace.
   * @param bool $outer
   *   Whether the outer wrapper should remain.
   */
  private function assertEmptyWrapperParity(string $hook, bool $outer): void {
    $this->getSession()->executeScript("window.emptyWrapperHook = '$hook';");
    $this->assertSame(array_fill(0, 3, [TRUE, $outer ? 2 : 0, $outer ? 0 : 2, 2]), $this->getSession()->evaluateScript(<<<'JS'
      ['', null, undefined].map((markup, index) => {
        Drupal.theme[window.emptyWrapperHook] = () => markup;
        const fixture = document.createElement('section');
        const id = 'unwrapped-drag-' + index;
        fixture.innerHTML = '<table id="' + id + '"><tbody>' +
          '<tr class="draggable"><td>First</td></tr><tr class="draggable"><td>Second</td></tr></tbody></table>';
        document.body.append(fixture);
        const tableDrag = { [id]: {} };
        Drupal.behaviors.tableDrag.attach(fixture, { tableDrag });
        let attached = true;
        try {
          Drupal.behaviors.adminTableDrag.attach(fixture, { tableDrag });
        } catch (error) {
          attached = false;
        }
        const rows = [...fixture.querySelectorAll('tr')];
        return [attached, fixture.querySelectorAll('.js-tabledrag-cell-content').length,
          fixture.querySelectorAll('.tabledrag-cell-content__item').length,
          rows.filter((row) => row.querySelector('.js-tabledrag-handle') &&
            once.filter('adminTabledrag', [row]).length).length];
      })
      JS));
  }

  /**
   * Tests synthetic changes with built-in and chained bulk handlers.
   */
  #[IgnoreDeprecations]
  public function testBuiltInBulkActionsIgnoreSyntheticChange(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['legacy' => 1, 'tableselect' => 1]]);
    $this->assertSame([[TRUE, ['Initial', 'Initial'], FALSE], [TRUE, [NULL, NULL], FALSE], [2, 2]], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const calls = [];
        return [false, true].map((chain) => {
          const fixture = document.createElement('div');
          fixture.innerHTML = '<form><div data-drupal-views-bulk-actions>' +
            (chain ? '' : '<span data-drupal-views-bulk-actions-status>Initial</span>') +
            '</div><table><thead><tr><th class="select-all"><input type="checkbox" checked></th></tr></thead>' +
            '<tbody><tr><td class="views-field-node-bulk-form"><input type="checkbox"></td></tr></tbody></table></form>';
          document.body.append(fixture);
          const actions = fixture.querySelector('[data-drupal-views-bulk-actions]');
          const bulk = new Drupal.DefaultAdminBulkActions(actions);
          if (chain) {
            for (const method of ['rowCheckboxHandler', 'selectAllHandler']) {
              const original = bulk[method];
              bulk[method] = function (event) {
                calls.push(event.isTrigger);
                return original.call(this, event);
              };
            }
          }
          let succeeded = true;
          const statuses = [];
          for (const checkbox of fixture.querySelectorAll('th input, td input')) {
            try {
              jQuery(checkbox).triggerHandler('change');
            } catch (error) {
              succeeded = false;
            }
            statuses.push(actions.querySelector('[data-drupal-views-bulk-actions-status]')?.textContent ?? null);
          }
          return [succeeded, statuses, bulk.bulkActionsSticky];
        }).concat([calls]);
      })()
      JS));
  }

  /**
   * Tests synthetic window events registered by bulk actions.
   */
  #[IgnoreDeprecations]
  public function testBulkActionsSyntheticWindowEvents(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['legacy' => 1, 'tableselect' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const fixture = document.createElement('div');
      fixture.innerHTML = '<form><div data-drupal-views-bulk-actions>' +
        '<span data-drupal-views-bulk-actions-status></span></div>' +
        '<table><tbody><tr><td class="views-field-node-bulk-form"><input type="checkbox" checked></td></tr></tbody></table></form>';
      document.body.append(fixture);
      const actions = fixture.querySelector('[data-drupal-views-bulk-actions]');
      const bulk = new Drupal.DefaultAdminBulkActions(actions);
      window.bulkWindowCalls = [];
      bulk.scrollResizeHandler = () => window.bulkWindowCalls.push('layout');
      const original = bulk.rowCheckboxHandler;
      bulk.rowCheckboxHandler = function (event) {
        window.bulkWindowCalls.push('load');
        return original.call(this, event);
      };
      window.triggerBulkWindowEvent = (type) => jQuery(window).trigger(type);
      window.triggerBulkWindowEvent('resize');
      JS);
    $this->assertJsCondition("window.bulkWindowCalls.includes('layout')");
    $session->executeScript("window.bulkWindowCalls.length = 0; window.triggerBulkWindowEvent('scroll');");
    $this->assertJsCondition("window.bulkWindowCalls.includes('layout')");
    $session->executeScript("window.triggerBulkWindowEvent('load');");
    $this->assertSame(['layout', 'load'], $session->evaluateScript('window.bulkWindowCalls'));
    $this->assertSession()->elementTextContains('css', '[data-drupal-views-bulk-actions-status]', '1 item selected');
  }

  /**
   * Tests synthetic change delivery to contributed bulk-action overrides.
   */
  #[IgnoreDeprecations]
  public function testBulkActionsSyntheticChange(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['legacy' => 1, 'tableselect' => 1]]);
    $this->assertSame([['row', 2], ['all', 2], ['row', 3], ['all', NULL]], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const fixture = document.createElement('div');
        fixture.innerHTML = '<form><div data-drupal-views-bulk-actions></div>' +
          '<table><thead><tr><th class="select-all"><input type="checkbox"></th></tr></thead><tbody>' +
          '<tr><td class="views-field views-field-node-bulk-form"><input type="checkbox"></td></tr>' +
          '</tbody></table></form>';
        document.body.append(fixture);
        Drupal.behaviors.tableSelect.attach(fixture);
        const bulk = new Drupal.DefaultAdminBulkActions(fixture.querySelector('[data-drupal-views-bulk-actions]'));
        const calls = [];
        bulk.rowCheckboxHandler = (event) => calls.push(['row', event.isTrigger]);
        bulk.selectAllHandler = (event) => calls.push(['all', event.isTrigger]);
        jQuery(fixture.querySelector('td input')).triggerHandler('change');
        jQuery(fixture.querySelector('th input')).triggerHandler('change');
        fixture.querySelector('th input').click();
        return calls;
      })()
      JS));
  }

  /**
   * Tests sticky bulk actions and a legacy override saved before bridge load.
   */
  #[IgnoreDeprecations]
  public function testLateBulkActionsBridge(): void {
    $this->openLateBridgeFixture();
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const fixture = document.createElement('div');
      fixture.id = 'bulk-fixture';
      fixture.innerHTML = '<form><div data-drupal-views-bulk-actions><span data-drupal-views-bulk-actions-status></span></div>' +
        '<table><thead><tr><th class="select-all"></th></tr></thead><tbody>' +
        '<tr><td class="views-field views-field-node-bulk-form"><input id="bulk-row" type="checkbox"></td></tr>' +
        '</tbody></table></form>';
      document.body.append(fixture);
      Drupal.behaviors.tableSelect.attach(fixture);
      const prototype = Drupal.DefaultAdminBulkActions.prototype;
      const original = prototype.rowCheckboxHandler;
      const override = function (event) {
        if (event instanceof jQuery.Event) {
          window.bulkChanges.push(event.isTrigger === 3);
        }
        return original.call(this, event);
      };
      prototype.rowCheckboxHandler = override;
      class BulkActions extends Drupal.DefaultAdminBulkActions {
        focusHandler(event) {
          window.bulkFocus = (window.bulkFocus ?? 0) +
            Number(event instanceof jQuery.Event && event.currentTarget.id === 'bulk-row');
          return super.focusHandler(event);
        }

        blurHandler(event) {
          window.bulkBlur.push(event instanceof jQuery.Event && event.currentTarget.id === 'bulk-row');
          return super.blurHandler(event);
        }
      }
      const originalClass = Drupal.DefaultAdminBulkActions;
      const Wrapped = function (...args) {
        return Reflect.construct(BulkActions, args, new.target);
      };
      Wrapped.prototype = BulkActions.prototype;
      window.bulkChanges = [];
      window.bulkBlur = [];
      Drupal.DefaultAdminBulkActions = Wrapped;
      try {
        window.bulkInstance = new Wrapped(fixture.querySelector('[data-drupal-views-bulk-actions]'));
      } finally {
        Drupal.DefaultAdminBulkActions = originalClass;
      }
      JS);
    $this->loadLateBridge();
    $this->assertSame([TRUE, TRUE, [TRUE], TRUE, TRUE, [TRUE, TRUE, FALSE]], $session->evaluateScript(<<<'JS'
      (() => {
        const bulk = window.bulkInstance;
        const legacyTabbable = bulk.$tabbable;
        const alias = legacyTabbable[0] === bulk.tabbable[0];
        bulk.$tabbable = null;
        const emptyAlias = bulk.$tabbable.length === 0 && bulk.tabbable.length === 0;
        bulk.$tabbable = legacyTabbable;
        const row = document.getElementById('bulk-row');
        jQuery(row).trigger('focus');
        const singleFocus = window.bulkFocus === 1;
        jQuery(bulk.$tabbable.filter('#bulk-row')).triggerHandler('focus');
        const syntheticFocus = window.bulkFocus === 2;
        jQuery(row).trigger('blur');
        const selectAll = bulk.selectAll[0];
        selectAll.click();
        const selected = row.checked && bulk.bulkActions.dataset.drupalStickyVbo === 'true' &&
          bulk.bulkActions.textContent.includes('1 item selected');
        selectAll.click();
        const cleared = !row.checked && bulk.bulkActions.dataset.drupalStickyVbo === 'false';
        row.click();
        return [alias && emptyAlias, singleFocus && syntheticFocus, window.bulkBlur, selected, cleared, window.bulkChanges];
      })()
      JS));
  }

  /**
   * Tests selection and listener ownership when unloading just bulk actions.
   */
  #[IgnoreDeprecations]
  public function testBulkActionsOwnership(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['legacy' => 1, 'tableselect' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      (() => {
        const fixture = document.createElement('div');
        fixture.innerHTML = '<form><div data-drupal-views-bulk-actions><span data-drupal-views-bulk-actions-status></span></div>' +
          '<table><thead><tr><th class="select-all"></th></tr></thead><tbody>' +
          '<tr><td class="views-field views-field-node-bulk-form"><input type="checkbox"></td></tr>' +
          '</tbody></table></form>';
        document.body.append(fixture);
        Drupal.behaviors.tableSelect.attach(fixture);
        const actions = fixture.querySelector('[data-drupal-views-bulk-actions]');
        const bulk = new Drupal.DefaultAdminBulkActions(actions);
        const original = Drupal.tableSelect;
        Drupal.tableSelect = function () { return original.call(this); };
        let updates = 0;
        let focus = 0;
        let blur = 0;
        bulk.updateStatus = () => { updates += 1; };
        bulk.focusHandler = () => { focus += 1; };
        bulk.blurHandler = () => { blur += 1; };
        fixture.querySelector('th input').click();
        const row = fixture.querySelector('tbody input');
        const trigger = () => {
          jQuery(row).triggerHandler('focus');
          jQuery(row).triggerHandler('blur');
          return [focus, blur];
        };
        const before = trigger();
        Drupal.detachBehaviors(actions, drupalSettings, 'unload');
        const retained = trigger();
        actions.remove();
        window.checkReleasedBulkActions = () => [updates, before, retained, trigger()];
      })()
      JS);
    $this->assertSame([1, [1, 1], [2, 2], [2, 2]], $session->evaluateScript('window.checkReleasedBulkActions()'));
  }

}
