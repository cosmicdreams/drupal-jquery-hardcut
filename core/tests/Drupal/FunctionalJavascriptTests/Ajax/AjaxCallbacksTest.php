<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Ajax;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Ajax callbacks on FAPI elements.
 */
#[Group('Ajax')]
#[RunTestsInSeparateProcesses]
class AjaxCallbacksTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ajax_forms_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests if Ajax callback works on date element.
   */
  public function testDateAjaxCallback(): void {

    // Test Ajax callback when date changes.
    $this->drupalGet('ajax_forms_test_ajax_element_form');
    $this->assertNotEmpty($this->getSession()->getPage()->find('xpath', '//div[@id="ajax_date_value"][text()="No date yet selected"]'));
    $this->getSession()->executeScript(<<<'JS'
      const input = document.querySelector('[data-drupal-selector=edit-date]');
      input.value = '2016-01-01';
      input.dispatchEvent(new Event('change', { bubbles: true }));
      JS);
    $this->assertNotEmpty($this->assertSession()->waitForElement('xpath', '//div[@id="ajax_date_value"]/div[text()="2016-01-01"]'));
  }

  /**
   * Tests if Ajax callback works on datetime element.
   */
  public function testDateTimeAjaxCallback(): void {

    // Test Ajax callback when datetime changes.
    $this->drupalGet('ajax_forms_test_ajax_element_form');
    $this->assertNotEmpty($this->getSession()->getPage()->find('xpath', '//div[@id="ajax_datetime_value"][text()="No datetime selected."]'));
    $this->getSession()->executeScript(<<<'JS'
      document.querySelector('[data-drupal-selector=edit-datetime-date]').value = '2016-01-01';
      const time = document.querySelector('[data-drupal-selector=edit-datetime-time]');
      time.value = '12:00:00';
      time.dispatchEvent(new Event('change', { bubbles: true }));
      JS);
    $this->assertNotEmpty($this->assertSession()->waitForElement('xpath', '//div[@id="ajax_datetime_value"]/div[text()="2016-01-01 12:00:00"]'));
  }

}
