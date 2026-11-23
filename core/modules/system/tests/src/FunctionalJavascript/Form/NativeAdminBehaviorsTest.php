<?php

declare(strict_types=1);

namespace Drupal\Tests\system\FunctionalJavascript\Form;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests locale and text form behaviors.
 */
#[Group('Form')]
#[RunTestsInSeparateProcesses]
class NativeAdminBehaviorsTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['form_test', 'locale', 'content_translation', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests translation markers, description toggles and filename detection.
   */
  public function testLocaleBehaviors(): void {
    $this->drupalGet('form-test/admin-behaviors');
    $this->assertSame('undefined', $this->getSession()->evaluateScript('typeof window.jQuery'));
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $edit = '#locale-translate-edit-form';
    $assert->elementNotExists('css', "$edit .messages--warning");

    $page->fillField('first-translation', 'First');
    $this->assertNotNull($assert->waitForElementVisible('css', "$edit table.changed tr.changed .ajax-changed"));
    $page->fillField('first-translation', 'First again');
    $page->fillField('second-translation', 'Second');
    $this->assertNotNull($assert->waitForElementVisible('css', "$edit tr:nth-child(2).changed .ajax-changed"));
    $assert->elementsCount('css', "$edit .messages--warning", 1);
    $assert->elementsCount('css', "$edit tr.changed .ajax-changed", 2);

    $description = '#locale-translation-status-form .description';
    $this->getSession()->executeScript(<<<'JS'
      const description = document.querySelector('#locale-translation-status-form .description');
      description.dispatchEvent(new KeyboardEvent('keydown', {bubbles: true, keyCode: 40}));
      JS);
    $assert->elementNotExists('css', '#locale-translation-status-form tr.expanded');
    $this->getSession()->executeScript(<<<'JS'
      const description = document.querySelector('#locale-translation-status-form .description');
      description.dispatchEvent(new KeyboardEvent('keydown', {bubbles: true, keyCode: 13}));
      JS);
    $assert->elementTextEquals('css', $description, 'Hide description');
    $assert->elementExists('css', '#locale-translation-status-form tr.expanded');
    $assert->elementExists('css', $description)->click();
    $assert->elementTextEquals('css', $description, 'Show description');

    $this->getSession()->executeScript(<<<'JS'
      const input = document.querySelector('.file-import-input');
      const files = new DataTransfer();
      files.items.add(new File([''], 'example.fr.po'));
      input.files = files.files;
      input.dispatchEvent(new Event('change', {bubbles: true}));
      JS);
    $this->assertSame('fr', $assert->elementExists('css', '.langcode-input')->getValue());
  }

  /**
   * Tests empty and populated summary toggles with optional full text labels.
   */
  public function testTextSummaries(): void {
    $this->drupalGet('form-test/admin-behaviors');
    $this->assertSame('undefined', $this->getSession()->evaluateScript('typeof window.jQuery'));
    $assert = $this->assertSession();
    $empty = $assert->elementExists('css', '.js-text-format-wrapper:has(#empty-summary)');
    $wrapper = $assert->elementExists('css', '.js-text-summary-wrapper', $empty);
    $this->assertFalse($wrapper->isVisible());
    $fullLabel = $assert->elementExists('css', 'label[for="full-text"]', $empty);
    $this->assertSame('Full text', $assert->elementExists('css', '.visually-hidden', $fullLabel)->getHtml());
    // Verify text.js raw addEventListener path prevents default and toggles.
    $prevented = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const link = document.querySelector('.js-text-format-wrapper:has(#empty-summary) .field-edit-link');
        const click = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(click);
        return click.defaultPrevented;
      })()
      JS);
    $this->assertTrue($prevented);
    $this->assertTrue($wrapper->isVisible());
    $wrapper->pressButton('Hide summary');
    $this->assertFalse($wrapper->isVisible());

    $populated = $assert->elementExists('css', '.js-text-format-wrapper:has(#existing-summary)');
    $wrapper = $assert->elementExists('css', '.js-text-summary-wrapper', $populated);
    $this->assertTrue($wrapper->isVisible());
    $wrapper->pressButton('Hide summary');
    $this->assertFalse($wrapper->isVisible());
    $fullLabel = $assert->elementExists('css', '.js-form-type-textarea > label', $populated);
    $fullLabel->pressButton('Edit summary');
    $this->assertTrue($wrapper->isVisible());
    $this->assertSame('Existing summary', $wrapper->findField('existing-summary')->getValue());
    $this->getSession()->executeScript('Drupal.attachBehaviors();');
    $assert->elementsCount('css', '.link-edit-summary', 2);

  }

  /**
   * Tests that synthetic preview clicks preserve alt/ctrl/meta/shift modifiers.
   */
  public function testPreviewClickModifiers(): void {
    $this->drupalGet('form-test/admin-behaviors', ['query' => ['preview' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const link = document.getElementById('preview-leave-link');
        const originalDialog = Drupal.dialog;
        let opened = 0;
        Drupal.dialog = () => ({ showModal() { opened += 1; }, close() {} });
        try {
          const modifierResults = ['altKey', 'ctrlKey', 'metaKey', 'shiftKey'].map((modifier) => {
            const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, [modifier]: true });
            link.dispatchEvent(event);
            return [modifier, event.defaultPrevented, opened];
          });
          const plain = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
          link.dispatchEvent(plain);
          return [modifierResults, plain.defaultPrevented, opened];
        } finally {
          Drupal.dialog = originalDialog;
        }
      })()
      JS);
    $this->assertSame([
      [
        ['altKey', FALSE, 0],
        ['ctrlKey', FALSE, 0],
        ['metaKey', FALSE, 0],
        ['shiftKey', FALSE, 0],
      ],
      TRUE,
      1,
    ], $result);
  }

}
