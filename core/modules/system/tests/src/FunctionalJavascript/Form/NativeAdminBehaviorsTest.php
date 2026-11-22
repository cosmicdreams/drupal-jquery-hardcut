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
   * Tests filename detection from a legacy synthetic change event.
   */
  public function testLegacyLocaleFilenameDetection(): void {
    $this->drupalGet('form-test/admin-behaviors', ['query' => ['legacy' => 1]]);
    $warnings = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const input = document.querySelector('.file-import-input');
        const files = new DataTransfer();
        files.items.add(new File([''], 'example.fr.po'));
        input.files = files.files;
        const deprecation = Drupal.deprecationError;
        const seen = [];
        try {
          Drupal.deprecationError = ({ message }) => seen.push(message);
          jQuery(input).trigger('change');
          return seen;
        }
        finally {
          Drupal.deprecationError = deprecation;
        }
      })()
      JS);
    $language = $this->assertSession()->elementExists('css', '.langcode-input');
    $this->assertSame('fr', $language->getValue());
    $this->assertSame([], $warnings);
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

    // Mixed page: triggerHandler() and trigger() each toggle the summary once.
    $this->drupalGet('form-test/admin-behaviors', ['query' => ['legacy' => 1]]);
    $wrapper = $assert->elementExists('css', '.js-text-format-wrapper:has(#empty-summary) .js-text-summary-wrapper');
    $this->getSession()->executeScript("jQuery('.js-text-format-wrapper:has(#empty-summary) .field-edit-link').triggerHandler('click')");
    $this->assertTrue($wrapper->isVisible());
    $this->getSession()->executeScript("jQuery('.js-text-format-wrapper:has(#empty-summary) .field-edit-link').trigger('click')");
    $this->assertFalse($wrapper->isVisible());
  }

  /**
   * Tests that mixed pages pass collections to legacy translation overrides.
   */
  public function testLegacyDependentTranslationOptions(): void {
    $this->drupalGet('form-test/admin-behaviors', ['query' => ['legacy' => 1]]);
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
      document.getElementById('translation-columns').dataset.warnings = JSON.stringify(warnings);
      JS);
    $assert = $this->assertSession();
    $assert->elementAttributeContains('css', '#translation-columns', 'data-legacy-check', '2,true,true');
    $warnings = json_decode($assert->elementExists('css', '#translation-columns')->getAttribute('data-warnings'), TRUE);
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('Drupal.behaviors.contentTranslationDependentOptions.check is deprecated in drupal:12.0.0', $warnings[0]);
    $format = $assert->elementExists('css', '#translation-columns [value="format"]');
    $this->assertTrue($format->isChecked());
    $this->assertTrue($format->hasAttribute('disabled'));
  }

  /**
   * Tests that synthetic preview clicks preserve alt/ctrl/meta/shift modifiers.
   */
  public function testPreviewClickModifiers(): void {
    $this->drupalGet('form-test/admin-behaviors', ['query' => ['legacy' => 1, 'preview' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const link = document.getElementById('preview-leave-link');
        const originalDialog = Drupal.dialog;
        let opened = 0;
        Drupal.dialog = () => ({ showModal() { opened += 1; }, close() {} });
        try {
          const modifierResults = ['altKey', 'ctrlKey', 'metaKey', 'shiftKey'].map((modifier) => {
            const event = jQuery.Event('click', { button: 0, [modifier]: true });
            jQuery(link).trigger(event);
            return [modifier, event.isDefaultPrevented(), opened];
          });
          const plain = jQuery.Event('click', { button: 0 });
          jQuery(link).trigger(plain);
          return [modifierResults, plain.isDefaultPrevented(), opened];
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

  /**
   * Tests legacy copyFieldValue overrides, arguments, return values, and deprecations.
   */
  public function testLegacyCopyFieldValueOverrides(): void {
    $this->drupalGet('form-test/admin-behaviors', ['query' => ['legacy' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const behavior = Drupal.behaviors.copyFieldValue;
        const originalBlur = behavior.valueSourceBlurHandler;
        const originalCopy = behavior.valueTargetCopyHandler;
        const deprecation = Drupal.deprecationError;
        const warnings = [];
        const calls = [];
        Drupal.deprecationError = ({ message }) => warnings.push(message);
        behavior.valueSourceBlurHandler = function (event) {
          calls.push(['blur', event instanceof jQuery.Event, this.id]);
          return originalBlur.call(this, event);
        };
        behavior.valueTargetCopyHandler = function (event, value) {
          calls.push(['copy', event instanceof jQuery.Event, event.target.id, value]);
          originalCopy.call(this, event, value);
          return false;
        };
        const source = document.getElementById('copy-source-field');
        const target = document.getElementById('copy-target-field');
        try {
          // Detach and reattach so overrides are bound, then test both events.
          source.value = 'Copied text';
          jQuery(source).trigger('blur');
          const afterBlur = target.value;

          target.value = '';
          const syntheticCopy = jQuery.Event('value:copy');
          jQuery(target).trigger(syntheticCopy, ['Direct legacy']);
          return {
            calls,
            afterBlur,
            afterSynthetic: target.value,
            syntheticReturn: [syntheticCopy.result, syntheticCopy.isDefaultPrevented(), syntheticCopy.isPropagationStopped()],
            warnings,
          };
        } finally {
          behavior.valueSourceBlurHandler = originalBlur;
          behavior.valueTargetCopyHandler = originalCopy;
          Drupal.deprecationError = deprecation;
        }
      })()
      JS);
    $this->assertSame([
      ['blur', TRUE, 'copy-source-field'],
      ['copy', TRUE, 'copy-target-field', 'Copied text'],
      ['copy', TRUE, 'copy-target-field', 'Direct legacy'],
    ], $actual['calls']);
    $this->assertSame('Copied text', $actual['afterBlur']);
    $this->assertSame('Direct legacy', $actual['afterSynthetic']);
    $this->assertSame([FALSE, TRUE, TRUE], $actual['syntheticReturn']);
    $this->assertContains(
      'The jQuery synthetic trigger for value:copy is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the same native event name and its documented payload. See https://www.drupal.org/node/CHANGE_RECORD.',
      $actual['warnings'],
    );
    $this->assertFalse(
      array_any($actual['warnings'], static fn (string $msg): bool => str_contains($msg, 'synthetic trigger for blur')),
    );
  }

}
