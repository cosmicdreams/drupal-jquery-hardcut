<?php

declare(strict_types=1);

namespace Drupal\Tests\navigation\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the workspaces tray opened from the Navigation toolbar.
 */
#[Group('navigation')]
#[RunTestsInSeparateProcesses]
class NavigationWorkspacesDialogTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['navigation', 'workspaces_ui'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the Workspaces link loads a native top tray.
   */
  public function testWorkspacesDialog(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access navigation',
      'administer workspaces',
    ]));
    $this->drupalGet('<front>');

    $session = $this->getSession();
    $original_title = $session->evaluateScript('document.title');
    $this->assertSession()->elementExists('css', 'a.toolbar-button--icon--workspaces[data-hx-get]')->click();
    $tray = $this->assertSession()->waitForElementVisible('css', 'dialog#drupal-off-canvas-wrapper[open]');
    $this->assertNotNull($tray);
    $this->assertStringContainsString('Current workspace:', $tray->getText());
    $this->assertTrue($tray->hasClass('drupal-dialog-position-top'));
    $this->assertSame($original_title, $session->evaluateScript('document.title'));
    $this->assertJsCondition('Drupal.displace.offsets.top >= 161');
    $tray->pressButton('Close');
    $this->assertSession()->assertNoElementAfterWait('css', 'dialog#drupal-off-canvas-wrapper');
    $this->assertJsCondition('Drupal.displace.offsets.top < 161');
  }

}
