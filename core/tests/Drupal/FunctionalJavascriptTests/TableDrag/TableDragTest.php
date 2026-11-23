<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\TableDrag;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\ExpectationException;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests draggable table.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
class TableDragTest extends WebDriverTestBase {

  /**
   * Class used to verify that dragging operations are in execution.
   */
  const DRAGGING_CSS_CLASS = 'tabledrag-test-dragging';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['tabledrag_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Whether synthetic clicks directly activate header checkboxes.
   */
  protected bool $syntheticHeaderClick = FALSE;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Xpath selector for finding tabledrag indentation elements in a table row.
   *
   * @var string
   */
  protected static $indentationXpathSelector = 'child::td[1]/*[contains(concat(" ", normalize-space(@class), " "), " js-indentation ")][contains(concat(" ", normalize-space(@class), " "), " indentation ")]';

  /**
   * Xpath selector for finding the tabledrag changed marker.
   *
   * @var string
   */
  protected static $tabledragChangedXpathSelector = 'child::td[1]/abbr[contains(concat(" ", normalize-space(@class), " "), " tabledrag-changed ")]';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->state = $this->container->get('state');
  }

  /**
   * Tests revealing a responsive column after a non-header cell.
   */
  public function testResponsiveColumnToggle(): void {
    $this->drupalGet('tabledrag_test');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const fixture = document.createElement('section');
      fixture.id = 'responsive-fixture';
      fixture.classList.add('compact');
      fixture.innerHTML = `
        <style>
          #responsive-fixture.compact .priority-low { display: none; }
        </style>
        <table class="responsive-enabled">
          <thead><tr><th>Name</th><td></td><th class="priority-low">Detail</th></tr></thead>
          <tbody><tr><td>A</td><td class="priority-low">B</td></tr></tbody>
        </table>
      `;
      document.body.append(fixture);
      Drupal.behaviors.tableResponsive.attach(fixture);
      Drupal.behaviors.tableResponsive.attach(fixture);
      JS);

    $assert = $this->assertSession();
    $assert->elementsCount('css', '#responsive-fixture .tableresponsive-toggle', 1);
    $toggle = $assert->elementExists('css', '#responsive-fixture .tableresponsive-toggle');
    $cell = $assert->elementExists('css', '#responsive-fixture tbody .priority-low');
    $this->assertFalse($cell->isVisible());

    $toggle->click();
    $this->assertTrue($cell->isVisible());
    $this->assertSame('Hide lower priority columns', $toggle->getText());

    $toggle->click();
    $this->assertFalse($cell->isVisible());

