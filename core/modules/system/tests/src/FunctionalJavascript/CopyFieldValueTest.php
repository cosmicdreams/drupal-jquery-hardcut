<?php

declare(strict_types=1);

namespace Drupal\Tests\system\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests copy field value functionality.
 *
 * @see Drupal.behaviors.copyFieldValue.
 */
#[Group('system')]
#[RunTestsInSeparateProcesses]
class CopyFieldValueTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * Tests copy field value JavaScript functionality.
   */
  public function testCopyFieldValue(): void {
    $this->drupalGet('/system-test/copy-field-value-test-form');
    $page = $this->getSession()->getPage();
    $source_field_selector = 'edit-source-field';
    $target_field = $page->find('css', '#edit-target-field');

    $this->getSession()->executeScript(<<<'JS'
      window.copiedValues = [];
      document.getElementById('edit-target-field').addEventListener('value:copy', (event) => {
        window.copiedValues.push(event.detail);
      });
      JS);

    $random_string = $this->randomString();
    // Ensure that after source field has been filled, target field is filled
    // with the same value.
    $page->fillField($source_field_selector, $random_string);
    $target_field->focus();
    $this->assertEquals($target_field->getValue(), $random_string);

    $this->assertSame([$random_string], $this->getSession()->evaluateScript('window.copiedValues'));

    // Ensure that the target value doesn't change after it has been focused.
    $page->fillField($source_field_selector, '');
    $target_field->focus();
    $this->assertEquals($target_field->getValue(), $random_string);
  }

}
