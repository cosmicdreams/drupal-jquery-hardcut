<?php

declare(strict_types=1);

namespace Drupal\Tests\system\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the off-canvas dialog functionality.
 */
#[Group('system')]
#[RunTestsInSeparateProcesses]
class OffCanvasTest extends OffCanvasTestBase {

  /**
   * Stores to the class that should be on the last dialog.
   *
   * @var string
   *
   * @see \Drupal\off_canvas_test\Controller\TestController::linksDisplay.
   */
  protected $lastDialogClass;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'off_canvas_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that non-contextual links will work with the off-canvas dialog.
   */
  #[DataProvider('themeDataProvider')]
  public function testOffCanvasLinks($theme): void {
    $this->enableTheme($theme);
    $this->drupalGet('/off-canvas-test-links');

    $page = $this->getSession()->getPage();
    $web_assert = $this->assertSession();

    // Confirm touchevents detection is loaded with Off Canvas assets.
    $this->assertNotNull($web_assert->waitForElement('css', 'html.no-touchevents'));

    // Make sure off-canvas dialog is on page when first loaded.
    $web_assert->elementNotExists('css', '#drupal-off-canvas');

    // Check opening and closing with two separate links.
    // Make sure tray updates to new content.
    // Check the first link again to make sure the empty title class is
    // removed.
    foreach ([1, 2, 1] as $link_index) {
      $this->assertOffCanvasDialog($link_index, 'side');
      $header_text = $this->getOffCanvasDialog()->find('css', '.drupal-dialog-title')->getText();
      if ($link_index === 2) {
        // Check no title behavior.
        $web_assert->elementExists('css', '.drupal-dialog-empty-title');
        $web_assert->elementExists('css', '.drupal-dialog-title[hidden]');

        $style = $page->find('css', '.drupal-dialog-off-canvas')->getAttribute('style');
        $this->assertStringContainsString('width: 555px;', $style, 'Dialog width respected.');
        $page->clickLink("Open side panel 1");
        $this->waitForOffCanvasToOpen();
        $style = $page->find('css', '.drupal-dialog-off-canvas')->getAttribute('style');
        $this->assertStringNotContainsString('width: 555px;', $style, 'Dialog width reset to default.');
      }
      else {
        // Check that header is correct.
        $this->assertEquals("Thing $link_index", $header_text);
        $web_assert->elementNotExists('css', '.drupal-dialog-empty-title');
      }
    }

    // Test the off_canvas_top tray.
    foreach ([1, 2] as $link_index) {
      $this->assertOffCanvasDialog($link_index, 'top');

      if ($link_index === 2) {
        $style = $page->find('css', '.drupal-dialog-off-canvas')->getAttribute('style');
        $this->assertStringContainsString('height: 421px;', $style);
      }
    }

    // Ensure an off-canvas link opened from inside the off-canvas dialog will
    // work.
    $this->drupalGet('/off-canvas-test-links');
    $page->clickLink('Display more links!');
    $this->waitForOffCanvasToOpen();
    $web_assert->linkExists('Off_canvas link!');
    // Click off-canvas link inside off-canvas dialog.
    $page->clickLink('Off_canvas link!');
    $this->waitForOffCanvasToOpen();
    $web_assert->elementTextContains('css', '#drupal-off-canvas-wrapper', 'Thing 2 says hello');

    // Ensure an off-canvas link opened from inside the off-canvas dialog will
    // work after another dialog has been opened.
    $this->drupalGet('/off-canvas-test-links');
    $page->clickLink("Open side panel 1");
    $this->waitForOffCanvasToOpen();
    $page->clickLink('Display more links!');
    $this->waitForOffCanvasToOpen();
    $web_assert->linkExists('Off_canvas link!');
    // Click off-canvas link inside off-canvas dialog.
    $page->clickLink('Off_canvas link!');
    $this->waitForOffCanvasToOpen();
    $web_assert->elementTextContains('css', '#drupal-off-canvas-wrapper', 'Thing 2 says hello');
  }

  /**
   * Tests keyboard focus wrapping in the non-modal off-canvas tray.
   */
  public function testFocusWrap(): void {
    $this->drupalGet('/off-canvas-test-links');
    $this->clickLink('Display more links!');
    $this->waitForOffCanvasToOpen();
    $this->assertJsCondition('document.querySelector("dialog#drupal-off-canvas-wrapper[open]:not(:modal)") !== null');

    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(DrupalSelenium2Driver::class, $driver);
    $webdriver = $driver->getWebDriverSession();
    $keyboard = ['type' => 'key', 'id' => 'keyboard'];

    $this->getSession()->executeScript('document.querySelector("#drupal-off-canvas-wrapper .drupal-dialog-resize").focus()');
    $this->assertJsCondition('document.activeElement.matches("#drupal-off-canvas-wrapper .drupal-dialog-resize")');
    $keyboard['actions'] = [
      ['type' => 'keyDown', 'value' => "\u{E004}"],
      ['type' => 'keyUp', 'value' => "\u{E004}"],
    ];
    $webdriver->postActions(['actions' => [$keyboard]]);
    $this->assertJsCondition('document.activeElement.matches("#drupal-off-canvas-wrapper .drupal-dialog-titlebar-close")');

    $keyboard['actions'] = [
      ['type' => 'keyDown', 'value' => "\u{E008}"],
      ['type' => 'keyDown', 'value' => "\u{E004}"],
      ['type' => 'keyUp', 'value' => "\u{E004}"],
      ['type' => 'keyUp', 'value' => "\u{E008}"],
    ];
    $webdriver->postActions(['actions' => [$keyboard]]);
    $this->assertJsCondition('document.activeElement.matches("#drupal-off-canvas-wrapper .drupal-dialog-resize")');

    // Escape closes the tray and returns focus to its trigger.
    $keyboard['actions'] = [
      ['type' => 'keyDown', 'value' => "\u{E00C}"],
      ['type' => 'keyUp', 'value' => "\u{E00C}"],
    ];
    $webdriver->postActions(['actions' => [$keyboard]]);
    $this->waitForOffCanvasToClose();
    $this->assertJsCondition('document.activeElement.textContent === "Display more links!"');
  }

