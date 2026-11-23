<?php

declare(strict_types=1);

namespace Drupal\Tests\navigation\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
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
    $session->executeScript(<<<'JS'
      document.querySelector('a.toolbar-button--icon--workspaces')
        .addEventListener('htmx:before:request', ({ currentTarget }) => {
          const icon = currentTarget.querySelector('.toolbar-button__icon');
          window.workspacesThrobberVisible = getComputedStyle(icon).backgroundImage !== 'none';
        }, { once: true });
      JS);
    $this->assertSession()->elementExists('css', 'a.toolbar-button--icon--workspaces[data-hx-get]')->click();
    $tray = $this->assertSession()->waitForElementVisible('css', 'dialog#drupal-off-canvas-wrapper[open]');
    $this->assertNotNull($tray);
    $this->assertJsCondition('window.workspacesThrobberVisible === true');
    $this->assertJsCondition('!document.querySelector("a.toolbar-button--icon--workspaces").classList.contains("htmx-request")');
    $this->assertStringContainsString('Current workspace:', $tray->getText());
    $this->assertTrue($tray->hasClass('drupal-dialog-position-top'));
    $this->assertSame($original_title, $session->evaluateScript('document.title'));
    $this->assertJsCondition('Drupal.displace.offsets.top >= 161');
    // Pause the second response until after the open tray has been closed.
    $session->executeScript(<<<'JS'
      document.querySelector('a.toolbar-button--icon--workspaces')
        .addEventListener('htmx:before:request', ({ detail: { ctx } }) => {
          const fetch = ctx.fetch;
          ctx.fetch = async (...args) => {
            const response = await fetch(...args);
            await new Promise((resolve) => { window.resumeWorkspaces = resolve; });
            return response;
          };
        }, { once: true });
      JS);
    $this->assertSession()->elementExists('css', 'a.toolbar-button--icon--workspaces[data-hx-get]')->click();
    $this->assertJsCondition('typeof window.resumeWorkspaces === "function"');
    $tray->pressButton('Close');
    $this->assertSession()->assertNoElementAfterWait('css', 'dialog#drupal-off-canvas-wrapper');
    $session->executeScript('window.resumeWorkspaces()');
    $tray = $this->assertSession()->waitForElementVisible('css', 'dialog#drupal-off-canvas-wrapper[open]');
    $this->assertNotNull($tray);
    $this->assertStringContainsString('Current workspace:', $tray->getText());
    $tray->pressButton('Close');
    $this->assertSession()->assertNoElementAfterWait('css', 'dialog#drupal-off-canvas-wrapper');
    $this->assertJsCondition('Drupal.displace.offsets.top < 161');
  }

  /**
   * Tests overlapping Workspaces, Layout Builder, and direct dialog opens.
   */
  public function testSharedTrayOpeningOrder(): void {
    $this->container->get('module_installer')->install(['node', 'layout_builder']);
    $this->createContentType(['type' => 'page']);
    LayoutBuilderEntityViewDisplay::load('node.page.default')
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();
    $node = $this->createNode(['type' => 'page']);
    $this->drupalLogin($this->drupalCreateUser([
      'access navigation',
      'administer workspaces',
      'configure any layout',
    ]));

    $assert = $this->assertSession();
    $session = $this->getSession();
    $tray = 'dialog#drupal-off-canvas-wrapper[open]';
    $this->drupalGet($node->toUrl()->toString() . '/layout');
    $session->executeScript(<<<'JS'
      window.openers = {
        workspaces: document.querySelector('.toolbar-button--icon--workspaces[data-hx-get]'),
        layout: document.querySelector('.layout-builder__add-block a'),
      };
      window.pauseOpen = (name, source = window.openers[name], waitForAssets = false) => {
        delete window.resumeOpen;
        window.openFinished = false;
        if (name === 'workspaces') {
          const event = waitForAssets ? 'htmx:before:response' : 'htmx:before:request';
          source.addEventListener(event, ({ detail: { ctx } }) => {
            const delay = (callback) => async (...args) => {
              const result = await callback(...args);
              await new Promise((resolve) => { window.resumeOpen = resolve; });
              return result;
            };
            if (waitForAssets) {
              const response = ctx.response.raw;
              response.text = delay(response.text.bind(response));
            } else {
              ctx.fetch = delay(ctx.fetch);
            }
            document.addEventListener('htmx:finally:request', ({ detail }) => {
              if (detail.ctx === ctx) {
                window.openFinished = true;
              }
            });
          }, { once: true });
        } else {
          document.addEventListener('drupal:ajax-before-send', ({ detail: { ajax } }) => {
            const receive = ajax.receiveResponse;
            ajax.receiveResponse = (...args) => {
              ajax.receiveResponse = receive;
              return new Promise((resolve) => {
                window.resumeOpen = () => {
                  resolve(receive.apply(ajax, args));
                };
              }).finally(() => { window.openFinished = true; });
            };
          }, { once: true });
        }
        source.click();
      };
      JS);

    foreach ([
      ['workspaces', 'layout', 'Choose a block'],
      ['layout', 'workspaces', 'Current workspace:'],
    ] as [$slow, $fast, $expected]) {
      $session->executeScript("window.pauseOpen('$slow')");
      $this->assertJsCondition('typeof window.resumeOpen === "function"');
      $session->executeScript("window.resumeFirst = window.resumeOpen; window.pauseOpen('$fast')");
      $this->assertJsCondition('typeof window.resumeOpen === "function"');
      $session->executeScript("window.previousTray = document.querySelector('$tray')");
      $session->executeScript('window.resumeFirst()');
      $this->assertJsCondition('window.openFinished');
      $this->assertJsCondition("document.querySelector('$tray') === window.previousTray");
      $session->executeScript('window.openFinished = false; window.resumeOpen()');
      $this->assertJsCondition('window.openFinished');
      $this->assertNotNull($assert->waitForElementVisible('css', "$tray:contains('$expected')"));

      $session->executeScript("window.pauseOpen('$slow', undefined, true)");
      $this->assertJsCondition('typeof window.resumeOpen === "function"');
      $session->executeScript("window.openers.$fast.click()");
      $this->assertNotNull($assert->waitForElementVisible('css', "$tray:contains('$expected')"));
      $session->executeScript('window.resumeOpen()');
      $this->assertJsCondition('window.openFinished');
      $assert->elementTextContains('css', $tray, $expected);
    }

    // Opening from a lifecycle listener supersedes the open in progress.
    $session->executeScript(<<<'JS'
      const content = document.createElement('div');
      const dialog = Drupal.dialog(content, { removeOnClose: true });
      content.addEventListener('dialog:beforecreate', () => dialog.showModal(), { once: true });
      dialog.show();
      window.reentrantDialog = dialog;
      JS);
    $this->assertJsCondition('window.reentrantDialog.element.matches(":modal")');
    $session->executeScript('window.reentrantDialog.close()');

    // A direct show or showModal on the same target supersedes both old
    // requests, even if the tray created by the last request was removed.
    foreach (['show', 'showModal'] as $method) {
      $session->executeScript("window.pauseOpen('workspaces')");
      $this->assertJsCondition('typeof window.resumeOpen === "function"');
      $session->executeScript(<<<'JS'
        window.resumeHtmx = window.resumeOpen;
        window.pauseOpen('layout');
        JS);
      $this->assertJsCondition('typeof window.resumeOpen === "function"');
      $session->executeScript(<<<JS
        Drupal.dialog.get(document.getElementById('drupal-off-canvas')).close();
        const content = document.createElement('div');
        content.id = 'drupal-off-canvas';
        content.textContent = 'Direct dialog';
        Drupal.dialog(content, { removeOnClose: true }).$method();
        window.resumeHtmx();
        window.resumeOpen();
        JS);
      $assert->assertWaitOnAjaxRequest();
      $this->assertJsCondition('!window.openers.workspaces.classList.contains("htmx-request")');
      $assert->elementTextContains('css', 'dialog[open] #drupal-off-canvas', 'Direct dialog');
      $assert->elementExists('css', 'dialog[open]')->pressButton('Close');

      // Restore the tray for the next opening and the dismissal cases.
      $session->executeScript('window.openers.workspaces.click()');
      $this->assertNotNull($assert->waitForElementVisible('css', "$tray:contains('Current workspace:')"));
    }

    // Closing a tray cancels requests launched from within that tray, for
    // either transport. The existing test covers the external Workspaces link.
    foreach (['layout', 'workspaces'] as $name) {
      $session->executeScript(<<<JS
        const source = window.openers.$name.cloneNode(true);
        source.removeAttribute('data-once');
        const content = document.getElementById('drupal-off-canvas');
        content.append(source);
        htmx.process(source);
        Drupal.attachBehaviors(content);
        window.pauseOpen('$name', source);
        JS);
      $this->assertJsCondition('typeof window.resumeOpen === "function"');
      $assert->elementExists('css', $tray)->pressButton('Close');
      $session->executeScript('window.resumeOpen()');
      $this->assertJsCondition('window.openFinished');
      $assert->elementNotExists('css', $tray);
      $session->executeScript('window.openers.workspaces.click()');
      $this->assertNotNull($assert->waitForElementVisible('css', "$tray:contains('Current workspace:')"));
    }
  }

  /**
   * Tests the tray stays open when a Workspaces request fails.
   */
  public function testWorkspacesDialogErrors(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access navigation',
      'administer workspaces',
    ]));
    $this->drupalGet('<front>');

    $session = $this->getSession();
    $assert = $this->assertSession();
    $opener = $assert->elementExists('css', 'a.toolbar-button--icon--workspaces[data-hx-get]');
    $opener->click();
    $tray = $assert->waitForElementVisible('css', 'dialog#drupal-off-canvas-wrapper[open]');
    $this->assertNotNull($tray);
    $original_title = $session->evaluateScript('document.title');

    foreach ([403, 500] as $status) {
      $session->executeScript(<<<JS
        window.failedResponseRead = false;
        document.querySelector('.toolbar-button--icon--workspaces')
          .addEventListener('htmx:before:request', ({ detail: { ctx } }) => {
            ctx.fetch = async () => new Response(
              '<title>Failed response</title><p>Failure $status</p>',
              { status: $status, headers: { 'HX-Trigger': 'failed-tray-response' } },
            );
          }, { once: true });
        document.addEventListener('failed-tray-response', () => {
          window.failedResponseTriggered = true;
        }, { once: true });
        document.addEventListener('htmx:finally:request', () => {
          window.failedResponseRead = true;
        }, { once: true });
        JS);
      $opener->click();
      $this->assertJsCondition('window.failedResponseRead');
      $this->assertJsCondition('!window.failedResponseTriggered');
      $assert->elementTextContains('css', '[data-drupal-message-type="error"]', 'Oops, something went wrong.');
      $assert->pageTextNotContains("Failure $status");
      $this->assertStringContainsString('Current workspace:', $tray->getText());
      $this->assertSame($original_title, $session->evaluateScript('document.title'));
      $session->executeScript('new Drupal.Message().clear()');
    }
  }

}
