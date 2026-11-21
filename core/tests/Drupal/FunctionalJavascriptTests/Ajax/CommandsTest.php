<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Ajax;

use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
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
        { command: 'css', selector: '#typed-command-input', argument: { minWidth: 42, 'opacity': 0.5, '--custom-value': 3 }, native: true },
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
   * Tests safe unmarked commands on a page without jQuery.
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
   * Tests unmarked commands when only the request adapter is loaded.
   */
  #[IgnoreDeprecations]
  public function testMixedUnmarkedCommands(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Using jQuery Ajax selector, method or effect inputs is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use native: true with a CSS selector and absolute values. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage("Javascript Deprecation: DataCommand's jQuery-data semantics is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use native: true with Drupal.ajax.elementData. See https://www.drupal.org/node/CHANGE_RECORD.");
    $this->drupalGet('ajax-test/global-events');
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const target = document.body.appendChild(document.createElement('div'));
        target.id = 'mixed-command';
        jQuery.cssHooks.probeStyle = {
          set(element, value) { element.dataset.probeStyle = value; },
        };
        const ajax = Drupal.ajax({ url: location.href });
        await ajax.commandExecutionQueue([
          { command: 'css', selector: '#mixed-command', argument: { probeStyle: 'hook' } },
          { command: 'data', selector: '#mixed-command', name: 'probeValue', value: 'legacy' },
          { command: 'data', native: true, selector: '#mixed-command', name: 'nativeValue', value: 'native' },
        ]);
        return [typeof Drupal.ajax.commandCompatibility, target.dataset.probeStyle,
          jQuery(target).data('probeValue'), jQuery(target).data('nativeValue'),
          Drupal.ajax.elementData.get(target, 'nativeValue')];
      })()
      JS);
    $driver = $this->getSession()->getDriver();
    assert($driver instanceof DrupalSelenium2Driver);
    $driver->processJavascriptDeprecations();
    $this->assertSame(['undefined', 'hook', 'legacy', NULL, 'native'], $result);
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
        await ajax.commands.css(ajax, { native: true, selector: '#native-selector', argument: styles });
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
          native: true, selector: '#native-selector:has(.skip)', name: 'hasChild', value: true,
        });
        const legacy = [];
        for (const selector of [':visible', 'span:eq(0)', 'span:contains(foo)', 'div:has(span)']) {
          try {
            ajax.commands.remove(ajax, { selector });
          }
          catch (error) {
            legacy.push(error.message.includes('requires the core/drupal.ajax.jquery library'));
          }
        }
        return [selected, Drupal.ajax.elementData.get(target, 'hasChild'),
          Object.keys(styles).filter((key) => key !== 'flex').map((key) => key.startsWith('--')
          ? target.style.getPropertyValue(key) : target.style[key]), target.style.flexGrow, legacy];
      })()
      JS);
    $this->assertSame([
      TRUE,
      TRUE,
      ['', '', '', '0.5', '2', '21px', '8px', '3px', '7'],
      '1',
      [TRUE, TRUE, TRUE, TRUE],
    ], $result);
  }

  /**
   * Tests that mixed-page insertion clones legacy data and handlers.
   */
  #[IgnoreDeprecations]
  public function testLegacyThemeClonesContent(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.theme.ajaxWrapperNewContent() with a jQuery collection is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use an array of native nodes. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalGet('ajax-test/global-events');
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const holder = document.body.appendChild(document.createElement('div'));
        holder.id = 'legacy-clone';
        holder.innerHTML = '<div></div><div></div>';
        let calls = 0;
        Drupal.theme.ajaxWrapperNewContent = (content) => {
          content.data('root', 1).on('probe-root', () => calls++);
          content.find('span').data('child', 2).on('probe-child', () => calls++);
          return content;
        };
        const ajax = Drupal.ajax({ url: location.href });
        await ajax.commands.insert(ajax, {
          selector: '#legacy-clone > div', method: 'append', data: '<p><span>Child</span></p>',
        });
        const values = [...holder.querySelectorAll('p')].map((node) => {
          const root = jQuery(node).trigger('probe-root');
          const child = root.find('span').trigger('probe-child');
          return [root.data('root'), child.data('child')];
        });
        return [values, calls];
      })()
      JS);
    $driver = $this->getSession()->getDriver();
    assert($driver instanceof DrupalSelenium2Driver);
    $driver->processJavascriptDeprecations();
    $this->assertSame([[[1, 2], [1, 2]], 4], $result);
  }

  /**
   * Tests mixed-page removal and compatibility data reads.
   */
  public function testCouncilMixedCommands(): void {
    $this->drupalGet('ajax_forms_test_ajax_commands_form', ['query' => ['legacy_commands' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const parent = document.createElement('div');
        parent.id = 'native-removal-parent';
        parent.innerHTML = '<div id="native-removed"></div>';
        document.body.append(parent);
        const node = parent.firstElementChild;
        let calls = 0;
        jQuery(node).data({ dialogType: 'modal', dialogTitle: 'Settings' }).on('probe', () => ++calls);
        const values = ['dialog-type', 'dialog-title'].map((key) => Drupal.ajax.elementData.get(node, key));
        const ajax = Drupal.ajax({ url: location.href });
        await ajax.commandExecutionQueue([
          { command: 'insert', selector: '#native-removal-parent', method: 'html', data: '' },
        ]);
        parent.append(node);
        jQuery(node).trigger('probe');
        return [values, jQuery.hasData(node), calls];
      })()
      JS);
    $this->assertSame([['modal', 'Settings'], FALSE, 0], $result);
  }

  /**
   * Tests the type passed to overridden themes without the command adapter.
   */
  #[IgnoreDeprecations]
  public function testCouncilThemeOverrides(): void {
    foreach (['ajaxWrapperNewContent', 'ajaxWrapperMultipleRootElements'] as $hook) {
      $this->expectUserDeprecationMessage("Javascript Deprecation: Drupal.theme.$hook() with a jQuery collection is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use an array of native nodes. See https://www.drupal.org/node/CHANGE_RECORD.");
    }
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.Ajax#progress.element is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native elementNode property. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.theme.ajaxProgressBar() with a jQuery collection is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native progress bar element. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalGet('ajax-test/global-events');
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const trace = [typeof Drupal.ajax.commandCompatibility];
        const target = document.createElement('div');
        target.id = 'theme-target';
        document.body.append(target);
        const ajax = Drupal.ajax({ url: location.href, element: target });
        const hooks = ['ajaxWrapperNewContent', 'ajaxWrapperMultipleRootElements', 'ajaxProgressBar'];
        const originals = Object.fromEntries(hooks.map((name) => [name, Drupal.theme[name]]));
        const assign = (native) => hooks.forEach((name) => {
          const override = (input, ...args) => {
            trace.push(native ? (name === 'ajaxProgressBar' ? input instanceof HTMLElement : Array.isArray(input)) : input instanceof jQuery);
            return originals[name](input, ...args);
          };
          Drupal.theme[name] = native ? (Drupal.ajax.useNativeCallback?.(override) ?? override) : override;
        });
        for (const native of [false, true]) {
          assign(native);
          await ajax.commandExecutionQueue([
            { command: 'insert', selector: '#theme-target', method: 'html',
              data: '<span>First</span><span>Second</span>', effect: 'fade', speed: 0 },
          ]);
          ajax.setProgressIndicatorBar();
        }
        const nodes = target.querySelectorAll('span');
        return [trace, originals.ajaxWrapperMultipleRootElements(nodes)[0].children.length,
          ajax.progress.element[0] === ajax.progress.elementNode];
      })()
      JS);
    $driver = $this->getSession()->getDriver();
    assert($driver instanceof DrupalSelenium2Driver);
    $driver->processJavascriptDeprecations();
    $this->assertSame([['undefined', TRUE, TRUE, TRUE, TRUE, TRUE, TRUE], 2, TRUE], $result);
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
   * Tests the original command contracts on a page with the legacy library.
   */
  #[IgnoreDeprecations]
  public function testLegacyCommands(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Using jQuery Ajax selector, method or effect inputs is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a CSS selector and supported native command options. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage("Javascript Deprecation: DataCommand's jQuery-data semantics is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use Drupal's element data API. See https://www.drupal.org/node/CHANGE_RECORD.");
    $this->expectUserDeprecationMessage('Javascript Deprecation: The invoke Ajax command is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a native command for the operation, or define an explicit command in your extension. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form', ['query' => ['legacy_commands' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const fixture = document.createElement('div');
      fixture.id = 'legacy-commands';
      fixture.innerHTML = '<div class="target" style="width: 30px"></div><div class="target"></div>';
      document.body.append(fixture);

      jQuery.cssHooks.fixtureColor = {
        set(element, value) {
          element.dataset.color = value;
        }
      };
      jQuery.fn.fixtureInvoke = function (attribute, value) {
        return this.attr(attribute, value);
      };
      jQuery.fn.fixtureInsert = function (nodes) {
        return this.append(nodes).attr('data-plugin-insert', 'yes');
      };

      const ajax = Drupal.ajax({ url: location.href });
      ajax.commandExecutionQueue([
        { command: 'css', selector: '#legacy-commands .target:first', argument: { width: '+=12', fixtureColor: 'blue' } },
        { command: 'data', selector: '#legacy-commands .target:first', name: 'fixture-key', value: { stored: true } },
        { command: 'invoke', selector: '#legacy-commands .target:first', method: 'fixtureInvoke', args: ['data-invoked', 'yes'] },
        { command: 'insert', selector: '#legacy-commands .target:first', method: 'fixtureInsert', data: '<span>plugin</span>' },
        { command: 'remove', selector: '#legacy-commands .target:last' },
      ], 'success').then(() => {
        fixture.dataset.completed = 'yes';
      });
      JS);

    $this->assertJsCondition("document.querySelector('#legacy-commands').dataset.completed === 'yes'");
    $target = $this->assertSession()->elementExists('css', '#legacy-commands .target');
    $this->assertSame('width: 42px;', $target->getAttribute('style'));
    $this->assertSame('blue', $target->getAttribute('data-color'));
    $this->assertSame('yes', $target->getAttribute('data-invoked'));
    $this->assertSame('yes', $target->getAttribute('data-plugin-insert'));
    $this->assertSame('plugin', $target->getText());
    $this->assertSession()->elementsCount('css', '#legacy-commands .target', 1);
    $this->assertTrue($session->evaluateScript("jQuery.data(document.querySelector('#legacy-commands .target'), 'fixtureKey').stored"));
    $this->assertSame(['stored' => TRUE], $session->evaluateScript("Drupal.ajax.elementData.get(document.querySelector('#legacy-commands .target'), 'fixture-key')"));
  }

  /**
   * Tests the various Ajax Commands.
   */
  #[IgnoreDeprecations]
  public function testAjaxCommands(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Using jQuery Ajax selector, method or effect inputs is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a CSS selector and supported native command options. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage('Javascript Deprecation: The invoke Ajax command is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a native command for the operation, or define an explicit command in your extension. See https://www.drupal.org/node/CHANGE_RECORD.');
    $session = $this->getSession();
    $page = $this->getSession()->getPage();

    $form_path = 'ajax_forms_test_ajax_commands_form';
    $web_user = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($web_user);
    $this->drupalGet($form_path, ['query' => ['legacy_requests' => 1]]);

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

    $this->drupalGet($form_path, ['query' => ['legacy_requests' => 1]]);
    $page->pressButton("AJAX 'Announce': Click to announce");
    $this->assertWaitPageContains('<div id="drupal-live-announce" class="visually-hidden" aria-live="polite" aria-busy="false">Default announcement.</div>');

    $this->drupalGet($form_path, ['query' => ['legacy_requests' => 1]]);
    $page->pressButton("AJAX 'Announce': Click to announce with 'polite' priority");
    $this->assertWaitPageContains('<div id="drupal-live-announce" class="visually-hidden" aria-live="polite" aria-busy="false">Polite announcement.</div>');

    $this->drupalGet($form_path, ['query' => ['legacy_requests' => 1]]);
    $page->pressButton("AJAX 'Announce': Click to announce with 'assertive' priority");
    $this->assertWaitPageContains('<div id="drupal-live-announce" class="visually-hidden" aria-live="assertive" aria-busy="false">Assertive announcement.</div>');

    $this->drupalGet($form_path, ['query' => ['legacy_requests' => 1]]);
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
    $this->drupalGet($form_path, ['query' => ['legacy_requests' => 1]]);
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

    // Loading the legacy handler through the response exposes data already
    // written by a native command.
    $this->assertTrue($session->evaluateScript('Drupal.ajax.commandCompatibility === undefined'));
    $session->executeScript(<<<'JS'
      const remove = jQuery.fn.remove;
      jQuery.fn.remove = function (...args) {
        if (this[0]?.id === 'remove_text') {
          document.querySelector('#remove_div').dataset.overridden = 'yes';
        }
        return remove.apply(this, args);
      };
      JS);
    $page->pressButton("AJAX invoke command: Invoke addClass() method.");
    $this->assertWaitPageContains('<div id="invoke_div" class="error">Original contents</div>');
    $this->assertSame('test_value', $session->evaluateScript('jQuery("#data_div").data("test_key")'));
    $session->executeScript(<<<'JS'
      const element = document.querySelector('#data_div');
      element.setAttribute('data-test_key', 'attribute');
      jQuery.data(element, 'test_key', 'replacement');
      JS);
    $this->assertSame('replacement', $session->evaluateScript('Drupal.ajax.elementData.get(document.querySelector("#data_div"), "test_key")'));
    $session->executeScript('jQuery("#data_div").removeData("test_key");');
    $this->assertSame('attribute', $session->evaluateScript('Drupal.ajax.elementData.get(document.querySelector("#data_div"), "test_key")'));

    // Tests the 'prepend' command.
    $page->pressButton("AJAX 'prepend': Click to prepend something");
    $this->assertWaitPageContains('<div id="prepend_div">prepended textSomething will be prepended to this div. </div>');

    // Tests the 'remove' command.
    $page->pressButton("AJAX 'remove': Click to remove text");
    $this->assertWaitPageContains('<div id="remove_div" data-overridden="yes"></div>');

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
