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
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
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
   * Tests deprecated table members, event callbacks and weight notifications.
   */
  #[IgnoreDeprecations]
  public function testLegacyTableDrag(): void {
    $contracts = [
      'Drupal.tableDrag#$table',
      'Drupal.tableDrag#$toggleWeightButton',
      'Drupal.TableResponsive#$link',
      'The legacy event argument for pointerCoords',
      'The synthetic resize.tableresponsive trigger',
      'The legacy resize.tableresponsive removal',
    ];
    foreach ($contracts as $contract) {
      $this->expectUserDeprecationMessage("Javascript Deprecation: $contract is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native table or row member, or native theme/interaction callback.");
    }
    $this->drupalGet('tabledrag_test', ['query' => ['legacy' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const drag = Drupal.tableDrag['tabledrag-test-table'];
      const table = document.getElementById('tabledrag-test-table');
      const legacyTable = jQuery(table);
      const button = drag.toggleWeightButton;
      const legacyButton = jQuery(button);
      drag.$table = legacyTable;
      drag.$toggleWeightButton = legacyButton;
      const members = drag.$table === legacyTable && drag.table === table &&
        drag.$toggleWeightButton === legacyButton && drag.toggleWeightButton === button;

      drag.$table = null;
      const nullTable = drag.table === null && drag.$table.length === 0;
      drag.$table = [table];
      drag.$toggleWeightButton = undefined;
      const undefinedButton = drag.toggleWeightButton === undefined && drag.$toggleWeightButton.length === 0;
      drag.$toggleWeightButton = button;
      const setters = nullTable && undefinedButton &&
        drag.table === table && drag.toggleWeightButton === button;

      const args = [];
      const original = drag.pointerCoords;
      const pointer = new MouseEvent('mousemove', { clientX: 10, clientY: 20 });
      drag.pointerCoords = function (event) {
        args.push(event);
        return original.call(this, event);
      };
      const native = drag.pointerCoords;
      const legacy = function (event) {
        args.push(event);
        return native.call(this, event);
      };
      legacy.legacyTableEvent = true;
      drag.pointerCoords = legacy;
      const previous = drag.pointerCoords;
      const chained = function (event) {
        args.push(event);
        return previous.call(this, event);
      };
      chained.legacyTableEvent = true;
      drag.pointerCoords = chained;
      const { x, y } = drag.pointerCoords(pointer);
      const callbacks = args.length === 3 && args[0] === args[1] &&
        args[0].originalEvent === pointer && args[2] === pointer && x === 10 && y === 20;
      drag.pointerCoords = original;
      const restored = drag.pointerCoords === original;
      drag.pointerCoords = () => false;
      delete drag.pointerCoords;
      const deleted = drag.pointerCoords === original;

      const subclass = Object.create(Drupal.tableDrag.prototype);
      subclass.pointerCoords = (event) => event === pointer;
      const instance = Object.create(subclass);
      const inherited = instance.pointerCoords(pointer);
      instance.pointerCoords = () => false;
      delete instance.pointerCoords;
      const deletedSubclass = instance.pointerCoords(pointer);

      const nativeEvents = [];
      const legacyEvents = [];
      table.addEventListener('columnschange', (event) => nativeEvents.push(event));
      jQuery(window).on('columnschange', (event, displayWeight) => {
        legacyEvents.push({ event: event.originalEvent, displayWeight });
      });
      let resizeRefreshes = 0;
      let blockResize = true;
      const before = [];
      jQuery(window).on('resize.tableresponsive', (event) => {
        resizeRefreshes += 1;
        before.push('early');
        if (blockResize) {
          event.stopImmediatePropagation();
        }
      });
      const responsiveTable = document.createElement('table');
      responsiveTable.classList.add('responsive-enabled');
      responsiveTable.innerHTML = '<thead><tr><th class="priority-low" style="display: none">Detail</th></tr></thead>';
      const style = document.createElement('style');
      style.textContent = '#responsive-order .tableresponsive-toggle { display: none; }';
      const fixture = document.createElement('div');
      fixture.id = 'responsive-order';
      fixture.append(style, responsiveTable);
      document.body.append(fixture);

      const prototype = Drupal.TableResponsive.prototype;
      const originalEvaluate = prototype.eventhandlerEvaluateColumnVisibility;
      prototype.eventhandlerEvaluateColumnVisibility = function (event) {
        before.push(event instanceof Event && event.target === window && event.currentTarget === window ? 'native' : 'wrong');
        originalEvaluate.call(this, event);
        return 'resized';
      };
      Drupal.behaviors.tableResponsive.attach(fixture);
      const refreshesAfterAttach = resizeRefreshes;
      const blocked = before.join(',') === 'early';
      prototype.eventhandlerEvaluateColumnVisibility = originalEvaluate;

      const responsive = Drupal.TableResponsive.tables.find(({ table }) => table === responsiveTable);
      const header = responsive.headers[0];
      const visible = () => responsive.link.style.display !== 'none';
      blockResize = false;
      const resizeResult = jQuery(window).triggerHandler('resize.tableresponsive');
      const order = before.join(',') === 'early,early,native' && resizeResult === 'resized';
      const initialVisibility = responsive.link.style.display === 'inline-block';
      responsive.$link.data('pegged', 1);
      header.style.display = 'table-cell';
      jQuery(window).trigger('resize.tableresponsive');
      const pegged = visible() && responsive.$link.data('pegged') === 1;
      responsive.$link.data({ pegged: 0 });
      jQuery(window).trigger('resize.tableresponsive');
      const syntheticResizeHidToggle = !visible();
      header.style.display = 'none';
      window.dispatchEvent(new Event('resize'));
      const nativeResizeShowedToggle = visible();
      jQuery(window).off('resize.tableresponsive');
      header.style.display = 'table-cell';
      window.dispatchEvent(new Event('resize'));
      const removedResizeLeftToggleVisible = visible();
      window.readTableDrag = () => [
        members, setters, callbacks, restored, deleted, inherited, deletedSubclass, refreshesAfterAttach,
        [blocked, order, initialVisibility, pegged, syntheticResizeHidToggle, nativeResizeShowedToggle, removedResizeLeftToggleVisible],
        nativeEvents.map((event) => event.detail.displayWeight),
        legacyEvents.map(({ displayWeight }) => displayWeight),
        nativeEvents.every((event, index) => event === legacyEvents[index]?.event &&
          event.target === table && event.bubbles && event.cancelable),
      ];
      JS);

    $this->findWeightsToggle('Show row weights')->click();
    $this->findWeightsToggle('Hide row weights')->click();
    $this->assertSame(
      [
        TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, 1,
        [TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE],
        [TRUE, FALSE], [TRUE, FALSE], TRUE,
      ],
      $session->evaluateScript('window.readTableDrag()'),
    );
  }

  /**
   * Checks legacy gesture overrides at the real mouse and keyboard bindings.
   */
  #[IgnoreDeprecations]
  public function testLegacyGestures(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['legacy' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const drag = Drupal.tableDrag['tabledrag-test-table'];
      window.gestureEvents = [];
      for (const method of ['dragStart', 'dragRow', 'dropRow']) {
        const original = drag[method];
        const callback = function (event, ...args) {
          if (method === 'dragStart' || drag.dragObject || drag.rowObject) {
            window.gestureEvents.push([method, event.type, event instanceof jQuery.Event]);
          }
          return original.call(this, event, ...args);
        };
        callback.legacyTableEvent = true;
        drag[method] = callback;
      }
      JS);
    $first = $this->findRowById(1)->find('css', '.tabledrag-handle');
    $second = $this->findRowById(2)->find('css', '.tabledrag-handle');
    $first->dragTo($second);
    $this->assertOrder(['Row with id 2', 'Row with id 1', 'Row with id 3']);
    $this->assertTrue($session->evaluateScript(<<<'JS'
      ['dragStart', 'dragRow', 'dropRow'].every((method) =>
        window.gestureEvents.some(([name, type, legacy]) =>
          method === name && legacy && /^(pointer|mouse)/.test(type)))
      JS));

    $second->focus();
    $session->executeScript(<<<'JS'
      jQuery(document.activeElement).trigger(jQuery.Event('keydown', { keyCode: 40 }));
      JS);
    $second->blur();
    $this->assertOrder(['Row with id 1', 'Row with id 2', 'Row with id 3']);
    $this->assertTrue($session->evaluateScript(<<<'JS'
      window.gestureEvents.some(([name, type, legacy]) =>
        name === 'dropRow' && type === 'blur' && legacy)
      JS));

    $this->assertSame([TRUE, TRUE], $session->evaluateScript(<<<'JS'
      (() => {
        const drag = Drupal.tableDrag['tabledrag-test-table'];
        const [first, second] = drag.table.tBodies[0].rows;
        const handle = first.querySelector('.tabledrag-handle');
        const x = handle.getBoundingClientRect().left + window.scrollX + 2;
        const event = (type, row) => jQuery.Event(type, {
          pageX: x, pageY: row.getBoundingClientRect().top + window.scrollY + 2,
        });
        jQuery(handle).triggerHandler(event('mousedown', first));
        jQuery(document).triggerHandler(event('mousemove', second));
        jQuery(document).triggerHandler(event('mouseup', second));
        return [drag.table.tBodies[0].rows[0] === second, drag.dragObject === null && drag.rowObject === null];
      })()
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
   * Tests select-all markup and synthetic checkbox activation on both themes.
   */
  #[IgnoreDeprecations]
  public function testLegacyTableSelect(): void {
    $this->drupalGet('tabledrag_test', ['query' => ['legacy' => 1, 'tableselect' => 1]]);
    $this->assertSame([TRUE, TRUE, TRUE, TRUE], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const behavior = Drupal.behaviors.tableSelect;
        behavior.attach(null);
        behavior.attach(document.createTextNode(''));
        const test = (theme) => {
          Drupal.theme.checkbox = theme;
          const fixture = document.createElement('section');
          fixture.innerHTML = '<table><thead><tr><th class="select-all"></th><th class="select-all"></th></tr></thead>' +
            '<tbody><tr><td><input type="checkbox"></td></tr></tbody></table>';
          document.body.append(fixture);
          behavior.attach(jQuery(fixture));
          return fixture;
        };
        const input = document.createElement('input');
        input.type = 'checkbox';
        const native = test(() => input);
        const legacy = test(() => jQuery('<input type="checkbox">'));
        const headers = legacy.querySelectorAll('th input');
        const row = legacy.querySelector('tbody tr');
        row.querySelector('input').checked = true;
        const changes = [];
        legacy.addEventListener('change', (event) => changes.push(event.cancelable));
        jQuery(row.querySelector('input')).triggerHandler('click');
        const rowSynced = row.classList.contains('selected') && Array.from(headers).every((header) => header.checked);
        headers[0].checked = false;
        jQuery(headers[0]).triggerHandler('click');
        const headerSynced = !row.classList.contains('selected') &&
          !row.querySelector('input').checked && Array.from(headers).every((header) => !header.checked);
        return [
          native.querySelectorAll('th input').length === 2 && native.contains(input),
          headers.length === 2 && !legacy.textContent.includes('[object Object]'),
          rowSynced && headerSynced,
          changes.length >= 2 && changes.every((cancelable) => !cancelable),
        ];
      })()
      JS));
  }

  /**
   * Tests callbacks and bindings captured before loading the adapter.
   */
  #[IgnoreDeprecations]
  public function testLateTableBridge(): void {
    $this->openLateBridgeFixture();
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const drag = Drupal.tableDrag['tabledrag-test-table'];
      const pointer = new MouseEvent('mousemove');
      window.latePointer = pointer;
      drag.pointerCoords = (event) => event === pointer;
      const savedNative = drag.pointerCoords;
      const legacy = (event) => event instanceof jQuery.Event && savedNative.call(drag, event);
      legacy.legacyTableEvent = true;
      drag.pointerCoords = legacy;

      const responsive = document.createElement('table');
      responsive.classList.add('responsive-enabled');
      document.body.append(responsive);
      const prototype = Drupal.TableResponsive.prototype;
      const savedEvaluate = prototype.eventhandlerEvaluateColumnVisibility;
      const evaluate = function (event) {
        window.lateResize = event instanceof jQuery.Event && event.target === window;
        return savedEvaluate.call(this, event);
      };
      evaluate.legacyTableEvent = true;
      prototype.eventhandlerEvaluateColumnVisibility = evaluate;
      Drupal.behaviors.tableResponsive.attach(document);
      prototype.eventhandlerEvaluateColumnVisibility = savedEvaluate;
      JS);
    $this->loadLateBridge();
    $this->assertSame([TRUE, TRUE, TRUE], $session->evaluateScript(<<<'JS'
      (() => {
        const drag = Drupal.tableDrag['tabledrag-test-table'];
        jQuery(window).trigger('resize.tableresponsive');
        return [drag.$table[0] === drag.table, drag.pointerCoords(window.latePointer), window.lateResize];
      })()
      JS));
  }

  /**
   * Opens a fixture with the distribution present and the adapter deferred.
   */
  protected function openLateBridgeFixture(): void {
    $this->state->set('tabledrag_test.late_bridge', TRUE);
    $this->container->get('library.discovery')->clear();
    $this->drupalGet('tabledrag_test', ['query' => ['legacy' => 1, 'tableselect' => 1]]);
    $this->assertTrue($this->getSession()->evaluateScript(<<<'JS'
      typeof jQuery === 'function' && !('$table' in Drupal.tableDrag.prototype)
      JS));
  }

  /**
   * Loads the adapter after the fixture has bound its callbacks.
   */
  protected function loadLateBridge(): void {
    $this->getSession()->executeScript(<<<'JS'
      const script = document.createElement('script');
      script.src = Drupal.url('core/misc/jquery.tabledrag.bc.js');
      document.head.append(script);
      JS);
    $this->assertJsCondition(<<<'JS'
      '$table' in Drupal.tableDrag.prototype
      JS);
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