  /**
   * Tests the body displacement behaves differently at a narrow width.
   */
  public function testNarrowWidth(): void {
    $narrow_width_breakpoint = 768;
    $offset = 20;
    $height = 800;
    $page = $this->getSession()->getPage();
    $web_assert = $this->assertSession();

    // Test the same functionality on multiple themes.
    foreach (static::getTestThemes() as $theme) {
      $this->enableTheme($theme);
      // Testing at the wider width.
      $this->getSession()->resizeWindow($narrow_width_breakpoint + $offset, $height);
      $this->drupalGet('/off-canvas-test-links');
      $this->assertFalse($page->find('css', '.dialog-off-canvas-main-canvas')->hasAttribute('style'), 'Body not padded on wide page load.');
      $page->clickLink("Open side panel 1");
      $this->waitForOffCanvasToOpen();
      // Check that the main canvas is padded when page is not narrow width and
      // tray is open.
      $page->waitFor(10, function ($page) {
        return $page->find('css', '.dialog-off-canvas-main-canvas')->hasAttribute('style');
      });
      $web_assert->elementAttributeContains('css', '.dialog-off-canvas-main-canvas', 'style', 'padding-right');

      $this->assertJsCondition(<<<'JS'
        (() => {
          const tray = document.getElementById('drupal-off-canvas-wrapper');
          return Drupal.displace.offsets.right === tray.getBoundingClientRect().width;
        })()
        JS);
      $page->pressButton('Close');
      $this->waitForOffCanvasToClose();
      $this->assertJsCondition('Drupal.displace.offsets.right === 0');

      // Testing at the narrower width.
      $this->getSession()->resizeWindow($narrow_width_breakpoint - $offset, $height);
      $this->drupalGet('/off-canvas-test-links');
      $this->assertFalse($page->find('css', '.dialog-off-canvas-main-canvas')->hasAttribute('style'), 'Body not padded on narrow page load.');
      $page->clickLink("Open side panel 1");
      $this->waitForOffCanvasToOpen();
      $this->assertFalse($page->find('css', '.dialog-off-canvas-main-canvas')->hasAttribute('style'), 'Body not padded on narrow page with tray open.');
    }
  }

  /**
   * Tests that a superseded htmx response cannot overwrite the newer tray.
   */
  public function testOffCanvasHtmxRequests(): void {
    $this->drupalGet('/off-canvas-test-htmx-links');
    $session = $this->getSession();
    $page_title = $session->evaluateScript('document.title');
    $session->executeScript(<<<'JS'
      const links = [...document.links];
      const text = Response.prototype.text;
      Response.prototype.text = async function () {
        const body = await text.call(this);
        if (this.url.includes('off-canvas-thing1')) {
          links.find((link) => link.textContent === 'Open panel 2').click();
          await new Promise((resolve) => setTimeout(resolve, 300));
          window.firstTrayResponseRead = true;
        }
        return body;
      };
      links.find((link) => link.textContent === 'Open panel 1').click();
      JS);
    $this->waitForOffCanvasToOpen();
    $this->assertJsCondition('window.firstTrayResponseRead === true');
    $this->assertSession()->elementTextContains('css', '#drupal-off-canvas', 'Thing 2 says hello');
    $this->assertSession()->elementAttributeContains('css', 'dialog#drupal-off-canvas-wrapper', 'style', 'width: 555px');
    $this->assertSame($page_title, $session->evaluateScript('document.title'));
    $this->assertCount(1, $session->getPage()->findAll('css', 'dialog#drupal-off-canvas-wrapper'));
  }

  /**
   * Asserts that the off-canvas dialog opens and renders expected content.
   *
   * @param int $link_index
   *   The index of the link to test.
   * @param string $position
   *   The position of the dialog to test.
   *
   * @internal
   */
  protected function assertOffCanvasDialog(int $link_index, string $position): void {
    $page = $this->getSession()->getPage();
    $web_assert = $this->assertSession();
    $link_text = "Open $position panel $link_index";

    // Click the first test like that should open the page.
    $page->clickLink($link_text);
    if ($this->lastDialogClass) {
      $web_assert->assertNoElementAfterWait('css', '.' . $this->lastDialogClass);
    }
    $this->waitForOffCanvasToOpen($position);
    $this->lastDialogClass = "$position-$link_index";

    // Check that response text is on page.
    $web_assert->pageTextContains("Thing $link_index says hello");
    $off_canvas_tray = $this->getOffCanvasDialog();

    // Check that tray is visible.
    $this->assertEquals(TRUE, $off_canvas_tray->isVisible());

    $tray_text = $off_canvas_tray->findById('drupal-off-canvas')->getText();
    $this->assertEquals("Thing $link_index says hello", $tray_text);
  }

}
