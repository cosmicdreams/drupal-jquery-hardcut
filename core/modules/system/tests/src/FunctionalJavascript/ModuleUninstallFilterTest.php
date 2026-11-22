<?php

declare(strict_types=1);

namespace Drupal\Tests\system\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the JavaScript functionality of the module uninstall filter.
 */
#[Group('system')]
#[RunTestsInSeparateProcesses]
class ModuleUninstallFilterTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user', 'system', 'page_cache', 'dynamic_page_cache'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $admin_user = $this->drupalCreateUser([
      'administer modules',
    ]);
    $this->drupalLogin($admin_user);
  }

  /**
   * Tests that correct module count is returned when list filtered.
   */
  public function testModuleUninstallFilter(): void {

    // Find the module filter field.
    $this->drupalGet('admin/modules/uninstall');
    $assertSession = $this->assertSession();
    $session = $this->getSession();
    $page = $session->getPage();

    $filter = $page->findField('edit-text');

    // Get all module rows, for assertions later.
    $module_rows = $page->findAll('css', '.module-name');

    // Test module filter reduces the number of visible rows.
    $filter->setValue('dynamic');
    $this->assertJsCondition('!document.getElementById("edit-uninstall-page-cache")?.getClientRects().length');
    $visible_rows = $this->filterVisibleElements($module_rows);
    $this->assertNotEquals(count($module_rows), count($visible_rows));

    // Test Drupal.announce() message when multiple matches are expected.
    $filter->setValue('cache');
    $this->assertJsCondition('document.getElementById("drupal-live-announce").textContent.includes("modules are available")');
    $visible_rows = $this->filterVisibleElements($module_rows);
    $expected_message = count($visible_rows) . ' modules are available in the modified list.';
    $assertSession->elementTextContains('css', '#drupal-live-announce', $expected_message);

    // Test Drupal.announce() message when only one match is expected.
    // Using a very specific module name, we expect only one row.
    $filter->setValue('dynamic page cache');
    $this->assertJsCondition('document.getElementById("drupal-live-announce").textContent.includes("module is available")');
    $visible_rows = $this->filterVisibleElements($module_rows);
    $this->assertEquals(1, count($visible_rows));
    $expected_message = '1 module is available in the modified list.';
    $assertSession->elementTextContains('css', '#drupal-live-announce', $expected_message);

    // Test Drupal.announce() message when no matches are expected.
    $filter->setValue('Pan-Galactic Gargle Blaster');
    $this->assertJsCondition('document.getElementById("drupal-live-announce").textContent.includes("0 modules are available")');
    $visible_rows = $this->filterVisibleElements($module_rows);
    $this->assertEquals(0, count($visible_rows));
    $expected_message = '0 modules are available in the modified list.';
    $assertSession->elementTextContains('css', '#drupal-live-announce', $expected_message);
  }

  /**
   * Removes any non-visible elements from the passed array.
   *
   * @param \Behat\Mink\Element\NodeElement[] $elements
   *   An array of node elements.
   *
   * @return \Behat\Mink\Element\NodeElement[]
   *   An array of node elements that are visible.
   */
  protected function filterVisibleElements($elements): array {
    return array_filter($elements, function (NodeElement $element) {
      return $element->isVisible();
    });
  }

}
