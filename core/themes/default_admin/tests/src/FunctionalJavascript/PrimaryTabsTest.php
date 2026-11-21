<?php

declare(strict_types=1);

namespace Drupal\Tests\default_admin\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the responsive primary tabs.
 */
#[Group('default_admin')]
#[RunTestsInSeparateProcesses]
class PrimaryTabsTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'default_admin';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block'];

  /**
   * Tests that the primary tab menu can be operated by keyboard on mobile.
   */
  public function testMobilePrimaryTabs(): void {
    $this->drupalPlaceBlock('local_tasks_block');
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);
    $session = $this->getSession();
    $session->resizeWindow(500, 900);
    $this->drupalGet('user/' . $account->id() . '/edit');

    $assert = $this->assertSession();
    $tabs_selector = '.tabs--primary';
    $button_selector = "$tabs_selector [data-drupal-nav-tabs-trigger]";
    $inactive_selector = "$tabs_selector .tabs__tab:not(.is-active)";
    $button = $assert->elementExists('css', $button_selector);
    $inactive = $assert->elementExists('css', $inactive_selector);
    $this->assertTrue($button->isVisible());
    $this->assertSame('false', $button->getAttribute('aria-expanded'));
    $this->assertFalse($inactive->isVisible());

    $session->executeScript("document.querySelector('$button_selector').focus()");
    $this->pressEnter();
    $this->assertSame('true', $button->getAttribute('aria-expanded'));
    $this->assertNotNull($assert->waitForElementVisible('css', $inactive_selector));

    $this->pressEnter();
    $this->assertSame('false', $button->getAttribute('aria-expanded'));
    $this->assertJsCondition("document.querySelector('$inactive_selector').getClientRects().length === 0");

    $session->resizeWindow(1024, 900);
    $this->assertNotNull($assert->waitForElementVisible('css', $inactive_selector));
    $this->assertFalse($button->isVisible());
    $this->assertNull($button->getAttribute('aria-expanded'));
  }

  /**
   * Activates the focused button using the browser keyboard input source.
   */
  private function pressEnter(): void {
    $this->getSession()->getDriver()->getWebDriverSession()->postActions([
      'actions' => [
        [
          'type' => 'key',
          'id' => 'keyboard',
          'actions' => [
            ['type' => 'keyDown', 'value' => "\u{E007}"],
            ['type' => 'keyUp', 'value' => "\u{E007}"],
          ],
        ],
      ],
    ]);
  }

}
