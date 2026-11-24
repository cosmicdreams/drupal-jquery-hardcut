<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Ajax;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Performs tests on AJAX framework commands.
 */
#[Group('Ajax')]
#[RunTestsInSeparateProcesses]
class CommandsTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'ajax_test', 'ajax_forms_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that each command waits for the previous command to finish.
   */
  public function testPromiseCommandOrder(): void {
    $this->drupalGet('ajax-test/promise-form');
    $this->getSession()->getPage()->pressButton('Execute commands button');
    $this->assertSession()->assertExpectedAjaxRequest(1);
    $this->assertSession()->elementTextEquals('css', '#ajax_test_form_promise_wrapper', '12345');
  }

  /**
   * Tests striping visible rows again without changing hidden or nested rows.
   */
  public function testRestripeCommand(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form');
    $this->getSession()->getPage()->pressButton("AJAX 'restripe' command");
    $this->assertSession()->assertExpectedAjaxRequest(1);

    $rows = $this->getSession()->getPage()->findAll('css', '#restripe_table > tbody > tr');
    $this->assertCount(4, $rows);
    $this->assertSame('odd', $rows[0]->getAttribute('class'));
    $this->assertSame('odd', $rows[1]->getAttribute('class'));
    $this->assertSame('even', $rows[2]->getAttribute('class'));
    $this->assertSame('odd', $rows[3]->getAttribute('class'));
    $nested = $this->assertSession()->elementExists('css', '#table-nested');
    $this->assertSame('odd', $nested->getAttribute('class'));
  }

  /**
   * Tests native insertion and the typed form commands in queue order.
   */
  public function testNativeCommandQueue(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const fixture = document.createElement('div');
      fixture.id = 'native-commands';
      fixture.innerHTML = '<div class="target"></div><div class="target"></div><input id="typed-command-input">';
      document.body.append(fixture);

      fixture.addEventListener('command-event', (event) => {
        fixture.dataset.eventValue = event.target.value;
        fixture.dataset.canceled = String(event.cancelable);
      });

      const ajax = Drupal.ajax({ url: location.href });
      ajax.commandExecutionQueue([
        { command: 'insert', selector: '#native-commands .target', method: 'append',
          data: '<!-- fixture -->first <span>second</span><script>document.currentScript && (window.nativeScriptRuns = (window.nativeScriptRuns || 0) + 1);</script><script type="application/json">{"fixture":true}</script>' },
        { command: 'css', selector: '#typed-command-input', argument: { minWidth: 42, 'opacity': 0.5, '--custom-value': 3 } },
        { command: 'setValue', selector: '#typed-command-input', value: 'selected' },
        { command: 'dispatchEvent', selector: '#typed-command-input', event: 'command-event' },
        { command: 'toggleClass', selector: '#typed-command-input', className: 'error', enabled: true },
        { command: 'setAttribute', selector: '#typed-command-input', name: 'data-error', value: 'true' },
        { command: 'focus', selector: '#typed-command-input' },
        { command: 'insert', selector: '#native-commands', method: 'append', effect: 'fade', speed: 500,
          data: '<span class="animated-target">animated</span>' },
      ], 'success').then(() => {
        fixture.dataset.animationTracked = String(Drupal.hasActiveAnimations());
        fixture.dataset.completed = 'yes';
      });
      JS);

    $this->assertJsCondition("document.querySelector('#native-commands').dataset.completed === 'yes'");
    $this->assertSame(1, $session->evaluateScript('window.nativeScriptRuns'));
    $fixture = $this->assertSession()->elementExists('css', '#native-commands');
    $this->assertSame('selected', $fixture->getAttribute('data-event-value'));
    $this->assertSame('true', $fixture->getAttribute('data-canceled'));
    $this->assertSame('true', $fixture->getAttribute('data-animation-tracked'));
    $this->assertJsCondition('!Drupal.hasActiveAnimations()');
    $this->assertSession()->elementsCount('css', '#native-commands .target > span', 2);
    $this->assertSession()->elementsCount('css', '#native-commands script[type="application/json"]', 2);
    $this->assertTrue($session->evaluateScript("Array.from(document.querySelectorAll('#native-commands .target')).every(target => target.firstChild.nodeType === Node.COMMENT_NODE && target.childNodes[1].textContent === 'first ')"));
    $this->assertTrue($session->evaluateScript(<<<'JS'
      (() => {
        const input = document.querySelector('#typed-command-input');
        return document.activeElement === input && input.value === 'selected' &&
          input.classList.contains('error') && input.dataset.error === 'true' &&
          input.style.minWidth === '42px' && input.style.opacity === '0.5' &&
          input.style.getPropertyValue('--custom-value') === '3';
      })()
      JS));
  }

  /**
   * Tests that superseded responses can contain a settings command with no data.
   */
  public function testSupersededEmptySettingsCommand(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $this->assertTrue($this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const ajax = Drupal.ajax({ url: location.href });
        ajax.isResponseCurrent = () => false;
        await ajax.commandExecutionQueue([
          { command: 'settings', merge: true },
          { command: 'settings', merge: true, settings: {
            ajaxPageState: { testAssetState: 'retained' },
          } },
        ], 'success');
        return drupalSettings.ajaxPageState.testAssetState === 'retained';
      })()
      JS));
  }

  /**
   * Tests common Ajax commands on a page without jQuery.
   */
  public function testCouncilNativeCommands(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const target = document.createElement('div');
        target.id = 'native-absolute';
        target.dataset.fixture = 'alpha beta';
        document.body.append(target);
        const ajax = Drupal.ajax({ url: location.href });
        await ajax.commandExecutionQueue([
          { command: 'css', selector: "[data-fixture~='beta']", argument: { width: 42, cssFloat: 'left' } },
          { command: 'data', selector: '#native-absolute', name: 'state', value: 7 },
          { command: 'insert', selector: "[data-fixture ~= 'beta']", method: 'html',
            data: '<div style="display:none"><span class="ajax-new-content">Visible</span></div>' },
        ]);
        return [typeof jQuery, target.style.width, target.style.cssFloat,
          Drupal.ajax.elementData.get(target, 'state'), getComputedStyle(target.firstElementChild).display];
      })()
      JS);
    $this->assertSame(['undefined', '42px', 'left', 7, 'block'], $result);
  }

  /**
   * Tests native selectors and numerical style units.
   */
  public function testNativeSelectorAndStyleUnits(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const target = document.body.appendChild(document.createElement('div'));
        target.id = 'native-selector';
        target.innerHTML = '<span data-label=":has("></span><span class="skip"></span>';
        const ajax = Drupal.ajax({ url: location.href });
        const styles = {
          fontSize: 21, borderRadius: 3, gap: 8, flex: 1, opacity: 0.5, lineHeight: 2,
          minWidth: 21, paddingTop: 8, borderWidth: 3, '--size': 7,
        };
        await ajax.commands.css(ajax, { selector: '#native-selector', argument: styles });
        await ajax.commands.css(ajax, { selector: '#native-selector', argument: {
          width: -2, height: '-.5em', padding: -3, borderWidth: '-1px', marginLeft: -7,
        } });
        const signed = ['width', 'height', 'paddingLeft', 'borderWidth', 'marginLeft'].map((key) => target.style[key]);
        await ajax.commands.css(ajax, { selector: '#native-selector', argument: styles });
        let selected;
        try {
          await ajax.commands.data(ajax, {
            selector: '#native-selector span:first-child:not(.skip)[data-label=":has("]',
            name: 'marked', value: true,
          });
          selected = Drupal.ajax.elementData.get(target.firstElementChild, 'marked');
        }
        catch (error) {
          selected = error.message;
        }
        ajax.commands.data(ajax, {
          selector: '#native-selector:has(.skip)', name: 'hasChild', value: true,
        });
        return [selected, Drupal.ajax.elementData.get(target, 'hasChild'),
          Object.keys(styles).filter((key) => key !== 'flex').map((key) => key.startsWith('--')
          ? target.style.getPropertyValue(key) : target.style[key]), target.style.flexGrow, signed];
      })()
      JS);
    $this->assertSame([
      TRUE,
      TRUE,
      ['', '', '', '0.5', '2', '21px', '8px', '3px', '7'],
      '1',
      ['0px', '0em', '0px', '0px', '-7px'],
    ], $result);
  }

  /**
   * Tests that external scripts preserve their nonce and cross-origin settings.
   */
  public function testCouncilScriptNonce(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const target = document.body.appendChild(document.createElement('div'));
        target.id = 'script-target';
        const script = new Promise((resolve) => {
          const observer = new MutationObserver((mutations) => {
            for (const node of mutations.flatMap(({ addedNodes }) => [...addedNodes])) {
              if (node.localName === 'script' && node.src.startsWith('data:')) {
                observer.disconnect();
                resolve([node.nonce, node.getAttribute('crossorigin')]);
              }
            }
          });
          observer.observe(document.head, { childList: true });
        });
        await Drupal.ajax({ url: location.href }).commandExecutionQueue([
          { command: 'insert', selector: '#script-target', method: 'html',
            data: '<script src="data:text/javascript,void%200" nonce="sample-nonce" crossorigin></script>' },
        ]);
        return Promise.race([script, new Promise((resolve) => setTimeout(resolve, 1500))]);
      })()
      JS);
    $this->assertSame(['sample-nonce', ''], $result);
  }

  /**
   * Tests the various Ajax Commands.
   */
  public function testAjaxCommands(): void {
    $session = $this->getSession();
    $page = $this->getSession()->getPage();

    $form_path = 'ajax_forms_test_ajax_commands_form';
    $web_user = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($web_user);
    $this->drupalGet($form_path);

    // Tests the 'add_css' command.
    $page->pressButton("AJAX 'add_css' command");
    $this->assertWaitPageContains('my/file.css');
    $this->assertSession()->elementExists('css', 'link[href="my/file.css"]');
    $this->assertSession()->elementExists('css', 'link[href="https://example.com/css?family=Open+Sans"]');

    // Tests the 'after' command.
    $page->pressButton("AJAX 'After': Click to put something after the div");
    $this->assertWaitPageContains('<div id="after_div">Something can be inserted after this</div>This will be placed after');

    // Tests the 'alert' command.
    $page->pressButton("AJAX 'Alert': Click to alert");
    // Wait for the alert to appear.
    $page->waitFor(10, function () use ($session): bool {
      try {
        $session->getDriver()->getWebDriverSession()->alert()->getText();
        return TRUE;
      }
      catch (\Exception) {
        return FALSE;
      }
    });
    $alert_text = $this->getSession()->getDriver()->getWebDriverSession()->alert()->getText();
    $this->assertEquals('Alert', $alert_text);
    $this->getSession()->getDriver()->getWebDriverSession()->alert()->accept();

    $this->drupalGet($form_path);
    $page->pressButton("AJAX 'Announce': Click to announce");
    $this->assertWaitPageContains('<div id="drupal-live-announce" class="visually-hidden" aria-live="polite" aria-busy="false">Default announcement.</div>');

    $this->drupalGet($form_path);
    $page->pressButton("AJAX 'Announce': Click to announce with 'polite' priority");
    $this->assertWaitPageContains('<div id="drupal-live-announce" class="visually-hidden" aria-live="polite" aria-busy="false">Polite announcement.</div>');

    $this->drupalGet($form_path);
    $page->pressButton("AJAX 'Announce': Click to announce with 'assertive' priority");
    $this->assertWaitPageContains('<div id="drupal-live-announce" class="visually-hidden" aria-live="assertive" aria-busy="false">Assertive announcement.</div>');

    $this->drupalGet($form_path);
    $page->pressButton("AJAX 'Announce': Click to announce twice");
    $this->assertWaitPageContains('<div id="drupal-live-announce" class="visually-hidden" aria-live="assertive" aria-busy="false">Assertive announcement.' . "\nAnother announcement.</div>");

    // Tests the 'append' command.
    $page->pressButton("AJAX 'Append': Click to append something");
    $this->assertWaitPageContains('<div id="append_div">Append inside this divAppended text</div>');

    // Tests the 'before' command.
    $page->pressButton("AJAX 'before': Click to put something before the div");
    $this->assertWaitPageContains('Before text<div id="before_div">Insert something before this.</div>');

    // Tests the 'changed' command.
    $page->pressButton("AJAX changed: Click to mark div changed.");
    $this->assertWaitPageContains('<div id="changed_div" class="ajax-changed">');

    // Tests the 'changed' command using the second argument.
    // Refresh page for testing 'changed' command to same element again.
    $this->drupalGet($form_path);
    $page->pressButton("AJAX changed: Click to mark div changed with asterisk.");
    $this->assertWaitPageContains('<div id="changed_div" class="ajax-changed"> <div id="changed_div_mark_this">This div can be marked as changed or not. <abbr class="ajax-changed" title="Changed">*</abbr> </div></div>');

    // Tests the 'css' command.
    $page->pressButton("Set the '#box' div to be blue.");
    $this->assertWaitPageContains('<div id="css_div" style="background-color: blue;">');

    // Tests the 'data' command.
    $page->pressButton("AJAX data command: Issue command.");
    $this->assertTrue($page->waitFor(10, function () use ($session): bool {
      return 'test_value' === $session->evaluateScript('Drupal.ajax.elementData.get(document.querySelector("#data_div"), "test_key")');
    }));

    // Tests the 'html' command.
    $page->pressButton("AJAX html: Replace the HTML in a selector.");
    $this->assertWaitPageContains('<div id="html_div">replacement text</div>');

    // Tests the 'insert' command.
    $page->pressButton("AJAX insert: Let client insert based on #ajax['method'].");
    $this->assertWaitPageContains('<div id="insert_div">insert replacement textOriginal contents</div>');

    // Tests the toggleClass command.
    $page->pressButton("AJAX toggleClass command: Add the error class.");
    $this->assertWaitPageContains('<div id="toggle_class_div" class="error">Original contents</div>');

    // Tests the 'prepend' command.
    $page->pressButton("AJAX 'prepend': Click to prepend something");
    $this->assertWaitPageContains('<div id="prepend_div">prepended textSomething will be prepended to this div. </div>');

    // Tests the 'remove' command.
    $page->pressButton("AJAX 'remove': Click to remove text");
    $this->assertWaitPageContains('<div id="remove_div"></div>');

    // Tests the 'settings' command.
    $test_settings_command = <<<JS
Drupal.behaviors.testSettingsCommand = {
  attach: function (context, settings) {
    const result = document.createElement('div');
    result.className = 'test-settings-command';
    result.textContent = settings.ajax_forms_test.foo;
    document.body.append(result);
  }
};
JS;
    $session->executeScript($test_settings_command);
    // @todo Replace after https://www.drupal.org/project/drupal/issues/2616184
    $session->executeScript('document.querySelector("#edit-settings-command-example").dispatchEvent(new MouseEvent("mousedown", { bubbles: true, cancelable: true }));');
    $this->assertWaitPageContains('<div class="test-settings-command">42</div>');
  }

  /**
   * Asserts that page contains a text after waiting.
   *
   * @param string $text
   *   A needle text.
   *
   * @internal
   */
  protected function assertWaitPageContains(string $text): void {
    $page = $this->getSession()->getPage();
    $page->waitFor(10, function () use ($page, $text): bool {
      return stripos($page->getContent(), $text) !== FALSE;
    });
    $this->assertStringContainsString($text, $page->getContent());
  }

}
