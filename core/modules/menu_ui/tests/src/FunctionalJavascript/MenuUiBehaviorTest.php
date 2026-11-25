<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_ui\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests menu parent updates and automatic menu link titles.
 */
#[Group('menu_ui')]
#[RunTestsInSeparateProcesses]
class MenuUiBehaviorTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['menu_ui', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the available menus update the parent link options.
   */
  public function testParentOptions(): void {
    NodeType::create(['type' => 'example', 'name' => 'Example'])->save();
    $this->drupalLogin($this->drupalCreateUser(['administer content types', 'administer menu']));
    $this->drupalGet('admin/structure/types/manage/example');

    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->assertNotNull($assert->waitForElement('css', '#edit-menu-parent option[value="main:"]:only-child'));
    $assert->elementExists('named', ['link', 'Menu settings'])->click();
    $page->checkField('menu_options[tools]');
    $this->assertNotNull($assert->waitForElement('css', '#edit-menu-parent option[value="tools:"]'));
    $assert->fieldValueEquals('Default parent link', 'main:');

    $page->uncheckField('menu_options[main]');
    $this->assertJsCondition("!document.querySelector('#edit-menu-parent option[value=\"main:\"]')");
    $page->uncheckField('menu_options[tools]');
    $this->assertJsCondition("document.getElementById('edit-menu-parent').closest('div').hidden");
  }

  /**
   * Tests that editing the menu title stops automatic title updates.
   */
  public function testAutomaticMenuTitle(): void {
    NodeType::create(['type' => 'example', 'name' => 'Example'])->save();
    $this->drupalLogin($this->drupalCreateUser(['create example content', 'administer menu']));
    $this->drupalGet('node/add/example');

    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $page->fillField('Title', 'First title');
    $assert->elementExists('named', ['link', 'Menu settings'])->click();
    $page->checkField('Provide a menu link');
    $assert->fieldValueEquals('Menu link title', 'First title');

    $page->fillField('Title', 'Second title');
    $assert->fieldValueEquals('Menu link title', 'Second title');
    $page->fillField('Menu link title', 'Custom title');
    $page->fillField('Title', 'Third title');
    $assert->fieldValueEquals('Menu link title', 'Custom title');
  }

}
