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

  /**
   * Tests that a synthetic blur leaves later native blurs to core alone.
   */
  public function testSyntheticBlurReleasesJquery(): void {
    $this->drupalGet('/system-test/copy-field-value-test-form');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $scripts = json_encode(array_column(
      \Drupal::service('library.discovery')->getLibraryByName('core', 'jquery')['js'],
      'data',
    ));
    $session->executeScript(<<<JS
      (async () => {
        for (const path of $scripts) {
          await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = drupalSettings.path.baseUrl + path;
            script.onload = resolve;
            script.onerror = reject;
            document.head.append(script);
          });
        }
        const source = document.getElementById('edit-source-field');
        const target = document.getElementById('edit-target-field');
        jQuery(source).val('synthetic').trigger('blur');
        const synthetic = target.value;
        target.value = '';
        source.value = 'native';
        const behavior = Drupal.behaviors.copyFieldValue;
        const blur = behavior.valueSourceBlurHandlerNative;
        let calls = 0;
        behavior.valueSourceBlurHandlerNative = function (...args) {
          calls += 1;
          return blur.apply(this, args);
        };
        const dispatch = jQuery.event.dispatch;
        let dispatchCalls = 0;
        jQuery.event.dispatch = function (...args) {
          dispatchCalls += 1;
          return dispatch.apply(this, args);
        };
        try {
          source.dispatchEvent(new FocusEvent('blur'));
        }
        finally {
          behavior.valueSourceBlurHandlerNative = blur;
          jQuery.event.dispatch = dispatch;
        }
        window.syntheticBlurResult = [synthetic, target.value, calls, dispatchCalls];
      })();
      JS);
    $this->assertJsCondition('Array.isArray(window.syntheticBlurResult)');
    $this->assertSame(['synthetic', 'native', 1, 0], $session->evaluateScript('window.syntheticBlurResult'));
  }

}
