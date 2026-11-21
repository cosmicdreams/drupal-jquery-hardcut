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
  protected static $modules = ['form_test', 'locale', 'content_translation'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests translation markers, description toggles and filename detection.
   */
  public function testLocaleBehaviors(): void {
    $this->drupalGet('form-test/admin-behaviors');
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
    $assert = $this->assertSession();
    $empty = $assert->elementExists('css', '.js-text-format-wrapper:has(#empty-summary)');
    $wrapper = $assert->elementExists('css', '.js-text-summary-wrapper', $empty);
    $this->assertFalse($wrapper->isVisible());
    $fullLabel = $assert->elementExists('css', 'label[for="full-text"]', $empty);
    $this->assertSame('Full text', $assert->elementExists('css', '.visually-hidden', $fullLabel)->getHtml());
    $fullLabel->pressButton('Edit summary');
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
   * Tests that mixed pages pass collections to legacy translation overrides.
   */
  public function testLegacyDependentTranslationOptions(): void {
    $this->drupalGet('form-test/admin-behaviors');
    $this->getSession()->executeScript(<<<'JS'
      const behavior = Drupal.behaviors.contentTranslationDependentOptions;
      const original = behavior.check;
      behavior.check = function (fields, columns, changed) {
        document.getElementById('translation-columns').dataset.legacyCheck =
          [fields.length, fields instanceof jQuery, changed instanceof jQuery].join(',');
        return original.call(this, fields, columns, changed);
      };
      const body = document.querySelector('#translation-columns [value="body"]');
      const deprecation = Drupal.deprecationError;
      const warnings = [];
      try {
        Drupal.deprecationError = ({ message }) => warnings.push(message);
        body.checked = true;
        jQuery(body).trigger('change');
      }
      finally {
        Drupal.deprecationError = deprecation;
      }
      document.getElementById('translation-columns').dataset.warnings = warnings.join(' ');
      JS);
    $assert = $this->assertSession();
    $assert->elementAttributeContains('css', '#translation-columns', 'data-legacy-check', '2,true,true');
    $assert->elementAttributeContains('css', '#translation-columns', 'data-warnings', 'Drupal.behaviors.contentTranslationDependentOptions.check is deprecated in drupal:12.0.0');
    $format = $assert->elementExists('css', '#translation-columns [value="format"]');
    $this->assertTrue($format->isChecked());
    $this->assertTrue($format->hasAttribute('disabled'));
  }

}
