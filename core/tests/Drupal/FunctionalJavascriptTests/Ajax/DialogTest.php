<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Ajax;

use Drupal\ajax_test\Controller\AjaxTestController;
use Drupal\Core\Ajax\OpenModalDialogWithUrl;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore testdialog
/**
 * Performs tests on opening and manipulating dialogs via AJAX commands.
 */
#[Group('Ajax')]
#[RunTestsInSeparateProcesses]
class DialogTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ajax_test', 'ajax_forms_test', 'dialog_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests sending non-JS and AJAX requests to open and manipulate modals.
   */
  public function testDialog(): void {
    // Ensure the elements render without notices or exceptions.
    $this->drupalGet('ajax-test/dialog');

    // Set up variables for this test.
    $dialog_renderable = AjaxTestController::dialogContents();
    $dialog_contents = \Drupal::service('renderer')->renderRoot($dialog_renderable);

    // Check that requesting a modal dialog without JS goes to a page.
    $this->drupalGet('ajax-test/dialog-contents');
    $this->assertSession()->responseContains($dialog_contents);

    // Visit the page containing the many test dialog links.
    $this->drupalGet('ajax-test/dialog');

    // Tests a basic modal dialog by verifying the contents of the dialog are as
    // expected.
    $this->getSession()->getPage()->clickLink('Link 1 (modal)');

    // Clicking the link triggers an AJAX request/response.
    // Opens a Dialog panel.
    $link1_dialog_div = $this->assertSession()->waitForElementVisible('css', 'dialog.drupal-dialog');
    $this->assertJsCondition('document.querySelector("dialog.drupal-dialog").matches(":modal")');
    $this->assertNotNull($link1_dialog_div, 'Link was used to open a dialog ( modal )');

    $link1_modal = $link1_dialog_div->find('css', '#drupal-modal');
    $this->assertNotNull($link1_modal, 'Link was used to open a dialog ( non-modal )');
    $this->assertSession()->responseContains($dialog_contents);

    $dialog_title = $this->assertSession()->elementExists('css', 'h1.drupal-dialog-title', $link1_dialog_div);
    $this->assertSame('AJAX Dialog & contents: Release <draft>', $dialog_title->getText());
    $this->assertSame($dialog_title->getAttribute('id'), $link1_dialog_div->getAttribute('aria-labelledby'));

    // Close open dialog, return to the dialog links page.
    $close_button = $link1_dialog_div->findButton('Close');
    $this->assertNotNull($close_button);
    $close_button->press();

    // Tests a modal with a dialog-option.
    // Link 2 is similar to Link 1, except it submits additional width
    // information which must be echoed in the resulting  DOM update.
    $this->getSession()->getPage()->clickLink('Link 2 (modal)');
    $dialog = $this->assertSession()->waitForElementVisible('css', 'dialog.drupal-dialog');
    $this->assertNotNull($dialog, 'Link was used to open a dialog ( non-modal, with options )');
    $style = $dialog->getAttribute('style');
    $this->assertStringContainsString('width: 400px;', $style, "Modal respected the dialog-options width parameter.  Style = $style");

    // Reset: Return to the dialog links page.
    $this->drupalGet('ajax-test/dialog');

    // Test a non-modal dialog ( with target ).
    $this->clickLink('Link 3 (non-modal)');
    $non_modal_dialog = $this->assertSession()->waitForElementVisible('css', 'dialog.drupal-dialog');
    $this->assertJsCondition('!document.querySelector("dialog.drupal-dialog").matches(":modal")');
    $this->assertNotNull($non_modal_dialog, 'Link opens a non-modal dialog.');
    $non_modal_dialog_title = $non_modal_dialog->find('css', "h2.drupal-dialog-title:contains('AJAX Dialog & contents')");
    $this->assertNotNull($non_modal_dialog_title);

    // Tests the dialog contains a target element specified in the AJAX request.
    $non_modal_dialog->find('css', 'div#ajax-test-dialog-wrapper-1');
    $this->assertSession()->responseContains($dialog_contents);

    // Reset: Return to the dialog links page.
    $this->drupalGet('ajax-test/dialog');

    // Tests a non-modal dialog ( without target ).
    $this->clickLink('Link 7 (non-modal, no target)');
    $no_target_dialog = $this->assertSession()->waitForElementVisible('css', 'dialog.drupal-dialog');
    $this->assertNotNull($no_target_dialog, 'Link opens a non-modal dialog.');

    $contents_no_target = $no_target_dialog->find('css', 'div.drupal-dialog-content');
    $this->assertNotNull($contents_no_target, 'non-modal dialog opens ( no target ). ');
    $id = $contents_no_target->getAttribute('id');
    $partial_match = str_starts_with($id, 'drupal-dialog-ajax-testdialog-contents');
    $this->assertTrue($partial_match, 'The non-modal ID has the expected prefix.');

    $no_target_button = $no_target_dialog->findButton('Close');
    $this->assertNotNull($no_target_button, 'Link dialog has a close button');
    $no_target_button->press();

    $this->getSession()->getPage()->findButton('Button 1 (modal)')->press();
    $button1_dialog = $this->assertSession()->waitForElementVisible('css', 'dialog.drupal-dialog');
    $this->assertNotNull($button1_dialog, 'Button opens a modal dialog.');

    $button1_dialog_content = $button1_dialog->find('css', 'div.drupal-dialog-content');
    $this->assertNotNull($button1_dialog_content, 'Button opens a modal dialog.');

    // Test the HTML escaping of & character.
    $button1_dialog_title = $button1_dialog->find('css', "h1.drupal-dialog-title:contains('AJAX Dialog & contents')");
    $this->assertNotNull($button1_dialog_title);
    $button1_dialog_title_amp = $button1_dialog->find('css', "h1.drupal-dialog-title:contains('AJAX Dialog &amp; contents')");
    $this->assertNull($button1_dialog_title_amp);

    // Reset: Close the dialog.
    $button1_dialog->findButton('Close')->press();

    // Abbreviated test for "normal" dialogs, testing only the difference.
    $this->getSession()->getPage()->findButton('Button 2 (non-modal)')->press();
    $button2_dialog = $this->assertSession()->waitForElementVisible('css', 'div.drupal-dialog-content');
    $this->assertNotNull($button2_dialog, 'Non-modal content displays as expected.');

    // Use a link to close the panel opened by button 2.
    $this->getSession()->getPage()->clickLink('Link 4 (close non-modal if open)');

    // Test dialogs opened using OpenModalDialogWithUrl.
    $this->getSession()->getPage()->findButton('Button 3 (modal from url)')->press();
    // Check that title was fetched properly.
    // @see \Drupal\ajax_test\Form\AjaxTestDialogForm::dialog.
    $form_dialog_title = $this->assertSession()->waitForElement('css', "h1.drupal-dialog-title:contains('Ajax Form contents')");
    $this->assertNotNull($form_dialog_title, 'Dialog form has the expected title.');
    $this->getSession()->getPage()->pressButton('Close');
    // Test external URL.
    $dialog_obj = new OpenModalDialogWithUrl('http://example.com', []);
    try {
      $dialog_obj->render();
    }
    catch (\LogicException $e) {
      $this->assertEquals('External URLs are not allowed.', $e->getMessage());
    }

    // Form modal.
    $this->clickLink('Link 5 (form)');
    // Two links have been clicked in succession - This time wait for a change
    // in the title as the previous closing dialog may temporarily be open.
    $form_dialog_title = $this->assertSession()->waitForElementVisible('css', "h1.drupal-dialog-title:contains('Ajax Form contents')");
    $this->assertNotNull($form_dialog_title, 'Dialog form has the expected title.');
    // Locate the newly opened dialog.
    $form_dialog = $this->getSession()->getPage()->find('css', 'dialog.drupal-dialog');
    $this->assertNotNull($form_dialog, 'Form dialog is visible');

    $form_contents = $form_dialog->find('css', "p:contains('Ajax Form contents description.')");
    $this->assertNotNull($form_contents, 'For has the expected text.');
    $do_it = $form_dialog->findButton('Do it');
    $this->assertNotNull($do_it, 'The dialog has a "Do it" button.');
    $preview = $form_dialog->findButton('Preview');
    $this->assertNotNull($preview, 'The dialog contains a "Preview" button.');

    // Form submit inputs, link buttons, and buttons in dialog are copied to the
    // dialog buttonpane as buttons. The originals should have their styles set
    // to display: none.
    $hidden_buttons = $this->getSession()->getPage()->findAll('css', '.ajax-test-form .button');
    $this->assertCount(3, $hidden_buttons);
    $hidden_button_text = [];
    foreach ($hidden_buttons as $button) {
      $styles = $button->getAttribute('style');
      $this->assertStringContainsStringIgnoringCase('display: none;', $styles);
      $hidden_button_text[] = $button->hasAttribute('value') ? $button->getAttribute('value') : $button->getHtml();
    }

    // The copied buttons should have the same text as the submit inputs they
    // were copied from.
    $moved_to_buttonpane_buttons = $this->getSession()->getPage()->findAll('css', '.drupal-dialog-buttonpane button');
    $this->assertCount(3, $moved_to_buttonpane_buttons);
    foreach ($moved_to_buttonpane_buttons as $key => $button) {
      $this->assertEquals($hidden_button_text[$key], $button->getText());
    }

    // Press buttons in the dialog to ensure there are no AJAX errors.
    $this->assertSession()->elementExists('css', '.drupal-dialog-buttonpane')->pressButton('Hello world');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $has_focus_text = $this->getSession()->evaluateScript('document.activeElement.textContent');
    $this->assertEquals('Do it', $has_focus_text);
    $this->assertSession()->elementExists('css', '.drupal-dialog-buttonpane')->pressButton('Preview');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $has_focus_text = $this->getSession()->evaluateScript('document.activeElement.textContent');
    $this->assertEquals('Do it', $has_focus_text);

    // Reset: close the form.
    $form_dialog->findButton('Close')->press();

    // Non AJAX version of Link 6.
    $this->drupalGet('dialog-test/add');
    // Check we get a chunk of the code, we can't test the whole form as form
    // build id and token with be different.
    $this->assertSession()->elementExists('xpath', "//form[@id='dialog-test-entity-form-add-form']");

    // Reset: Return to the dialog links page.
    $this->drupalGet('ajax-test/dialog');

    $this->clickLink('Link 6 (entity form)');
    $dialog_add = $this->assertSession()->waitForElementVisible('css', 'dialog.drupal-dialog');
    $this->assertNotNull($dialog_add, 'Form dialog is visible');

    $form_add = $dialog_add->find('css', 'form.dialog-test-entity-form-add-form');
    $this->assertNotNull($form_add, 'Modal dialog JSON contains entity form.');

    $form_title = $dialog_add->find('css', "h1.drupal-dialog-title:contains('Add dialog test entity form')");
    $this->assertNotNull($form_title, 'The add form title is as expected.');

    // Test: dialog link opener with title callback.
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();
    $this->drupalGet("/ajax-test/link-page-dialog");
    $page->clickLink('Modal link');
    $this->assertEquals('Dialog link page title', $assert_session->waitForElement('css', '.drupal-dialog-title')->getText());
  }

  /**
   * Tests overlapping responses and cancellation of a dialog request.
   */
  public function testStaleDialogResponses(): void {
    $this->drupalGet('ajax-test/dialog');
    $session = $this->getSession();
    $assert = $this->assertSession();
    $session->executeScript(<<<'JS'
      window.pauseNextAjax = () => {
        delete window.resumeAjax;
        document.addEventListener('drupal:ajax-before-send', ({ detail: { ajax } }) => {
          const receive = ajax.receiveResponse;
          ajax.receiveResponse = (...args) => {
            ajax.receiveResponse = receive;
            return new Promise((resolve) => {
              window.resumeAjax = () => resolve(receive.apply(ajax, args));
            });
          };
        }, { once: true });
      };
      window.pauseNextAjax();
      JS);

    $this->clickLink('Link 1 (modal)');
    $this->assertJsCondition('typeof window.resumeAjax === "function"');
    $this->clickLink('Link 2 (modal)');
    $this->assertNotNull($assert->waitForElementVisible('css', 'dialog[open]'));
    $session->executeScript('window.resumeAjax()');
    $assert->assertWaitOnAjaxRequest();
    $this->assertJsCondition('document.querySelector("dialog[open]").style.width === "400px"');

    // A nested request is still pending when the user cancels the dialog that
    // opened it. Its response must not open a new dialog.
    $session->executeScript(<<<'JS'
      window.pauseNextAjax();
      const source = document.querySelector('dialog[open] a.dialog-cancel');
      Drupal.ajax({
        element: source,
        event: null,
        keypress: false,
        url: Drupal.url('ajax-test/dialog-contents'),
        dialogType: 'dialog',
      }).execute();
      JS);
    $this->assertJsCondition('typeof window.resumeAjax === "function"');
    $this->clickLink('Cancel');
    $assert->assertNoElementAfterWait('css', 'dialog[open]');
    $session->executeScript('window.resumeAjax()');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementNotExists('css', 'dialog[open]');

    // An outstanding close command for the old dialog must leave its
    // replacement open, even when both use the same selector.
    $this->clickLink('Link 3 (non-modal)');
    $original = $assert->waitForElementVisible('css', '#ajax-test-dialog-wrapper-1');
    $this->assertNotNull($original);
    $session->executeScript('window.pauseNextAjax()');
    $this->clickLink('Link 4 (close non-modal if open)');
    $this->assertJsCondition('typeof window.resumeAjax === "function"');
    $original->getParent()->pressButton('Close');
    $assert->assertNoElementAfterWait('css', '#ajax-test-dialog-wrapper-1');
    $this->clickLink('Link 3 (non-modal)');
    $this->assertNotNull($assert->waitForElementVisible('css', '#ajax-test-dialog-wrapper-1'));
    $session->executeScript('window.resumeAjax()');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementExists('css', '#ajax-test-dialog-wrapper-1');
  }

  /**
   * Tests nested autocomplete, details and select controls in dialogs.
   */
  public function testNestedFormWidgets(): void {
    $this->container->get('module_installer')->install(['form_test', 'contextual_test']);
    $this->drupalLogin($this->drupalCreateUser([
      'access autocomplete test',
      'access contextual links',
    ]));
    $assert = $this->assertSession();
    $session = $this->getSession();

    foreach ([['modal', NULL], ['dialog', NULL], ['dialog', 'off_canvas']] as [$type, $renderer]) {
      $this->drupalGet('ajax-test/dialog');
      $session->executeScript(sprintf(<<<'JS'
        Drupal.ajax({
          url: Drupal.url('ajax-test/dialog-widgets'),
          dialogType: %s,
          dialogRenderer: %s,
        }).execute();
        JS, json_encode($type), json_encode($renderer)));
      $dialog = $assert->waitForElementVisible('css', 'dialog[open]');
      $this->assertNotNull($dialog);
      $dialog->find('css', 'summary')->click();
      $assert->elementExists('css', 'details[open]', $dialog);
      $dialog->selectFieldOption('Choice', 'second');
      $this->assertSame('second', $dialog->findField('Choice')->getValue());

      $field = $dialog->findField('Suggestions');
      $field->setValue('label');
      $session->getDriver()->keyDown($field->getXpath(), ' ');
      $menu = $assert->waitForElementVisible('css', 'dialog[open] .ui-autocomplete li');
      $this->assertNotNull($menu);
      $this->assertJsCondition(<<<'JS'
        (() => {
          const input = document.querySelector('dialog[open] input.form-autocomplete');
          const item = document.querySelector('dialog[open] .ui-autocomplete li');
          const anchor = input.getBoundingClientRect();
          const option = item.getBoundingClientRect();
          return getComputedStyle(item.parentElement).position === 'absolute' &&
            Math.abs(option.left - anchor.left) < 10 &&
            Math.abs(option.top - anchor.bottom) < 10 &&
            item.contains(document.elementFromPoint(
              option.left + option.width / 2,
              option.top + option.height / 2,
            ));
        })()
        JS);
      $menu->click();
      $this->assertSame('value', $field->getValue());

      $region = $dialog->find('css', '.contextual-region');
      $session->getDriver()->mouseOver($region->getXpath());
      $trigger = $assert->waitForElementVisible('css', 'dialog[open] .contextual button.trigger');
      $this->assertNotNull($trigger);
      $trigger->click();
      $link = $assert->waitForElementVisible('css', 'dialog[open] .contextual a.use-ajax');
      $this->assertNotNull($link);
      $link->click();
      $this->assertJsCondition('document.querySelector("dialog:modal")?.textContent.includes("Everything is contextual!")');
      $assert->elementExists('css', '#drupal-modal')->getParent()->pressButton('Close');
      if ($type !== 'modal') {
        $dialog->pressButton('Close');
      }
      $assert->assertNoElementAfterWait('css', 'dialog[open]');
    }
  }

  /**
   * Tests dialog link opener with different HTTP methods.
   */
  public function testHttpMethod(): void {
    $assert = $this->assertSession();
    $script = <<<SCRIPT
      (function() {
        return document.querySelector('dialog:has(> #drupal-modal)').offsetWidth;
      }())
      SCRIPT;

    // Open the modal dialog with POST HTTP method.
    $this->drupalGet('/ajax-test/http-methods');
    $this->clickLink('Link');
    $assert->assertWaitOnAjaxRequest();
    $assert->pageTextContains('Modal dialog contents');
    $width = $this->getSession()->getDriver()->evaluateScript($script);
    // The requested width includes the dialog border.
    $this->assertSame(800, $width);

    // Switch to GET HTTP method.
    // @see \Drupal\ajax_test\Controller\AjaxTestController::httpMethods()
    \Drupal::state()->set('ajax_test.http_method', 'GET');

    // Open the modal dialog with GET HTTP method.
    $this->drupalGet('/ajax-test/http-methods');
    $this->clickLink('Link');
    $assert->assertWaitOnAjaxRequest();
    $assert->pageTextContains('Modal dialog contents');
    $width = $this->getSession()->getDriver()->evaluateScript($script);
    // The requested width includes the dialog border.
    $this->assertSame(800, $width);
  }

  /**
   * Tests that percentage heights cap the dialog's natural height.
   */
  public function testPercentageHeight(): void {
    $this->drupalGet('ajax-test/dialog');
    $this->clickLink('Link 1 (modal)');
    $dialog = $this->assertSession()->waitForElementVisible('css', 'dialog[open]');
    $this->assertNotNull($dialog);
    $dialog->pressButton('Close');

    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const toolbar = document.createElement('div');
      toolbar.dataset.offsetTop = '80';
      document.body.append(toolbar);
      Drupal.displace();
      const content = document.createElement('div');
      content.textContent = 'Short content';
      Drupal.dialog(content, { height: '75%', removeOnClose: true }).showModal();
      JS);
    $short_height = $session->evaluateScript('document.querySelector("dialog:modal").offsetHeight');
    $this->assertGreaterThan(0, $short_height);
    $limit = $session->evaluateScript('(document.documentElement.clientHeight - Drupal.displace.offsets.top) * 0.75');
    $this->assertLessThan($limit, $short_height);

    $session->executeScript(<<<'JS'
      const tallBlock = document.createElement('div');
      tallBlock.style.height = '200vh';
      document.querySelector('dialog:modal .drupal-dialog-content').append(tallBlock);
      JS);
    $this->assertEqualsWithDelta($limit, $session->evaluateScript('document.querySelector("dialog:modal").offsetHeight'), 1);
    $this->assertJsCondition(<<<'JS'
      (() => {
        const content = document.querySelector('dialog:modal .drupal-dialog-content');
        return content.scrollHeight > content.clientHeight;
      })()
      JS);

    $session->executeScript(<<<'JS'
      const content = document.querySelector('dialog:modal .drupal-dialog-content');
      const dialog = Drupal.dialog.get(content);
      dialog.element.classList.add('dialog-consumer-state');
      dialog.update({ maxHeight: '50%' });
      JS);
    $this->assertEqualsWithDelta($limit * 2 / 3, $session->evaluateScript('document.querySelector("dialog:modal").offsetHeight'), 1);
    $this->assertJsCondition('document.querySelector("dialog:modal").classList.contains("dialog-consumer-state")');

    $session->executeScript('document.querySelector("dialog:modal .drupal-dialog-content").lastElementChild.remove()');
    $this->assertSame($short_height, $session->evaluateScript('document.querySelector("dialog:modal").offsetHeight'));
  }

  /**
   * Tests native modal naming, focus containment, and keyboard dismissal.
   */
  public function testNativeModalFocus(): void {
    $this->drupalGet('ajax-test/dialog');
    $this->clickLink('Link 5 (form)');
    $this->assertSession()->waitForElementVisible('css', 'dialog[open]');
    $this->assertJsCondition(<<<'JS'
      (() => {
        const dialog = document.querySelector('dialog:modal');
        const title = document.getElementById(dialog.getAttribute('aria-labelledby'));
        return title?.textContent === 'Ajax Form contents' &&
          document.activeElement.textContent === 'Do it';
      })()
      JS);
    $this->getSession()->executeScript(<<<'JS'
      [...document.links].find((link) => link.textContent === 'Link 5 (form)').focus();
      JS);
    $this->assertJsCondition('document.querySelector("dialog:modal").contains(document.activeElement)');

    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(DrupalSelenium2Driver::class, $driver);
    $webdriver = $driver->getWebDriverSession();
    $keyboard = ['type' => 'key', 'id' => 'keyboard'];

    // Tab from the last form action wraps to the close button.
    $this->getSession()->executeScript('document.querySelector("dialog:modal .drupal-dialog-buttonset button:last-child").focus()');
    $keyboard['actions'] = [
      ['type' => 'keyDown', 'value' => "\u{E004}"],
      ['type' => 'keyUp', 'value' => "\u{E004}"],
    ];
    $webdriver->postActions(['actions' => [$keyboard]]);
    $this->assertJsCondition('document.activeElement.matches(".drupal-dialog-titlebar-close")');

    // Shift+Tab at the close button wraps back to the last form action.
    $keyboard['actions'] = [
      ['type' => 'keyDown', 'value' => "\u{E008}"],
      ['type' => 'keyDown', 'value' => "\u{E004}"],
      ['type' => 'keyUp', 'value' => "\u{E004}"],
      ['type' => 'keyUp', 'value' => "\u{E008}"],
    ];
    $webdriver->postActions(['actions' => [$keyboard]]);
    $this->assertJsCondition('document.activeElement.matches(".drupal-dialog-buttonset button:last-child")');

    $keyboard['actions'] = [
      ['type' => 'keyDown', 'value' => "\u{E00C}"],
      ['type' => 'keyUp', 'value' => "\u{E00C}"],
    ];
    $webdriver->postActions(['actions' => [$keyboard]]);
    $this->assertSession()->assertNoElementAfterWait('css', 'dialog[open]');
    $this->assertJsCondition('document.activeElement.textContent === "Link 5 (form)"');
  }

}