    $session->executeScript(<<<'JS'
      document.getElementById('responsive-fixture').classList.remove('compact');
      window.dispatchEvent(new Event('resize'));
      JS);
    $this->assertTrue($cell->isVisible());
    $this->assertFalse($toggle->isVisible());
  }

  /**
   * Tests row weight switch.
   */
  public function testRowWeightSwitch(): void {
    $this->state->set('tabledrag_test_table', array_flip(range(1, 3)));

    $this->drupalGet('tabledrag_test');

    $session = $this->getSession();
    $page = $session->getPage();

    $weight_select1 = $page->findField("table[1][weight]");
    $weight_select2 = $page->findField("table[2][weight]");
    $weight_select3 = $page->findField("table[3][weight]");

    $this->assertTrue($session->evaluateScript(<<<'JS'
      (() => {
        const drag = Drupal.tableDrag['tabledrag-test-table'];
        const row = drag.table.tBodies[0].rows[0];
        const rowObject = new drag.row(row, 'keyboard', true, drag.maxDepth, false);
        return drag.table instanceof HTMLTableElement &&
          drag.toggleWeightButton instanceof HTMLButtonElement &&
          rowObject.element === row && rowObject.table === drag.table &&
          Array.isArray(rowObject.group) && rowObject.group[0] === row;
      })()
      JS));
    if ($this->defaultTheme === 'stark') {
      $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
      $this->assertFalse($session->evaluateScript(
        '"$table" in Drupal.tableDrag["tabledrag-test-table"]',
      ));
    }

    // Check that rows weight selects are hidden.
    $this->assertFalse($weight_select1->isVisible());
    $this->assertFalse($weight_select2->isVisible());
    $this->assertFalse($weight_select3->isVisible());

    // Toggle row weight selects as visible.
    $this->findWeightsToggle('Show row weights')->click();

    // Check that rows weight selects are visible.
    $this->assertTrue($weight_select1->isVisible());
    $this->assertTrue($weight_select2->isVisible());
    $this->assertTrue($weight_select3->isVisible());

    // Reload and verify the preference is still applied.
    $this->drupalGet('tabledrag_test');
    $weight_select1 = $page->findField("table[1][weight]");
    $weight_select2 = $page->findField("table[2][weight]");
    $weight_select3 = $page->findField("table[3][weight]");
    $this->assertTrue($weight_select1->isVisible());

    // Toggle row weight selects back to hidden.
    $this->findWeightsToggle('Hide row weights')->click();

    // Check that rows weight selects are hidden again.
    $this->assertFalse($weight_select1->isVisible());
    $this->assertFalse($weight_select2->isVisible());
    $this->assertFalse($weight_select3->isVisible());
  }

  /**
   * Tests that omitting the weight toggle does not prevent dragging.
   */
  public function testEmptyWeightToggleTheme(): void {
    $this->drupalGet('tabledrag_test');
    $this->assertSame([TRUE, TRUE], $this->getSession()->evaluateScript(<<<'JS'
      [null, ''].map((markup) => {
        Drupal.theme.tableDragToggle = () => markup;
        const table = document.createElement('table');
        table.innerHTML = '<tbody><tr class="draggable"><td>Row</td></tr></tbody>';
        document.body.append(table);
        const previous = table.previousSibling;
        const drag = new Drupal.tableDrag(table, {});
        table.querySelector('.tabledrag-handle').dispatchEvent(new MouseEvent('mousedown'));
        const started = drag.dragObject !== null && drag.toggleWeightButton === null &&
          table.previousSibling === previous;
        document.dispatchEvent(new MouseEvent('mouseup'));
        return started;
      })
      JS));
  }

  /**
   * Tests right-to-left (RTL) keys and switching from keyboard to pointer.
   */
  public function testRtlAndLingeringKeyboardDrag(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['rtl' => 1]]);
    $this->moveRowWithKeyboard($this->findRowById(2), 'left');
    $this->assertTableRow($this->findRowById(2), '2', -10, '1', 1, TRUE);
    $this->moveRowWithKeyboard($this->findRowById(2), 'right');
    $this->assertTableRow($this->findRowById(2), '2', -9, '', 0, TRUE);

    $this->assertSame([TRUE, TRUE, '2'], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const drag = Drupal.tableDrag['tabledrag-test-table'];
        const [first, second, third] = drag.table.tBodies[0].rows;
        let keyboardDrop = false;
        drag.onDrop = () => { keyboardDrop = keyboardDrop || drag.rowObject.method === 'keyboard'; };
        drag.rowObject = new drag.row(third, 'keyboard', true, drag.maxDepth, true);
        drag.rowObject.indent(1);
        drag.safeBlur = true;
        const { left, top } = first.getBoundingClientRect();
        drag.dragStart(new MouseEvent('mousedown', { clientX: left + 2, clientY: top + 2 }), drag, first);
        const pointerStarted = drag.dragObject !== null && drag.rowObject.element === first;
        drag.dropRow(new MouseEvent('mouseup'), drag);
        return [keyboardDrop, pointerStarted, third.querySelector('.tabledrag-test-parent').value];
      })()
      JS));
  }

  /**
   * Tests drag auto-scroll and storage-driven weight visibility.
   */
  public function testAutoScrollAndStorage(): void {
    $this->state->set('tabledrag_test_table', array_flip(range(1, 60)));
    $this->drupalGet('tabledrag_test');
    $session = $this->getSession();
    $weight = $session->getPage()->findField('table[1][weight]');
    $this->assertFalse($weight->isVisible());
    $session->executeScript(<<<'JS'
      window.setRemoteWeights = (newValue) => window.dispatchEvent(
        new StorageEvent('storage', { key: 'Drupal.tableDrag.showWeight', newValue }),
      );
      window.setRemoteWeights('true');
      JS);
    $this->assertTrue($weight->isVisible());
    $session->executeScript("window.setRemoteWeights(null);");
    $this->assertFalse($weight->isVisible());
    $this->findWeightsToggle('Show row weights');

    $session->executeScript(<<<'JS'
      const drag = Drupal.tableDrag['tabledrag-test-table'];
      const first = drag.table.tBodies[0].rows[0];
      const handle = first.querySelector('.tabledrag-handle');
      const { left, top } = handle.getBoundingClientRect();
      window.initialDragScroll = window.scrollY;
      handle.dispatchEvent(new MouseEvent('mousedown', {
        clientX: left + 2, clientY: top + 2, cancelable: true,
      }));
      document.dispatchEvent(new MouseEvent('mousemove', {
        clientX: left + 2, clientY: window.innerHeight - 2, cancelable: true,
      }));
      JS);
    $this->assertJsCondition('window.scrollY > window.initialDragScroll');
    $session->executeScript("document.dispatchEvent(new MouseEvent('mouseup'));");
    $this->assertJsCondition("Drupal.tableDrag['tabledrag-test-table'].dragObject === null");
  }

  /**
   * Tests a wrapper around the public table select initializer.
   */
  public function testWrappedTableSelect(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['tableselect' => 1]]);
    $this->assertSame([TRUE, [TRUE]], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const original = Drupal.tableSelect;
        Drupal.tableSelect = function () { return original.call(this); };
        Drupal.theme.checkbox = () => '<input type="checkbox">';
        const fixture = document.createElement('div');
        fixture.innerHTML = '<table><thead><tr><th class="select-all"></th></tr></thead>' +
          '<tbody><tr><td><input type="checkbox"></td></tr></tbody></table>';
        document.body.append(fixture);
        Drupal.behaviors.tableSelect.attach(fixture);
        const row = fixture.querySelector('tbody input');
        const changes = [];
        row.addEventListener('change', (event) => changes.push(event.bubbles));
        fixture.querySelector('th input').click();
        return [row.checked, changes];
      })()
      JS));
  }

  /**
   * Tests attach refresh for direct instances and same-task replacements.
   */
  public function testResponsiveRefreshIncludesDirectInstances(): void {
    $this->drupalGet('tabledrag_test');
    $this->assertSame([TRUE, TRUE, TRUE, 1], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const fixture = document.createElement('section');
        fixture.innerHTML = '<table id="removed" class="responsive-enabled"></table>';
        const direct = document.createElement('table');
        document.body.append(direct, fixture);
        const order = [];
        const prototype = Drupal.TableResponsive.prototype;
        const original = prototype.eventhandlerEvaluateColumnVisibility;
        const evaluate = function (event) {
          order.push(this.table.id);
          return original.call(this, event);
        };
        prototype.eventhandlerEvaluateColumnVisibility = evaluate;
        direct.id = 'direct';
        new Drupal.TableResponsive(direct);
        Drupal.attachBehaviors(fixture, drupalSettings);
        const evaluatedDirect = order.includes('direct');
        order.length = 0;
        const removed = fixture.querySelector('table');
        const replacement = document.createElement('table');
        replacement.className = removed.className;
        replacement.id = 'replacement';
        Drupal.detachBehaviors(removed, drupalSettings, 'unload');
        removed.replaceWith(replacement);
        Drupal.behaviors.tableResponsive.attach(fixture);
        const owner = document.createElement('form');
        document.body.append(owner);
        const release = Drupal.tableInteraction.own(owner, owner, () => {});
        Drupal.detachBehaviors(owner, drupalSettings, 'unload');
        const { disconnect } = MutationObserver.prototype;
        let disconnects = 0;
        MutationObserver.prototype.disconnect = function () {
          disconnects += 1;
          return disconnect.call(this);
        };
        try {
          release();
          return [evaluatedDirect, !order.includes('removed'), order.includes('replacement'), disconnects];
        } finally {
          MutationObserver.prototype.disconnect = disconnect;
        }
      })()
      JS));
  }

  /**
   * Tests retained content across unload and moving a table within the page.
   */
  public function testTableBindingsReleasedOnUnload(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['tableselect' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const drag = Drupal.tableDrag['tabledrag-test-table'];
      const handle = drag.table.querySelector('.tabledrag-handle');
      const initialSpan = drag.table.tHead.rows[0].cells[0].colSpan;
      const toggle = drag.toggleWeightButton;
      const [first, second] = drag.table.tBodies[0].rows;
      let drops = 0;
      drag.onDrop = () => { drops += 1; };
      handle.dispatchEvent(new MouseEvent('mousedown'));
      drag.rowObject.direction = 'down';
      drag.rowObject.swap('after', second);
      Drupal.detachBehaviors(drag.table, drupalSettings, 'unload');

      const dialog = document.createElement('dialog');
      dialog.innerHTML = '<table class="responsive-enabled"><thead><tr><th class="select-all priority-low" style="display: none">Detail</th></tr></thead>' +
        '<tbody><tr><td><input type="checkbox"></td></tr></tbody></table>';
      document.body.append(dialog);
      dialog.show();
      Drupal.behaviors.tableResponsive.attach(dialog);
      Drupal.behaviors.tableSelect.attach(dialog);
      const table = dialog.querySelector('table');
      const responsive = Drupal.TableResponsive.tables.find((instance) => instance.table === table);
      const header = table.querySelector('th input');
      dialog.close();
      Drupal.detachBehaviors(dialog, drupalSettings, 'unload');

      window.checkRetainedTables = () => {
        const active = drag.dragObject !== null && document.body.classList.contains(drag.dragOrientation);
        document.dispatchEvent(new MouseEvent('mouseup'));
        const rows = [...drag.table.tBodies[0].rows];
        const weights = rows.map((row) => Number(row.querySelector('select').value));
        const completed = drops === 1 && drag.dragObject === null &&
          rows[0] === second && rows[1] === first &&
          weights.every((weight, index) => index === 0 || weight > weights[index - 1]);
        const dragDialog = document.createElement('dialog');
        document.body.append(dragDialog);
        dragDialog.append(drag.table);
        dragDialog.show();
        dragDialog.close();
        Drupal.detachBehaviors(dragDialog, drupalSettings, 'unload');
        dragDialog.showModal();
        handle.dispatchEvent(new MouseEvent('mousedown'));
        const restartedPointer = drag.dragObject !== null;
        document.dispatchEvent(new MouseEvent('mouseup'));
        handle.dispatchEvent(new KeyboardEvent('keydown', { keyCode: 39 }));
        const restartedKeyboard = drag.rowObject?.method === 'keyboard';
        document.dispatchEvent(new MouseEvent('mouseup'));
        dragDialog.close();
        dialog.showModal();
        responsive.link.click();
        dialog.close();
        dialog.show();
        const checkbox = table.querySelector('tbody input');
        checkbox.click();
        const retained = Drupal.TableResponsive.tables.includes(responsive) &&
          table.querySelector('th input') === header && header.checked &&
          responsive.revealedCells.length > 0;
        dialog.close();

        const before = document.querySelectorAll('[data-drupal-selector="tabledrag-toggle-weight"]').length;
        const destination = document.createElement('section');
        document.body.append(destination);
        destination.append(drag.table);
        Drupal.attachBehaviors(destination, drupalSettings);
        return [active, completed, retained, restartedPointer, restartedKeyboard,
          toggle.isConnected && Drupal.tableDrag['tabledrag-test-table'] === drag &&
          drag.table.contains(handle) && drag.table.tHead.rows[0].cells[0].colSpan === initialSpan &&
          document.querySelectorAll('[data-drupal-selector="tabledrag-toggle-weight"]').length === before];
      };
      JS);
    $this->assertSame([TRUE, TRUE, TRUE, TRUE, TRUE, TRUE], $session->evaluateScript('window.checkRetainedTables()'));
  }

  /**
   * Tests cleanup after a table is removed in a later task.
   */
  public function testUnloadedTableRemovesOrphanedToggles(): void {
    $this->drupalGet('tabledrag_test');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const fixture = document.createElement('section');
      const table = document.createElement('table');
      table.className = 'responsive-enabled';
      table.id = 'unloading-table';
      table.dataset.drupalTabledrag = table.id;
      fixture.append(table);
      document.body.append(fixture);
      const settings = { tableDrag: { [table.id]: {} } };
      Drupal.attachBehaviors(fixture, settings);
      const drag = Drupal.tableDrag[table.id];
      const responsive = Drupal.TableResponsive.tables.find((instance) => instance.table === table);
      let moves = 0;
      const move = () => { moves += 1; };
      drag.dragRow = move;
      const listenerRuns = () => {
        const before = moves;
        document.dispatchEvent(new MouseEvent('mousemove'));
        return moves !== before;
      };
      Drupal.detachBehaviors(table, drupalSettings, 'unload');
      window.unloadingTable = { table, drag, responsive, listenerRuns };
      JS);
    $this->assertSame([TRUE, TRUE], $session->evaluateScript(<<<'JS'
      [unloadingTable.listenerRuns(), Drupal.TableResponsive.tables.includes(unloadingTable.responsive)]
      JS));
    $session->executeScript('unloadingTable.table.remove()');
    $this->assertJsCondition(<<<'JS'
      !unloadingTable.drag.toggleWeightButton.isConnected &&
      !unloadingTable.responsive.link.isConnected &&
      !Drupal.TableResponsive.tables.includes(unloadingTable.responsive) &&
      !unloadingTable.listenerRuns()
      JS);

    $session->executeScript(<<<'JS'
      const fixture = document.createElement('section');
      const table = document.createElement('table');
      table.id = 'replaced-drag-table';
      table.dataset.drupalTabledrag = table.id;
      const replacement = table.cloneNode();
      const markup = '<tbody><tr id="retained-change" class="draggable"><td>Row</td></tr></tbody>';
      table.innerHTML = replacement.innerHTML = markup;
      fixture.append(table);
      document.body.append(fixture);
      const settings = { ...drupalSettings, tableDrag: { [table.id]: {} } };
      Drupal.attachBehaviors(fixture, settings);
      const original = Drupal.tableDrag[table.id];
      original.changedRowIds.add('retained-change');
      Drupal.detachBehaviors(table, settings, 'unload');
      table.remove();
      window.attachReplacementDrag = () => {
        fixture.append(replacement);
        Drupal.attachBehaviors(fixture, settings);
        window.replacementDragAttached = true;
      };
      window.checkReplacementDrag = () => {
        const drag = Drupal.tableDrag[table.id];
        return drag?.table === replacement && drag.changedRowIds.has('retained-change') &&
          !!replacement.querySelector('#retained-change .tabledrag-changed');
      };
      JS);
    // The replacement arrives after unload cleanup has run, as with a settle delay.
    $session->executeScript('setTimeout(window.attachReplacementDrag, 0)');
    $this->assertJsCondition('window.replacementDragAttached === true');
    $this->assertTrue($session->evaluateScript('window.checkReplacementDrag()'));
  }

  /**
   * Tests that an empty weight toggle does not persist across a disconnection.
   */
  public function testEmptyWeightToggleThemeOnReattach(): void {
    $this->drupalGet('tabledrag_test');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const table = document.createElement('table');
      const fixture = document.createElement('div');
      fixture.append(table);
      document.body.append(fixture);
      table.id = 'empty-weight-toggle';
      table.dataset.drupalTabledrag = table.id;
      const settings = { tableDrag: { [table.id]: {} } };
      const theme = Drupal.theme.tableDragToggle;
      Drupal.theme.tableDragToggle = () => '';
      Drupal.behaviors.tableDrag.attach(fixture, settings);
      Drupal.theme.tableDragToggle = theme;
      Drupal.detachBehaviors(table, settings, 'unload');
      table.remove();
      window.reattachWeightTable = () => {
        fixture.append(table);
        Drupal.behaviors.tableDrag.attach(fixture, settings);
        return Drupal.tableDrag[table.id].toggleWeightButton?.isConnected === true;
      };
      JS);
    $this->assertTrue($session->evaluateScript('window.reattachWeightTable()'));
  }

  /**
   * Tests that suppressing the themed warning still completes the drop.
   */
  public function testSuppressedChangedWarning(): void {
    $this->drupalGet('tabledrag_test');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const drag = Drupal.tableDrag['tabledrag-test-table'];
      Drupal.theme.tableDragChangedWarning = () => [];
      window.tableDragDrops = 0;
      drag.onDrop = () => { window.tableDragDrops += 1; };
      JS);

    $row = $this->findRowById(2);
    $this->moveRowWithKeyboard($row, 'right');
    $this->assertSession()->elementNotExists('css', '.tabledrag-changed-warning');
    $this->assertTrue($row->hasClass('drag-previous'));
    $this->assertSame([1, TRUE, 'true'], $session->evaluateScript(<<<'JS'
      (() => {
        const drag = Drupal.tableDrag['tabledrag-test-table'];
        return [
          window.tableDragDrops,
          drag.rowObject === null,
          drag.table.closest('form').getAttribute('data-tabledrag-save-warning'),
        ];
      })()
      JS));
  }

  /**
   * Tests that an Ajax response marks a row and adds the warning once.
   */
  public function testAjaxChangedWarning(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['ajax' => 1]]);
    if ($this->defaultTheme === 'stark') {
      $this->assertTrue($this->getSession()->evaluateScript("typeof jQuery === 'undefined'"));
    }
    $assert = $this->assertSession();
    $this->assertSame(
      "Cannot read properties of undefined (reading 'id')",
      $this->getSession()->evaluateScript(<<<'JS'
        (() => {
          try {
            Drupal.AjaxCommands.prototype.tabledragChanged(null, {
              id: 'missing-row',
              tabledrag_instance: 'tabledrag-test-table',
            }, 'success');
          } catch (error) {
            return error.message;
          }
        })()
        JS),
    );
    $assert->elementNotExists('css', '.tabledrag-changed-warning');
    $assert->elementNotExists('css', '#tabledrag-test-table-row-2 .tabledrag-changed');
    $this->getSession()->getPage()->pressButton('Mark row changed');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementsCount('css', '#tabledrag-test-table-row-2 .tabledrag-changed', 1);
    $assert->elementsCount('css', '.tabledrag-changed-warning', 1);
    $this->getSession()->getPage()->pressButton('Mark row changed');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementsCount('css', '.tabledrag-changed-warning', 1);
    $assert->elementsCount('css', '#tabledrag-test-table-row-2 .tabledrag-changed', 1);
  }

  /**
   * Tests draggable table drag'n'drop.
   */
  public function testDragAndDrop(): void {
    $this->state->set('tabledrag_test_table', array_flip(range(1, 3)));
    $this->drupalGet('tabledrag_test');

    $session = $this->getSession();
    $page = $session->getPage();

    // Confirm touchevents detection is loaded with Tabledrag.
    $this->assertNotNull($this->assertSession()->waitForElement('css', 'html.no-touchevents'));
    $weight_select1 = $page->findField("table[1][weight]");
    $weight_select2 = $page->findField("table[2][weight]");
    $weight_select3 = $page->findField("table[3][weight]");

    // Check that initially the rows are in the correct order.
    $this->assertOrder(['Row with id 1', 'Row with id 2', 'Row with id 3']);

    // Check that the 'unsaved changes' text is not present in the message area.
    $this->assertSession()->pageTextNotContains('You have unsaved changes.');

    $row1 = $this->findRowById(1)->find('css', 'a.tabledrag-handle');
    $row2 = $this->findRowById(2)->find('css', 'a.tabledrag-handle');
    $row3 = $this->findRowById(3)->find('css', 'a.tabledrag-handle');

    // Drag row1 over row2.
    $row1->dragTo($row2);

    // Check that the 'unsaved changes' text was added in the message area.
    $this->assertSession()->waitForText('You have unsaved changes.');

    // Check that row1 and row2 were swapped.
    $this->assertOrder(['Row with id 2', 'Row with id 1', 'Row with id 3']);

    // Check that weights were changed.
    $this->assertGreaterThan($weight_select2->getValue(), $weight_select1->getValue());
    $this->assertGreaterThan($weight_select2->getValue(), $weight_select3->getValue());
    $this->assertGreaterThan($weight_select1->getValue(), $weight_select3->getValue());

    // Now move the last row (row3) in the second position. row1 should go last.
    $row3->dragTo($row1);

    // Check that the order is: row2, row3 and row1.
    $this->assertOrder(['Row with id 2', 'Row with id 3', 'Row with id 1']);
  }

  /**
   * Tests touch events on a drag handle and the document.
   */
  public function testTouchDrag(): void {
    $this->state->set('tabledrag_test_table', array_flip(range(1, 3)));
    $this->drupalGet('tabledrag_test');
    $trace = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const table = document.getElementById('tabledrag-test-table');
        const [first, second] = table.tBodies[0].rows;
        const handle = first.querySelector('.tabledrag-handle');
        const drag = Drupal.tableDrag[table.id];
        const x = handle.getBoundingClientRect().left + 2;
        const start = first.getBoundingClientRect().top + 2;
        const end = second.getBoundingClientRect().top + 2;
        const touch = (clientY) => new Touch({ identifier: 1, target: handle, clientX: x, clientY });
        handle.dispatchEvent(new TouchEvent('touchstart', { touches: [touch(start)], cancelable: true }));
        document.dispatchEvent(new TouchEvent('touchmove', { touches: [touch(end)], cancelable: true }));
        document.dispatchEvent(new TouchEvent('touchend', { changedTouches: [touch(end)] }));
        return [
          table.tBodies[0].rows[0] === second,
          first.classList.contains('drag-previous'),
          drag.dragObject === null && drag.rowObject === null,
        ];
      })()
      JS);
    $this->assertSame([TRUE, TRUE, TRUE], $trace);
  }

  /**
   * Tests table drag in the menu and taxonomy administration forms.
   */
  public function testMenuAndTaxonomyTables(): void {
    $this->container->get('module_installer')->install(['menu_ui', 'taxonomy']);
    $this->rebuildContainer();
    Menu::create(['id' => 'drag-test', 'label' => 'Drag test menu'])->save();
    Vocabulary::create(['vid' => 'drag_test', 'name' => 'Drag test vocabulary'])->save();
    foreach ([0, 1] as $weight) {
      MenuLinkContent::create([
        'title' => "Drag test link $weight",
        'link' => ['uri' => 'internal:/user'],
        'menu_name' => 'drag-test',
        'weight' => $weight,
      ])->save();
      Term::create([
        'name' => "Drag test term $weight",
        'vid' => 'drag_test',
        'weight' => $weight,
      ])->save();
    }
    $this->drupalLogin($this->drupalCreateUser(['administer menu', 'administer taxonomy']));
    foreach ([
      'admin/structure/menu/manage/drag-test' => 'link',
      'admin/structure/taxonomy/manage/drag_test/overview' => 'term',
    ] as $path => $label) {
      $this->drupalGet($path);
      $this->assertOrder(["Drag test $label 0", "Drag test $label 1"]);
      $link = $this->getSession()->getPage()->findLink("Drag test $label 1");
      $this->assertNotNull($link);
      $row = $link->getParent();
      while ($row && $row->getTagName() !== 'tr') {
        $row = $row->getParent();
      }
      $this->assertNotNull($row);
      // These pages do not load the keyboard synchronization fixture.
      $handle = $row->find('css', '.tabledrag-handle');
      $handle->focus();
      $handle->keyDown(38);
      $handle->keyUp(38);
      $handle->blur();
      $this->assertOrder(["Drag test $label 1", "Drag test $label 0"]);
      $this->submitForm([], 'Save');
      $this->drupalGet($path);
      $this->assertOrder(["Drag test $label 1", "Drag test $label 0"]);
    }
  }

  /**
   * Tests accessibility through keyboard of the tabledrag functionality.
   */
  public function testKeyboardAccessibility(): void {
    $this->assertKeyboardAccessibility();
  }

  /**
   * Asserts accessibility through keyboard of a test draggable table.
   *
   * @param string $drupal_path
   *   The drupal path where the '#tabledrag-test-table' test table is present.
   *   Defaults to 'tabledrag_test'.
   * @param array|null $structure
   *   The expected table structure. If this isn't specified or equals NULL,
   *   then the expected structure will be set by this method. Defaults to NULL.
   *
   * @internal
   */
  protected function assertKeyboardAccessibility(string $drupal_path = 'tabledrag_test', ?array $structure = NULL): void {
    $expected_table = $structure ?: [
      ['id' => '1', 'weight' => 0, 'parent' => '', 'indentation' => 0, 'changed' => FALSE],
      ['id' => '2', 'weight' => 0, 'parent' => '', 'indentation' => 0, 'changed' => FALSE],
      ['id' => '3', 'weight' => 0, 'parent' => '', 'indentation' => 0, 'changed' => FALSE],
      ['id' => '4', 'weight' => 0, 'parent' => '', 'indentation' => 0, 'changed' => FALSE],
      ['id' => '5', 'weight' => 0, 'parent' => '', 'indentation' => 0, 'changed' => FALSE],
    ];
    if (!empty($drupal_path)) {
      $this->state->set('tabledrag_test_table', array_flip(range(1, 5)));
      $this->drupalGet($drupal_path);
    }
    $this->assertDraggableTable($expected_table);

    // Nest the row with id 2 as child of row 1.
    $this->moveRowWithKeyboard($this->findRowById(2), 'right');
    $expected_table[1] = ['id' => '2', 'weight' => -10, 'parent' => '1', 'indentation' => 1, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);

    // Nest the row with id 3 as child of row 1.
    $this->moveRowWithKeyboard($this->findRowById(3), 'right');
    $expected_table[2] = ['id' => '3', 'weight' => -9, 'parent' => '1', 'indentation' => 1, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);

    // Nest the row with id 3 as child of row 2.
    $this->moveRowWithKeyboard($this->findRowById(3), 'right');
    $expected_table[2] = ['id' => '3', 'weight' => -10, 'parent' => '2', 'indentation' => 2, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);

    // Nesting should be allowed to maximum level 2.
    $this->moveRowWithKeyboard($this->findRowById(4), 'right', 4);
    $expected_table[3] = ['id' => '4', 'weight' => -9, 'parent' => '2', 'indentation' => 2, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);

    // Re-order children of row 1.
    $this->moveRowWithKeyboard($this->findRowById(4), 'up');
    $expected_table[2] = ['id' => '4', 'weight' => -10, 'parent' => '2', 'indentation' => 2, 'changed' => TRUE];
    $expected_table[3] = ['id' => '3', 'weight' => -9, 'parent' => '2', 'indentation' => 2, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);

    // Move back the row 3 to the 1st level.
    $this->moveRowWithKeyboard($this->findRowById(3), 'left');
    $expected_table[3] = ['id' => '3', 'weight' => -9, 'parent' => '1', 'indentation' => 1, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);

    $this->moveRowWithKeyboard($this->findRowById(3), 'left');
    $expected_table[0] = ['id' => '1', 'weight' => -10, 'parent' => '', 'indentation' => 0, 'changed' => FALSE];
    $expected_table[3] = ['id' => '3', 'weight' => -9, 'parent' => '', 'indentation' => 0, 'changed' => TRUE];
    $expected_table[4] = ['id' => '5', 'weight' => -8, 'parent' => '', 'indentation' => 0, 'changed' => FALSE];
    $this->assertDraggableTable($expected_table);

    // Move row 3 to the last position.
    $this->moveRowWithKeyboard($this->findRowById(3), 'down');
    $expected_table[3] = ['id' => '5', 'weight' => -9, 'parent' => '', 'indentation' => 0, 'changed' => FALSE];
    $expected_table[4] = ['id' => '3', 'weight' => -8, 'parent' => '', 'indentation' => 0, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);

    // Nothing happens when trying to move the last row further down.
    $this->moveRowWithKeyboard($this->findRowById(3), 'down');
    $this->assertDraggableTable($expected_table);

    // Nest row 3 under 5. The max depth allowed should be 1.
    $this->moveRowWithKeyboard($this->findRowById(3), 'right', 3);
    $expected_table[4] = ['id' => '3', 'weight' => -10, 'parent' => '5', 'indentation' => 1, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);

    // The first row of the table cannot be nested.
    $this->moveRowWithKeyboard($this->findRowById(1), 'right');
    $this->assertDraggableTable($expected_table);

    // Move a row which has nested children. The children should move with it,
    // with nesting preserved. Swap the order of the top-level rows by moving
    // row 1 to after row 3.
    $this->moveRowWithKeyboard($this->findRowById(1), 'down', 2);
    $expected_table[0] = ['id' => '5', 'weight' => -10, 'parent' => '', 'indentation' => 0, 'changed' => FALSE];
    $expected_table[3] = $expected_table[1];
    $expected_table[1] = $expected_table[4];
    $expected_table[4] = $expected_table[2];
    $expected_table[2] = ['id' => '1', 'weight' => -9, 'parent' => '', 'indentation' => 0, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);
  }

  /**
   * Tests the root and leaf behaviors for rows.
   */
  public function testRootLeafDraggableRowsWithKeyboard(): void {
    $this->state->set('tabledrag_test_table', [
      1 => [],
      2 => ['parent' => 1, 'depth' => 1, 'classes' => ['tabledrag-leaf']],
      3 => ['parent' => 1, 'depth' => 1],
      4 => [],
      5 => ['classes' => ['tabledrag-root']],
    ]);

    $this->drupalGet('tabledrag_test');
    $expected_table = [
      ['id' => '1', 'weight' => 0, 'parent' => '', 'indentation' => 0, 'changed' => FALSE],
      ['id' => '2', 'weight' => 0, 'parent' => '1', 'indentation' => 1, 'changed' => FALSE],
      ['id' => '3', 'weight' => 0, 'parent' => '1', 'indentation' => 1, 'changed' => FALSE],
      ['id' => '4', 'weight' => 0, 'parent' => '', 'indentation' => 0, 'changed' => FALSE],
      ['id' => '5', 'weight' => 0, 'parent' => '', 'indentation' => 0, 'changed' => FALSE],
    ];
    $this->assertDraggableTable($expected_table);

    // Rows marked as root cannot be moved as children of another row.
    $this->moveRowWithKeyboard($this->findRowById(5), 'right');
    $this->assertDraggableTable($expected_table);

    // Rows marked as leaf cannot have children. Trying to move the row #3
    // as child of #2 should have no results.
    $this->moveRowWithKeyboard($this->findRowById(3), 'right');
    $this->assertDraggableTable($expected_table);

    // Leaf can be still swapped and moved to first level.
    $this->moveRowWithKeyboard($this->findRowById(2), 'down');
    $this->moveRowWithKeyboard($this->findRowById(2), 'left');
    $expected_table[0]['weight'] = -10;
    $expected_table[1]['id'] = '3';
    $expected_table[1]['weight'] = -10;
    $expected_table[2] = ['id' => '2', 'weight' => -9, 'parent' => '', 'indentation' => 0, 'changed' => TRUE];
    $expected_table[3]['weight'] = -8;
    $expected_table[4]['weight'] = -7;
    $this->assertDraggableTable($expected_table);

    // Root rows can have children.
    $this->moveRowWithKeyboard($this->findRowById(4), 'down');
    $this->moveRowWithKeyboard($this->findRowById(4), 'right');
    $expected_table[3]['id'] = '5';
    $expected_table[4] = ['id' => '4', 'weight' => -10, 'parent' => '5', 'indentation' => 1, 'changed' => TRUE];
    $this->assertDraggableTable($expected_table);
  }

  /**
   * Tests the warning that appears upon making changes to a tabledrag table.
   */
  public function testTableDragChangedWarning(): void {
    $this->drupalGet('tabledrag_test');

    // By default no text is visible.
    $this->assertSession()->pageTextNotContains('You have unsaved changes.');
    // Try to make a non-allowed action, like moving further down the last row.
    // No changes happen, so no message should be shown.
    $this->moveRowWithKeyboard($this->findRowById(5), 'down');
    $this->assertSession()->pageTextNotContains('You have unsaved changes.');

    // Wait for the warning to become visible during its fade-in.
    $this->moveRowWithKeyboard($this->findRowById(5), 'right');
    $this->assertSession()->waitForText('You have unsaved changes.');
    $this->assertSession()->pageTextContainsOnce('You have unsaved changes.');

    // Make another change, the text will stay visible and appear only once.
    $this->moveRowWithKeyboard($this->findRowById(2), 'up');
    $this->assertSession()->pageTextContainsOnce('You have unsaved changes.');
  }

  /**
   * Asserts that several pieces of markup are in a given order in the page.
   *
   * @param string[] $items
   *   An ordered list of strings.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   *   When any of the given string is not found.
   *
   * @todo Remove this and use the WebAssert method when #2817657 is done.
   *
   * @internal
   */
  protected function assertOrder(array $items): void {
    $session = $this->getSession();
    $text = $session->getPage()->getHtml();
    $strings = [];
    foreach ($items as $item) {
      if (($pos = strpos($text, $item)) === FALSE) {
        throw new ExpectationException("Cannot find '$item' in the page", $session->getDriver());
      }
      $strings[$pos] = $item;
    }
    ksort($strings);
    $this->assertSame($items, array_values($strings), "Strings found on the page but incorrectly ordered.");
  }

  /**
   * Tests nested draggable tables through keyboard.
   */
  public function testNestedDraggableTables(): void {
    $this->state->set('tabledrag_test_table', array_flip(range(1, 5)));
    $this->drupalGet('tabledrag_test_nested');
    $this->assertKeyboardAccessibility('');

    // Now move the rows of the parent table.
    $expected_parent_table = [
      [
        'id' => 'parent_1',
        'weight' => 0,
        'parent' => '',
        'indentation' => 0,
        'changed' => FALSE,
      ],
      [
        'id' => 'parent_2',
        'weight' => 0,
        'parent' => '',
        'indentation' => 0,
        'changed' => FALSE,
      ],
      [
        'id' => 'parent_3',
        'weight' => 0,
        'parent' => '',
        'indentation' => 0,
        'changed' => FALSE,
      ],
    ];
    $this->assertDraggableTable($expected_parent_table, 'tabledrag-test-parent-table', TRUE);

    // Switch parent table rows children.
    $this->moveRowWithKeyboard($this->findRowById('parent_2', 'tabledrag-test-parent-table'), 'up');
    $expected_parent_table = [
      [
        'id' => 'parent_2',
        'weight' => -10,
        'parent' => '',
        'indentation' => 0,
        'changed' => TRUE,
      ],
      [
        'id' => 'parent_1',
        'weight' => -9,
        'parent' => '',
        'indentation' => 0,
        'changed' => FALSE,
      ],
      [
        'id' => 'parent_3',
        'weight' => -8,
        'parent' => '',
        'indentation' => 0,
        'changed' => FALSE,
      ],
    ];
    $this->assertDraggableTable($expected_parent_table, 'tabledrag-test-parent-table', TRUE);

    // Try to move the row that contains the nested table to the last position.
    // Order should be changed, but changed marker isn't added.
    // This seems to be buggy, but this is the original behavior.
    $this->moveRowWithKeyboard($this->findRowById('parent_1', 'tabledrag-test-parent-table'), 'down');
    $expected_parent_table = [
      [
        'id' => 'parent_2',
        'weight' => -10,
        'parent' => '',
        'indentation' => 0,
        'changed' => TRUE,
      ],
      [
        'id' => 'parent_3',
        'weight' => -9,
        'parent' => '',
        'indentation' => 0,
        'changed' => FALSE,
      ],
      // Since 'parent_1' row was moved, it should be marked as changed, but
      // this would fail with core tabledrag.js.
      [
        'id' => 'parent_1',
        'weight' => -8,
        'parent' => '',
        'indentation' => 0,
        'changed' => NULL,
      ],
    ];
    $this->assertDraggableTable($expected_parent_table, 'tabledrag-test-parent-table', TRUE);

    // Re-test the nested draggable table.
    $expected_child_table_structure = [
      [
        'id' => '5',
        'weight' => -10,
        'parent' => '',
        'indentation' => 0,
        'changed' => FALSE,
      ],
      [
        'id' => '3',
        'weight' => -10,
        'parent' => '5',
        'indentation' => 1,
        'changed' => TRUE,
      ],
      [
        'id' => '1',
        'weight' => -9,
        'parent' => '',
        'indentation' => 0,
        'changed' => TRUE,
      ],
      [
        'id' => '2',
        'weight' => -10,
        'parent' => '1',
        'indentation' => 1,
        'changed' => TRUE,
      ],
      [
        'id' => '4',
        'weight' => -10,
        'parent' => '2',
        'indentation' => 2,
        'changed' => TRUE,
      ],
    ];
    $this->assertDraggableTable($expected_child_table_structure);
  }

  /**
   * Asserts the whole structure of the draggable test table.
   *
   * @param array $structure
   *   The table structure. Each entry represents a row and consists of:
   *   - id: the expected value for the ID hidden field.
   *   - weight: the expected row weight.
   *   - parent: the expected parent ID for the row.
   *   - indentation: how many indents the row should have.
   *   - changed: whether or not the row should have been marked as changed.
   * @param string $table_id
   *   The ID of the table. Defaults to 'tabledrag-test-table'.
   * @param bool $skip_missing
   *   Whether assertions done on missing elements value may be skipped or not.
   *   Defaults to FALSE.
   *
   * @internal
   */
  protected function assertDraggableTable(array $structure, string $table_id = 'tabledrag-test-table', bool $skip_missing = FALSE): void {
    $rows = $this->getSession()->getPage()->findAll('xpath', "//table[@id='$table_id']/tbody/tr");
    $this->assertSession()->elementsCount('xpath', "//table[@id='$table_id']/tbody/tr", count($structure));

    foreach ($structure as $delta => $expected) {
      $this->assertTableRow($rows[$delta], $expected['id'], $expected['weight'], $expected['parent'], $expected['indentation'], $expected['changed'], $skip_missing);
    }
  }

  /**
   * Asserts the values of a draggable row.
   *
   * @param \Behat\Mink\Element\NodeElement $row
   *   The row element to assert.
   * @param string $id
   *   The expected value for the ID hidden input of the row.
   * @param int $weight
   *   The expected weight of the row.
   * @param string $parent
   *   The expected parent ID.
   * @param int $indentation
   *   The expected indentation of the row.
   * @param bool|null $changed
   *   Whether or not the row should have been marked as changed. NULL means
   *   that this assertion should be skipped.
   * @param bool $skip_missing
   *   Whether assertions done on missing elements value may be skipped or not.
   *   Defaults to FALSE.
   *
   * @internal
   */
  protected function assertTableRow(NodeElement $row, string $id, int $weight, string $parent = '', int $indentation = 0, ?bool $changed = FALSE, bool $skip_missing = FALSE): void {
    // Assert that the row position is correct by checking that the id
    // corresponds.
    $id_name = "table[$id][id]";
    if (!$skip_missing || $row->find('hidden_field_selector', ['hidden_field', $id_name])) {
      $this->assertSession()->hiddenFieldValueEquals($id_name, $id, $row);
    }
    $parent_name = "table[$id][parent]";
    if (!$skip_missing || $row->find('hidden_field_selector', ['hidden_field', $parent_name])) {
      $this->assertSession()->hiddenFieldValueEquals($parent_name, $parent, $row);
    }
    $this->assertSession()->fieldValueEquals("table[$id][weight]", $weight, $row);
    $this->assertSession()->elementsCount('xpath', static::$indentationXpathSelector, $indentation, $row);
    // A row is marked as changed when the related markup is present.
    if ($changed !== NULL) {
      $this->assertSession()->elementsCount('xpath', static::$tabledragChangedXpathSelector, (int) $changed, $row);
    }
  }

  /**
   * Finds a row in the test table by the row ID.
   *
   * @param string|int $id
   *   The ID of the row.
   * @param string $table_id
   *   The ID of the parent table. Defaults to 'tabledrag-test-table'.
   *
   * @return \Behat\Mink\Element\NodeElement
   *   The row element.
   */
  protected function findRowById($id, $table_id = 'tabledrag-test-table'): NodeElement {
    $xpath = "//table[@id='$table_id']/tbody/tr[.//input[@name='table[$id][id]']]";
    $row = $this->getSession()->getPage()->find('xpath', $xpath);
    $this->assertNotEmpty($row);
    return $row;
  }

  /**
   * Finds the show/hide weight toggle element.
   *
   * @param string $expected_text
   *   The expected text on the element.
   *
   * @return \Behat\Mink\Element\NodeElement
   *   The toggle element.
   */
  protected function findWeightsToggle(string $expected_text): NodeElement {
    $toggle = $this->getSession()->getPage()->findButton($expected_text);
    $this->assertNotEmpty($toggle);
    return $toggle;
  }

  /**
   * Moves a row through the keyboard.
   *
   * @param \Behat\Mink\Element\NodeElement $row
   *   The row to move.
   * @param string $arrow
   *   The arrow button to use to move the row. Either one of 'left', 'right',
   *   'up' or 'down'.
   * @param int $repeat
   *   (optional) How many times to press the arrow button. Defaults to 1.
   */
  protected function moveRowWithKeyboard(NodeElement $row, $arrow, $repeat = 1): void {
    $keys = [
      'left' => 37,
      'right' => 39,
      'up' => 38,
      'down' => 40,
    ];
    if (!isset($keys[$arrow])) {
      throw new \InvalidArgumentException('The arrow parameter must be one of "left", "right", "up" or "down".');
    }

    $key = $keys[$arrow];

    $handle = $row->find('css', 'a.tabledrag-handle');
    $handle->focus();

    for ($i = 0; $i < $repeat; $i++) {
      $this->markRowHandleForDragging($handle);
      $handle->keyDown($key);
      $handle->keyUp($key);
      $this->waitUntilDraggingCompleted($handle);
    }

    $handle->blur();
  }

  /**
   * Marks a row handle for dragging.
   *
   * The handle is marked by adding a css class that is removed by an helper
   * js library once the dragging is over.
   *
   * @param \Behat\Mink\Element\NodeElement $handle
   *   The draggable row handle element.
   *
   * @throws \Exception
   *   Thrown when the class is not added successfully to the handle.
   */
  protected function markRowHandleForDragging(NodeElement $handle): void {
    $class = self::DRAGGING_CSS_CLASS;
    $script = <<<JS
document.evaluate("{$handle->getXpath()}", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null)
  .singleNodeValue.classList.add('{$class}');
JS;

    $this->getSession()->executeScript($script);
    $has_class = $this->getSession()->getPage()->waitFor(1, function () use ($handle, $class) {
      return $handle->hasClass($class);
    });

    if (!$has_class) {
      throw new \Exception(sprintf('Dragging css class was not added on handle "%s".', $handle->getXpath()));
    }
  }

  /**
   * Waits until the dragging operations are finished on a row handle.
   *
   * @param \Behat\Mink\Element\NodeElement $handle
   *   The draggable row handle element.
   *
   * @throws \Exception
   *   Thrown when the dragging operations are not completed on time.
   */
  protected function waitUntilDraggingCompleted(NodeElement $handle): void {
    $class_removed = $this->getSession()->getPage()->waitFor(1, function () use ($handle): bool {
      return !$handle->hasClass($this::DRAGGING_CSS_CLASS);
    });

    if (!$class_removed) {
      throw new \Exception(sprintf('Dragging operations did not complete on time on handle %s', $handle->getXpath()));
    }
  }

}
