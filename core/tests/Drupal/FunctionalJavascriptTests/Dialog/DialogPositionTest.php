<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Dialog;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the JavaScript functionality of the dialog position.
 */
#[Group('dialog')]
#[RunTestsInSeparateProcesses]
class DialogPositionTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests if the dialog UI works properly with block layout page.
   */
  public function testDialogOpenAndClose(): void {
    $admin_user = $this->drupalCreateUser(['administer blocks']);
    $this->drupalLogin($admin_user);
    $this->drupalGet('admin/structure/block');
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();

    // Keep a reference to a delivery that can run just after close, and
    // record which observer the controller disconnects.
    $session->executeScript(<<<'JS'
      window.ResizeObserver = class extends ResizeObserver {
        constructor(callback) {
          super(callback);
          this.deliver = callback;
        }
        observe(target, options) {
          if (target.matches('dialog.drupal-dialog')) {
            window.dialogObserver = this;
          }
          super.observe(target, options);
        }
        disconnect() {
          this.disconnected = true;
          super.disconnect();
        }
      };
      JS);

    // Open the dialog using the place block link.
    $placeBlockLink = $page->findLink('Place block');
    $this->assertTrue($placeBlockLink->isVisible(), 'Place block button exists.');
    $placeBlockLink->click();
    $assert_session->assertWaitOnAjaxRequest();
    $dialog = $page->find('css', '.drupal-dialog');
    $this->assertTrue($dialog->isVisible(), 'Dialog is opened after clicking the Place block button.');

    // Change the dialog size, queue a resize delivery, then close the dialog
    // before the browser can deliver it.
    $session->executeScript(<<<'JS'
      const dialog = document.querySelector('dialog.drupal-dialog');
      const content = dialog.querySelector('.drupal-dialog-content');
      window.closedDialogResizes = 0;
      content.addEventListener('dialogContentResize', () => {
        window.closedDialogResizes++;
      });
      dialog.style.width = `${dialog.offsetWidth + 25}px`;
      requestAnimationFrame(() => {
        window.dialogObserver.deliver();
        window.queuedResizeDelivered = true;
      });
      dialog.querySelector('.drupal-dialog-titlebar-close').click();
      JS);
    $this->assertJsCondition('window.queuedResizeDelivered === true');
    $this->assertJsCondition('window.dialogObserver.disconnected === true');
    $this->assertSame(0, $session->evaluateScript('window.closedDialogResizes'));
    $assert_session->elementNotExists('css', 'dialog.drupal-dialog');
  }

}
