<?php

declare(strict_types=1);

namespace Drupal\Tests\system\FunctionalJavascript\Form;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the tableselect form element for expected behavior.
 */
#[Group('Form')]
#[RunTestsInSeparateProcesses]
class ElementsTableSelectTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['form_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the presence of ajax functionality for all options.
   */
  public function testAjax(): void {
    // Test checkboxes (#multiple == TRUE).
    $this->drupalGet('form_test/tableselect/multiple-true');
    $session = $this->getSession();
    $page = $session->getPage();
    for ($i = 1; $i <= 3; $i++) {
      $row = 'row' . $i;
      $this->assertTrue($page->hasUncheckedField($row));
      $page->checkField($row);
      $this->assertSession()->assertWaitOnAjaxRequest();
      // Check current row and previous rows are checked.
      for ($j = 1; $j <= $i; $j++) {
        $other_row = 'row' . $j;
        $this->assertTrue($page->hasCheckedField($other_row));
      }
    }

    // Test radios (#multiple == FALSE). A rebuild can assign new element IDs.
    $this->drupalGet('form_test/tableselect/multiple-false');
    $assert = $this->assertSession();
    for ($i = 1; $i <= 3; $i++) {
      $row = 'input[type="radio"][value="row' . $i . '"]';
      $this->assertFalse($assert->elementExists('css', $row)->isChecked());
      $this->click($row);
      $assert->assertWaitOnAjaxRequest();
      $this->assertTrue($assert->elementExists('css', $row)->isChecked());
      // Check other rows are unchecked.
      for ($j = 1; $j <= 3; $j++) {
        if ($j === $i) {
          continue;
        }
        $other_row = 'input[type="radio"][value="row' . $j . '"]';
        $this->assertFalse($assert->elementExists('css', $other_row)->isChecked());
      }
    }
  }

  /**
   * Tests that a selection range updates rows without emitting change events.
   */
  public function testSelectionRange(): void {
    $this->drupalGet('form_test/tableselect/disabled-rows/multiple-true');
    $trace = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const checkboxes = ['row1', 'row2', 'row3'].map((value) =>
          document.querySelector(`input[type="checkbox"][value="${value}"]`),
        );
        const rows = checkboxes.map((checkbox) => checkbox.closest('tr'));
        let changes = 0;
        rows.forEach((row) => row.addEventListener('change', () => { changes += 1; }));
        const read = () => checkboxes.map((checkbox, index) => [
          checkbox.checked, checkbox.disabled, rows[index].classList.contains('selected'),
        ]);
        Drupal.tableSelectRange(rows[0], rows[2], true);
        const forward = read();
        Drupal.tableSelectRange(rows[2], rows[0], false);
        return [forward, read(), changes];
      })()
      JS);
    $this->assertSame([
      [[FALSE, FALSE, FALSE], [TRUE, TRUE, TRUE], [TRUE, FALSE, TRUE]],
      [[FALSE, FALSE, FALSE], [FALSE, TRUE, FALSE], [TRUE, FALSE, TRUE]],
      0,
    ], $trace);
  }

  /**
   * Tests table select with disabled rows.
   */
  public function testDisabledRows(): void {
    // Asserts that a row number (1 based) is enabled.
    $assert_row_enabled = function (int $delta): void {
      $delta = (string) $delta;
      $row = $this->assertSession()->elementExists('xpath', "//table/tbody/tr[$delta]");
      $this->assertFalse($row->hasClass('disabled'));
      $input = $row->find('css', 'input[value="row' . $delta . '"]');
      $this->assertFalse($input->hasAttribute('disabled'));
    };
    // Asserts that a row number (1 based) is disabled.
    $assert_row_disabled = function (int $delta): void {
      $delta = (string) $delta;
      $row = $this->assertSession()->elementExists('xpath', "//table/tbody/tr[$delta]");
      $this->assertTrue($row->hasClass('disabled'));
      $input = $row->find('css', 'input[value="row' . $delta . '"]');
      $this->assertTrue($input->hasAttribute('disabled'));
      $this->assertEquals('disabled', $input->getAttribute('disabled'));
    };

    // Test radios (#multiple == FALSE).
    $this->drupalGet('form_test/tableselect/disabled-rows/multiple-false');

    // Check that only 'row2' is disabled.
    $assert_row_enabled(1);
    $assert_row_disabled(2);
    $assert_row_enabled(3);

    // Test checkboxes (#multiple == TRUE).
    $this->drupalGet('form_test/tableselect/disabled-rows/multiple-true');

    // Check that only 'row2' is disabled.
    $assert_row_enabled(1);
    $assert_row_disabled(2);
    $assert_row_enabled(3);

    // A select-all interaction delivers native changes for enabled rows.
    $this->getSession()->executeScript(<<<'JS'
      window.rowChanges = [];
      document.querySelector('table tbody').addEventListener('change', (event) => {
        window.rowChanges.push([event.target.value, event.target.checked]);
      });
      JS);

    // Table select with checkboxes allows selection of all enabled options.
    $select_all_checkbox = $this->assertSession()->elementExists('xpath', '//table/thead/tr/th/input');
    $select_all_checkbox->check();

    // Check that the disabled option was not enabled or selected.
    $page = $this->getSession()->getPage();
    $this->assertTrue($page->hasCheckedField('row1'));
    $this->assertTrue($page->hasUncheckedField('row2'));
    $assert_row_disabled(2);
    $this->assertTrue($page->hasCheckedField('row3'));
    $this->assertSame(
      [['row1', TRUE], ['row3', TRUE]],
      $this->getSession()->evaluateScript('window.rowChanges'),
    );
  }

}
