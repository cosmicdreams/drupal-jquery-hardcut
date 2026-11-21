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
    $this->assertSame([TRUE, TRUE, TRUE, TRUE], $this->getSession()->evaluateScript(<<<'JS'
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
        return [
          drags.every(({ table }) => !!table.querySelector('.js-tabledrag-cell-content > .js-tabledrag-handle')),
          marks.every(Boolean) && fixture.textContent.includes('text marker'),
          fixture.querySelectorAll('.tabledrag-changed-warning').length === 2,
          drags.every(({ table }) => !table.parentElement.matches('.table-scroll-wrapper') &&
            table.previousElementSibling.previousElementSibling.matches('.tabledrag-changed-warning')),
        ];
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
      override.legacyTableEvent = true;
      prototype.rowCheckboxHandler = override;
      class BulkActions extends Drupal.DefaultAdminBulkActions {
        focusHandler(event) {
          window.bulkFocus = event instanceof jQuery.Event && event.currentTarget === document.activeElement;
          return super.focusHandler(event);
        }
      }
      BulkActions.prototype.focusHandler.legacyTableEvent = true;
      window.bulkChanges = [];
      window.bulkInstance = new BulkActions(fixture.querySelector('[data-drupal-views-bulk-actions]'));
      JS);
    $this->loadLateBridge();
    $this->assertSame([TRUE, TRUE, TRUE, TRUE, [TRUE, TRUE, FALSE]], $session->evaluateScript(<<<'JS'
      (() => {
        const bulk = window.bulkInstance;
        const legacyTabbable = bulk.$tabbable;
        const alias = legacyTabbable[0] === bulk.tabbable[0];
        bulk.$tabbable = null;
        const emptyAlias = bulk.$tabbable.length === 0 && bulk.tabbable.length === 0;
        bulk.$tabbable = legacyTabbable;
        const row = document.getElementById('bulk-row');
        row.focus();
        const selectAll = bulk.selectAll[0];
        selectAll.click();
        const selected = row.checked && bulk.bulkActions.dataset.drupalStickyVbo === 'true' &&
          bulk.bulkActions.textContent.includes('1 item selected');
        selectAll.click();
        const cleared = !row.checked && bulk.bulkActions.dataset.drupalStickyVbo === 'false';
        row.click();
        return [alias && emptyAlias, window.bulkFocus, selected, cleared, window.bulkChanges];
      })()
      JS));
  }

}
