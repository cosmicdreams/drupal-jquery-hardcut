<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Ajax;

use Drupal\Component\Utility\UrlHelper;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore nocontent
/**
 * Tests AJAX responses.
 */
#[Group('Ajax')]
#[RunTestsInSeparateProcesses]
class AjaxTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ajax_test', 'ajax_forms_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that AJAX works on admin routes.
   */
  public function testAjaxWithAdminRoute(): void {
    \Drupal::service('theme_installer')->install(['stark', 'default_admin']);
    $theme_config = \Drupal::configFactory()->getEditable('system.theme');
    $theme_config->set('admin', 'default_admin');
    $theme_config->set('default', 'stark');
    $theme_config->save();

    $account = $this->drupalCreateUser(['view the administration theme']);
    $this->drupalLogin($account);

    // First visit the site directly via the URL. This should render it in the
    // admin theme.
    $this->drupalGet('admin/ajax-test/theme');
    $assert = $this->assertSession();
    $assert->pageTextContains('Current theme: default_admin');

    // Now click the modal, which should use the front-end theme.
    $this->drupalGet('ajax-test/dialog');
    $assert->pageTextNotContains('Current theme: stark');
    $this->clickLink('Link 8 (ajax)');
    $assert->assertWaitOnAjaxRequest();

    $assert->pageTextContains('Current theme: stark');
    $assert->pageTextNotContains('Current theme: default_admin');
  }

  /**
   * Tests that AJAX loaded libraries are not retained between requests.
   *
   * @see https://www.drupal.org/node/2647916
   */
  public function testDrupalSettingsCachingRegression(): void {
    $this->drupalGet('ajax-test/dialog');
    $assert = $this->assertSession();
    $session = $this->getSession();

    // Insert a fake library into the already loaded library settings.
    $fake_library = 'fakeLibrary/fakeLibrary';
    $libraries = $session->evaluateScript("drupalSettings.ajaxPageState.libraries");
    $libraries = UrlHelper::compressQueryParameter(UrlHelper::uncompressQueryParameter($libraries) . ',' . $fake_library);
    $session->evaluateScript("drupalSettings.ajaxPageState.libraries = '$libraries';");
    $ajax_page_state = $session->evaluateScript("drupalSettings.ajaxPageState");
    $libraries = UrlHelper::uncompressQueryParameter($ajax_page_state['libraries']);
    // Test that the fake library is set.
    $this->assertStringContainsString($fake_library, $libraries);

    // Click on the AJAX link.
    $this->clickLink('Link 8 (ajax)');
    $assert->assertWaitOnAjaxRequest();

    // Test that the fake library is still set after the AJAX call.
    $ajax_page_state = $session->evaluateScript("drupalSettings.ajaxPageState");
    // Test that the fake library is set.
    $this->assertStringContainsString($fake_library, UrlHelper::uncompressQueryParameter($ajax_page_state['libraries']));

    // Reload the page, this should reset the loaded libraries and remove the
    // fake library.
    $this->drupalGet('ajax-test/dialog');
    $ajax_page_state = $session->evaluateScript("drupalSettings.ajaxPageState");
    $this->assertStringNotContainsString($fake_library, UrlHelper::uncompressQueryParameter($ajax_page_state['libraries']));

    // Click on the AJAX link again, and the libraries should still not contain
    // the fake library.
    $this->clickLink('Link 8 (ajax)');
    $assert->assertWaitOnAjaxRequest();
    $ajax_page_state = $session->evaluateScript("drupalSettings.ajaxPageState");
    $this->assertStringNotContainsString($fake_library, UrlHelper::uncompressQueryParameter($ajax_page_state['libraries']));
  }

  /**
   * Tests that various AJAX responses with DOM elements are correctly inserted.
   *
   * After inserting DOM elements, Drupal JavaScript behaviors should be
   * reattached and all top-level elements of type Node.ELEMENT_NODE need to be
   * part of the context.
   */
  public function testInsertAjaxResponse(): void {
    $render_single_root = [
      'pre-wrapped-div' => '<div class="pre-wrapped">pre-wrapped<script> var test;</script></div>',
      'pre-wrapped-span' => '<span class="pre-wrapped">pre-wrapped<script> var test;</script></span>',
      'pre-wrapped-whitespace' => ' <div class="pre-wrapped-whitespace">pre-wrapped-whitespace</div>' . "\n",
      'not-wrapped' => 'not-wrapped',
      'comment-string-not-wrapped' => '<!-- COMMENT -->comment-string-not-wrapped',
      'comment-not-wrapped' => '<!-- COMMENT --><div class="comment-not-wrapped">comment-not-wrapped</div>',
      'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect x="0" y="0" height="10" width="10" fill="green"></rect></svg>',
      'empty' => '',
    ];
    $render_multiple_root_unwrap = [
      'mixed' => ' foo <!-- COMMENT -->  foo bar<div class="a class"><p>some string</p></div> additional not wrapped strings, <!-- ANOTHER COMMENT --> <p>final string</p>',
      'top-level-only' => '<div>element #1</div><div>element #2</div>',
      'top-level-only-pre-whitespace' => ' <div>element #1</div><div>element #2</div> ',
      'top-level-only-middle-whitespace-span' => '<span>element #1</span> <span>element #2</span>',
      'top-level-only-middle-whitespace-div' => '<div>element #1</div> <div>element #2</div>',
    ];

    // An animated response with multiple roots gets a wrapper.
    $render_multiple_root_wrapper = [];
    foreach ($render_multiple_root_unwrap as $key => $render) {
      $render_multiple_root_wrapper["$key--effect"] = '<div>' . $render . '</div>';
    }

    $expected_renders = array_merge(
      $render_single_root,
      $render_multiple_root_wrapper,
      $render_multiple_root_unwrap
    );

    // Checking default process of wrapping Ajax content.
    foreach ($expected_renders as $render_type => $expected) {
      $this->assertInsert($render_type, $expected);
    }

    // Check that the theme hook can supply a wrapper for an empty response.
    $custom_wrapper = <<<'JS'
      Drupal.theme.ajaxWrapperNewContent = function (elements) {
        const wrapper = document.createElement('div');
        wrapper.className = 'div-wrapper-forever';
        wrapper.append(...elements);
        return [wrapper];
      };
      JS;
    $this->assertInsert('empty', '<div class="div-wrapper-forever"></div>', $custom_wrapper);

    // Checking inserting table elements.
    $expected = '<tr><td>table-row</td></tr>';
    $this->drupalGet('ajax-test/insert-table-wrapper');
    $this->clickLink('Link table-row');
    $this->assertWaitPageContains('<div class="ajax-target-wrapper"><table><tbody id="ajax-target">' . $expected . '</tbody></table></div>');
  }

  /**
   * Tests Ajax request entry points and their callback arguments.
   */
  public function testNativeRequestEntryLanes(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      window.runNativeLane = (lane) => {
        document.getElementById('request-lane')?.remove();
        const fixture = document.createElement('div');
        fixture.id = 'request-lane';
        fixture.innerHTML = `
          <div id="request-lane-echo"></div>
          <button type="button">Outside the form</button>
          <form>
            <input name="tags[]" value="a b">
            <input name="tags[]" value="+&amp;">
            <input name="disabled" value="omit" disabled>
            <button name="op" value="clicked">Inside the form</button>
          </form>
        `;
        document.body.append(fixture);
        const form = fixture.querySelector('form');
        if (lane === 'multipart') {
          form.enctype = 'multipart/form-data';
          const input = form.appendChild(document.createElement('input'));
          input.type = 'file';
          input.name = 'upload';
          const transfer = new DataTransfer();
          transfer.items.add(new File(['test\0upload'], 'payload.txt', { type: 'text/plain' }));
          input.files = transfer.files;
        }
        const button = fixture.querySelector(lane === 'outside' ? 'button' : 'form button');
        const ajax = Drupal.ajax({
          element: button,
          url: Drupal.url('ajax-test/echo-request'),
          httpMethod: lane === 'outside' ? 'GET' : 'POST',
          event: 'request-lane',
          setClick: true,
          progress: false,
          submit: { extra: ['a b', '+&'] },
        });
        const describe = (element) => {
          if (element === form) {
            return 'form';
          }
          return element === button ? 'button' : typeof element;
        };
        const trace = window.requestLaneTrace = [];
        const { beforeSerialize, beforeSend, beforeSubmit } = ajax;
        ajax.beforeSerialize = function (element, options) {
          trace.push(['serialize', describe(element), options === ajax.options]);
          return beforeSerialize.call(this, element, options);
        };
        ajax.beforeSubmit = function (values, element, options) {
          trace.push(['submit', describe(element)]);
          return beforeSubmit.call(this, values, element, options);
        };
        ajax.beforeSend = function (xhr, options) {
          trace.push(['send', xhr instanceof XMLHttpRequest]);
          return beforeSend.call(this, xhr, options);
        };
        ajax.options.context = button;
        const { success, complete } = ajax.options;
        ajax.options.success = function (data, status, xhr, element) {
          trace.push(['success', this === button, xhr instanceof XMLHttpRequest, describe(element)]);
          return success.apply(this, arguments);
        };
        ajax.options.complete = function (xhr, status, element) {
          trace.push(['complete', this === button, status, describe(element)]);
          return complete.apply(this, arguments);
        };
          const result = lane === 'execute'
          ? ajax.execute()
          : button.dispatchEvent(new Event('request-lane', { bubbles: true, cancelable: true }));
        window.requestLaneResult = lane === 'execute'
          ? [typeof result.network.then, typeof result.commands.then, typeof result.abort]
          : result;
        if (lane === 'execute') {
          result.commands.then(() => {
            window.requestLaneCommands = ajax.ajaxing;
          });
        }
      };
      JS);

    $direct_trace = [
      ['serialize', 'button', TRUE],
      ['send', TRUE],
      ['success', TRUE, TRUE, 'undefined'],
      ['complete', TRUE, 'success', 'undefined'],
    ];
    $form_trace = [
      ['serialize', 'form', FALSE],
      ['submit', 'form'],
      ['send', TRUE],
      ['success', TRUE, TRUE, 'undefined'],
      ['complete', TRUE, 'success', 'undefined'],
    ];
    foreach (['execute', 'outside', 'form', 'multipart'] as $lane) {
      $session->executeScript("window.runNativeLane('$lane')");
      $expected_result = $lane === 'execute' ? ['function', 'function', 'function'] : FALSE;
      $this->assertSame($expected_result, $session->evaluateScript('window.requestLaneResult'));
      $this->assertTrue($session->wait(10000, "document.getElementById('request-lane-echo').textContent.length > 0"));
      $request = json_decode($session->evaluateScript("document.getElementById('request-lane-echo').textContent"), TRUE, flags: JSON_THROW_ON_ERROR);
      $this->assertSame('XMLHttpRequest', $request['requestedWith']);
      $this->assertSame($lane === 'outside' ? 'GET' : 'POST', $request['method']);
      $expected_trace = in_array($lane, ['form', 'multipart'], TRUE) ? $form_trace : $direct_trace;
      $this->assertTrue($session->wait(10000, 'window.requestLaneTrace.length === ' . count($expected_trace)));
      $this->assertSame($expected_trace, $session->evaluateScript('window.requestLaneTrace'));
      if ($lane === 'execute') {
        $this->assertTrue($session->wait(10000, 'window.requestLaneCommands === false'));
      }
      if ($lane === 'outside') {
        $this->assertSame('', $request['body']);
        $this->assertStringContainsString('extra%5B%5D=a%20b&extra%5B%5D=%2B%26', $request['query']);
        $this->assertStringContainsString('_drupal_ajax=1', $request['query']);
      }
      elseif ($lane === 'multipart') {
        $this->assertStringStartsWith('multipart/form-data; boundary=', $request['contentType']);
        $this->assertSame(['a b', '+&'], $request['fields']['tags']);
        $this->assertSame(['a b', '+&'], $request['fields']['extra']);
        $this->assertSame('clicked', $request['fields']['op']);
        $this->assertArrayNotHasKey('disabled', $request['fields']);
        $this->assertSame(['payload.txt', 11, hash('sha256', "test\0upload")], $request['upload']);
      }
      else {
        $this->assertStringContainsString('extra%5B%5D=a+b&extra%5B%5D=%2B%26', $request['body']);
        $this->assertStringContainsString('_drupal_ajax=1', $request['body']);
        $this->assertStringNotContainsString('disabled=', $request['body']);
        if ($lane === 'form') {
          $this->assertStringStartsWith('tags%5B%5D=a+b&tags%5B%5D=%2B%26&op=clicked&', $request['body']);
        }
      }
    }
  }

  /**
   * Tests that native request failures surface as uncaught Ajax errors.
   */
  public function testNativeRequestError(): void {
    // The Ajax errors are the subject of this test.
    $this->failOnJavascriptConsoleErrors = FALSE;
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      window.nativeErrors = [];
      window.addEventListener('error', (event) => {
        window.nativeErrors.push(event.error?.name ?? event.message);
      });
      window.runNativeFailure = (url) => {
        window.nativeFailure = null;
        const ajax = Drupal.ajax({ url, progress: false });
        const handle = ajax.execute();
        handle.commands.then(
          () => ['resolved'],
          (error) => ['rejected', error.name, ajax.ajaxing],
        ).then((result) => {
          window.nativeFailure = result;
        });
      };
      JS);
    foreach (['ajax-test/native-error-does-not-exist', 'core/COPYRIGHT.txt'] as $index => $path) {
      $session->executeScript("window.runNativeFailure(Drupal.url('$path'))");
      $this->assertTrue($session->wait(10000, 'Boolean(window.nativeFailure)'));
      $this->assertSame(['rejected', 'AjaxError', FALSE], $session->evaluateScript('window.nativeFailure'));
      $this->assertSame(array_fill(0, $index + 1, 'AjaxError'), $session->evaluateScript('window.nativeErrors'));
    }
  }

  /**
   * Tests the shared transport.
   */
  public function testNativeRequestTransport(): void {
    $this->drupalGet('ajax-test/native-request');
    $session = $this->getSession();

    // The response and the load callback are available before control returns.
    $this->assertSame([
      200,
      'GET',
      'tag=a%20b',
      'XMLHttpRequest',
      TRUE,
    ], $session->evaluateScript(<<<'JS'
      (() => {
        let loaded = false;
        const request = Drupal.xhr({
          url: 'echo-native-request?tag=a%20b',
          async: false,
          beforeSend(xhr) {
            xhr.addEventListener('load', () => { loaded = true; });
          },
        });
        const response = JSON.parse(request.responseText);
        return [
          request.status, response.method, response.query,
          response.requestedWith, loaded,
        ];
      })()
      JS));

    $session->executeScript(<<<'JS'
      const body = new FormData();
      body.append('tags[]', 'a b');
      body.append('tags[]', '+&');
      body.append('upload', new File(['hello'], 'payload.txt'));
      Drupal.xhr({
        url: Drupal.url('ajax-test/echo-native-request'),
        method: 'POST',
        headers: { 'x-requested-with': 'explicit' },
        body,
        beforeSend(xhr, headers) {
          const originalHeader = headers.get('X-Requested-With');
          headers.set('X-Requested-With', 'changed');
          xhr.responseType = 'json';
          xhr.addEventListener('load', () => {
            window.nativeRequestResult = [originalHeader, xhr.status, xhr.response];
          });
        },
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.nativeRequestResult)'));
    [$header, $status, $response] = $session->evaluateScript('window.nativeRequestResult');
    $this->assertSame('explicit', $header);
    $this->assertSame(200, $status);
    $this->assertSame('POST', $response['method']);
    $this->assertSame('changed', $response['requestedWith']);
    $this->assertStringStartsWith('multipart/form-data; boundary=', $response['contentType']);
    $this->assertSame(['a b', '+&'], $response['fields']['tags']);
    $this->assertSame(['payload.txt', 5, hash('sha256', 'hello')], $response['upload']);

    $this->assertSame([TRUE, 0, NULL], $session->evaluateScript(<<<'JS'
      (() => {
        const url = Drupal.url('ajax-test/echo-native-request');
        const send = XMLHttpRequest.prototype.send;
        let sends = 0;
        XMLHttpRequest.prototype.send = function (...args) {
          sends++;
          return send.apply(this, args);
        };
        try {
          const vetoed = Drupal.xhr({ url, beforeSend: () => false });
          const otherOrigin = new URL(url, document.baseURI);
          otherOrigin.port = otherOrigin.port === '1234' ? '1235' : '1234';
          let crossOriginHeader;
          Drupal.xhr({
            url: otherOrigin.href,
            beforeSend(xhr, headers) {
              crossOriginHeader = headers.get('X-Requested-With');
              return false;
            },
          });
          return [vetoed.readyState === XMLHttpRequest.OPENED, sends, crossOriginHeader];
        }
        finally {
          XMLHttpRequest.prototype.send = send;
        }
      })()
      JS));

    $this->assertTrue($session->evaluateScript(<<<'JS'
      (() => {
        let aborted = false;
        const request = Drupal.xhr({
          url: Drupal.url('ajax-test/echo-native-request'),
          beforeSend(xhr) {
            xhr.addEventListener('abort', () => { aborted = true; });
          },
        });
        request.abort();
        return aborted;
      })()
      JS));
  }

  /**
   * Tests that the Ajax behavior runs the command queue.
   */
  public function testAjaxBehaviorRunsCommands(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $this->clickLink('Send native Ajax request');
    $this->assertSession()->assertExpectedAjaxRequest(1);
    $result = json_decode($this->assertSession()->elementExists('css', '#request-lane-echo')->getText(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('XMLHttpRequest', $result['requestedWith']);
    $this->assertSame('POST', $result['method']);
  }

  /**
   * Tests native progress polling and stopping from the update callback.
   */
  public function testNativeProgressBar(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $this->getSession()->executeScript(<<<'JS'
      const progress = new Drupal.ProgressBar('native-progress', (percentage, message, bar) => {
        if (percentage === 100) {
          bar.stopMonitoring();
        }
      }, 'POST');
      document.body.append(progress.elementNode);
      progress.startMonitoring(Drupal.url('ajax-test/echo-native-request?progress=1'), 10);
      JS);
    $this->assertSession()->assertExpectedAjaxRequest(1);
    $this->assertSession()->elementTextEquals('css', '#native-progress .progress__label', 'Finished');
    $this->assertSession()->elementTextEquals('css', '#native-progress .progress__description', 'POST XMLHttpRequest');
    $this->assertSession()->elementTextEquals('css', '#native-progress .progress__percentage', '100%');
  }

  /**
   * Tests the native default action of keyboard activation.
   */
  public function testKeyboardActivation(): void {
    $this->drupalGet('ajax-test/native-transport');
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const checkbox = document.body.appendChild(document.createElement('input'));
        checkbox.type = 'checkbox';
        const trace = [];
        const ajax = Drupal.ajax({ element: checkbox, event: 'click', keypress: true, url: location.href });
        ajax.eventResponse = (element, event) => trace.push([element.checked, event instanceof MouseEvent]);
        checkbox.dispatchEvent(new KeyboardEvent('keypress', { key: 'Enter', bubbles: true, cancelable: true }));
        const custom = document.body.appendChild(document.createElement('button'));
        const customAjax = Drupal.ajax({
          element: custom, event: 'fileUpload', keypress: true, url: location.href,
        });
        customAjax.eventResponse = (element, event) => trace.push([
          event.type, event.constructor === Event, event.bubbles, event.cancelable,
        ]);
        custom.dispatchEvent(new KeyboardEvent('keypress', { key: 'Enter', bubbles: true, cancelable: true }));
        return [checkbox.checked, trace];
      })()
      JS);
    $this->assertSame([TRUE, [[TRUE, TRUE], ['fileUpload', TRUE, TRUE, TRUE]]], $result);
  }

  /**
   * Tests preserving the submitter during an Ajax submission.
   */
  public function testNativeSubmitter(): void {
    $this->drupalGet('ajax-test/native-transport');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const result = document.body.appendChild(document.createElement('div'));
      result.id = 'request-lane-echo';
      const form = document.body.appendChild(document.createElement('form'));
      form.innerHTML = '<button name="op" value="second">Submit</button>';
      const ajax = Drupal.ajax({
        element: form, event: 'submit', url: Drupal.url('ajax-test/echo-request'),
      });
      ajax.form = form;
      const original = ajax.eventResponse;
      ajax.eventResponse = function (element, event) {
        return original.call(this, element, event);
      };
      form.requestSubmit(form.firstElementChild);
      JS);
    $this->assertSession()->assertExpectedAjaxRequest(1);
    $result = json_decode($this->assertSession()->elementExists('css', '#request-lane-echo')->getText(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('second', $result['fields']['op'] ?? NULL);
  }

  /**
   * Tests error completion when a synchronous native send throws.
   */
  public function testSynchronousNativeSendFailure(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const trace = [];
        window.alert = () => trace.push('alert');
        const form = document.body.appendChild(document.createElement('form'));
        const button = form.appendChild(document.createElement('button'));
        Drupal.behaviors.syncFailureProbe = {
          attach(context) { if (context === form) trace.push('attach'); },
          detach(context) { if (context === form) trace.push('detach'); },
        };
        const ajax = Drupal.ajax({ element: button, url: location.href, event: 'click' });
        const { beforeSend, error, complete } = ajax.options;
        ajax.options.async = false;
        ajax.options.beforeSend = function (...args) {
          const [xhr] = args;
          beforeSend.apply(this, args);
          xhr.send = () => {
            trace.push(['send', button.disabled, Boolean(form.querySelector('.ajax-progress'))]);
            throw new Error('Synchronous send failed');
          };
        };
        ajax.options.error = function (xhr, status, reason) {
          trace.push([status, reason.message]);
          error.call(this, xhr, status, reason);
        };
        ajax.options.complete = function (xhr, status) {
          try { complete.call(this, xhr, status); }
          catch (error) {
            if (!(error instanceof Drupal.AjaxError)) throw error;
          }
          trace.push('complete');
        };
        button.dispatchEvent(new MouseEvent('click', { cancelable: true }));
        return [ajax.ajaxing, button.disabled, Boolean(form.querySelector('.ajax-progress')), trace];
      })()
      JS);
    $this->assertSame([
      FALSE,
      FALSE,
      FALSE,
      ['detach', ['send', TRUE, TRUE], ['error', 'Synchronous send failed'], 'attach', 'complete'],
    ], $result);
  }

  /**
   * Tests native request completion separately from the Ajax command queue.
   */
  public function testNativeCommandRequest(): void {
    $this->drupalGet('ajax-test/native-transport');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const url = Drupal.url('ajax-test/echo-header');
      const ajax = Drupal.ajax({ url });
      const gate = new Promise((resolve) => {
        window.releaseNativeCommand = resolve;
      });
      const insert = ajax.commands.insert;
      ajax.commands.insert = function (...args) {
        return gate.then(() => insert.apply(this, args));
      };

      const request = Drupal.ajaxRequest({
        url,
        headers: { 'X-Test-Ajax-Send': 'native command' },
        beforeSend() {
          ajax.ajaxing = true;
        },
      }, ({ status, response, xhr }) => ajax.receiveResponse(response, status, xhr));
      window.nativeNetwork = request.network.then(({ status, xhr }) => [
        status, xhr.status, ajax.ajaxing,
      ]);
      request.commands.then(() => {
        window.nativeCommands = [ajax.ajaxing, document.getElementById('native-transport-result').textContent];
      });
      JS);
    $this->assertSame(['success', 200, TRUE], $session->evaluateScript('window.nativeNetwork'));
    $this->assertNull($session->evaluateScript('window.nativeCommands'));
    $session->executeScript('window.releaseNativeCommand();');
    $this->assertTrue($session->wait(10000, 'Boolean(window.nativeCommands)'));
    $this->assertSame([FALSE, 'native command'], $session->evaluateScript('window.nativeCommands'));

    $session->executeScript(<<<'JS'
      const url = Drupal.url('ajax-test/native-request');
      const aborted = Drupal.ajaxRequest({ url }, ({ status }) => status);
      aborted.abort();
      Promise.all([
        aborted.commands,
        Drupal.ajaxRequest({ url, beforeSend: () => false }, ({ status }) => status).commands,
        Drupal.ajaxRequest({ url }, ({ status }) => status).commands,
      ]).then((statuses) => {
        window.nativeFailureStatuses = statuses;
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.nativeFailureStatuses)'));
    $this->assertSame(['abort', 'canceled', 'parsererror'], $session->evaluateScript('window.nativeFailureStatuses'));
  }

  /**
   * Tests that a vetoed or aborted request leaves its element usable.
   */
  public function testVetoAndAbortCleanup(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const fixture = document.createElement('div');
      fixture.id = 'request-lane';
      fixture.innerHTML = `
        <div id="request-lane-echo"></div>
        <button type="button">Outside the form</button>
        <form><button name="op" value="clicked">Inside the form</button></form>
      `;
      document.body.append(fixture);
      const form = fixture.querySelector('form');
      const button = form.querySelector('button');
      const ajax = Drupal.ajax({
        element: button,
        url: Drupal.url('ajax-test/echo-request'),
        event: 'cleanup-lane',
        setClick: true,
      });
      let vetoes = 1;
      const { beforeSubmit } = ajax;
      ajax.beforeSubmit = function (...args) {
        if (vetoes-- > 0) {
          return false;
        }
        return beforeSubmit.apply(this, args);
      };
      const trigger = (element) => element.dispatchEvent(new Event('cleanup-lane', { bubbles: true, cancelable: true }));
      trigger(button);
      window.vetoState = [ajax.ajaxing, button.disabled, form.clk ?? null, fixture.querySelectorAll('.ajax-progress').length];
      // The vetoed attempt can be retried.
      trigger(button);

      // An abort from a global send handler restores the element.
      const outside = fixture.querySelector('button');
      const aborted = Drupal.ajax({
        element: outside,
        url: Drupal.url('ajax-test/echo-request'),
        event: 'cleanup-lane',
      });
      const hasProgress = () => Boolean(outside.nextElementSibling?.classList.contains('ajax-progress'));
      const { beforeSend } = aborted;
      aborted.beforeSend = function (xhr, settings) {
        beforeSend.call(this, xhr, settings);
        window.abortProgress = [hasProgress(), outside.disabled];
        xhr.abort();
      };
      trigger(outside);
      window.abortState = [window.abortProgress, aborted.ajaxing, outside.disabled, hasProgress()];
      JS);
    $this->assertSame([FALSE, FALSE, NULL, 0], $session->evaluateScript('window.vetoState'));
    $this->assertSame([[TRUE, TRUE], FALSE, FALSE, FALSE], $session->evaluateScript('window.abortState'));
    $this->assertTrue($session->wait(10000, "document.getElementById('request-lane-echo').textContent.length > 0"));
    $request = json_decode($session->evaluateScript("document.getElementById('request-lane-echo').textContent"), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertStringContainsString('op=clicked', $request['body']);
  }

  /**
   * Tests command completion and synchronous requests.
   */
  public function testNativeRequestCompletion(): void {
    $this->drupalGet('ajax-test/native-transport');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const url = Drupal.url('ajax-test/echo-header');

      // Extra success callbacks do not settle the commands early.
      const ajax = Drupal.ajax({ url });
      const insert = ajax.commands.insert;
      const gate = new Promise((resolve) => {
        window.releaseNativeCommands = resolve;
      });
      ajax.commands.insert = function (...args) {
        return gate.then(() => insert.apply(this, args));
      };
      ajax.options.success = [ajax.options.success, () => undefined];
      const handle = ajax.execute();
      handle.network.then(() => {
        window.nativeNetworkDone = true;
      });
      handle.commands.then(() => {
        window.nativeArrayCommands = [ajax.ajaxing, document.getElementById('native-transport-result').textContent];
      });

      // A synchronous request runs its callbacks before it returns.
      const sync = Drupal.ajax({ url, progress: false });
      sync.options.async = false;
      const { success } = sync.options;
      let ran = false;
      sync.options.success = function (...args) {
        ran = true;
        return success.apply(this, args);
      };
      sync.execute();
      window.syncRan = ran;
      JS);
    $this->assertTrue($session->evaluateScript('window.syncRan'));
    $this->assertTrue($session->wait(10000, 'window.nativeNetworkDone === true'));
    $this->assertNull($session->evaluateScript('window.nativeArrayCommands ?? null'));
    $session->executeScript('window.releaseNativeCommands();');
    $this->assertTrue($session->wait(10000, 'Boolean(window.nativeArrayCommands)'));
    $this->assertFalse($session->evaluateScript('window.nativeArrayCommands[0]'));
  }

  /**
   * Tests request options and cancellation on the native request path.
   */
  public function testNativeRequestOptions(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      document.body.insertAdjacentHTML('beforeend', '<div id="request-lane-echo"></div>');
      window.nativeResults = {};
      window.runNativeCase = (name, configure) => {
        const echo = document.getElementById('request-lane-echo');
        echo.textContent = '';
        const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-request'), progress: false, submit: { a: [1, 2] } });
        configure(ajax);
        ajax.execute().commands.then(() => {
          window.nativeResults[name] = JSON.parse(echo.textContent);
        });
      };
      JS);
    $request = function (string $name, string $configure) use ($session): array {
      $session->executeScript("window.runNativeCase('$name', $configure)");
      $this->assertTrue($session->wait(10000, "Boolean(window.nativeResults['$name'])"));
      return $session->evaluateScript("window.nativeResults['$name']");
    };

    $result = $request('traditional', '(ajax) => { ajax.options.traditional = true; }');
    $this->assertStringContainsString('a=1&a=2', $result['body']);

    // Header writes in beforeSend replace the prepared value, and the request
    // honors a changed URL and the XHR fields.
    $result = $request('beforeSend', <<<'JS'
      (ajax) => {
        const { beforeSend } = ajax.options;
        ajax.options.xhrFields = { withCredentials: true };
        ajax.options.beforeSend = function (xhr, settings) {
          xhr.setRequestHeader('X-Requested-With', 'Custom');
          settings.url += '&moved=1';
          const { complete } = settings;
          settings.complete = function (request, ...args) {
            window.nativeWithCredentials = request.withCredentials;
            return complete.call(this, request, ...args);
          };
          return beforeSend.call(this, xhr, settings);
        };
      }
      JS);
    $this->assertSame('Custom', $result['requestedWith']);
    $this->assertStringContainsString('moved=1', $result['query']);
    $this->assertTrue($session->evaluateScript('window.nativeWithCredentials'));

    // Uncached requests sent together get distinct cache-busting values.
    $this->assertTrue($session->evaluateScript(<<<'JS'
      (() => {
        const open = XMLHttpRequest.prototype.open;
        const urls = [];
        XMLHttpRequest.prototype.open = function (method, url, ...args) {
          urls.push(url);
          return open.call(this, method, url, ...args);
        };
        try {
          [1, 2].forEach(() => {
            const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-request'), progress: false, httpMethod: 'GET' });
            ajax.options.cache = false;
            ajax.execute();
          });
        }
        finally {
          XMLHttpRequest.prototype.open = open;
        }
        const values = urls.map((url) => new URL(url).searchParams.get('_'));
        return values.length === 2 && Boolean(values[0]) && values[0] !== values[1];
      })()
      JS));
    $this->assertTrue($session->wait(10000, 'Drupal.ajax.instances.every((instance) => !instance?.ajaxing)'));

    // An aborted request and a request aborted in beforeSend both restore the
    // element.
    $session->executeScript(<<<'JS'
      const button = document.body.appendChild(document.createElement('button'));
      button.id = 'native-abort';
      const ajax = Drupal.ajax({ element: button, url: Drupal.url('ajax-test/echo-request'), event: 'native-abort' });
      const handle = ajax.execute();
      window.nativeAbortSent = [button.disabled, Boolean(button.nextElementSibling?.classList.contains('ajax-progress'))];
      handle.abort();
      handle.network.then(({ status }) => {
        window.nativeAbort = [status, ajax.ajaxing, button.disabled, Boolean(button.nextElementSibling?.classList.contains('ajax-progress'))];
      });
      const cancelled = Drupal.ajax({ element: button, url: Drupal.url('ajax-test/echo-request'), event: 'native-cancel' });
      cancelled.beforeSend = (xhr) => xhr.abort();
      cancelled.execute().network.then(({ status }) => {
        window.nativeCancel = [status, cancelled.ajaxing, button.disabled];
      });
      JS);
    $this->assertSame([TRUE, TRUE], $session->evaluateScript('window.nativeAbortSent'));
    $this->assertTrue($session->wait(10000, 'Boolean(window.nativeAbort && window.nativeCancel)'));
    $this->assertSame(['abort', FALSE, FALSE, FALSE], $session->evaluateScript('window.nativeAbort'));
    $this->assertSame(['canceled', FALSE, FALSE], $session->evaluateScript('window.nativeCancel'));

    // A request made synchronous in beforeSend runs its callbacks before
    // returning, and a throwing beforeSend restores the element.
    $this->assertSame([TRUE, FALSE, FALSE, FALSE], $session->evaluateScript(<<<'JS'
      (() => {
        window.alert = () => {};
        const sync = Drupal.ajax({ url: Drupal.url('ajax-test/echo-request'), progress: false });
        const { beforeSend, success } = sync.options;
        let ran = false;
        sync.options.beforeSend = function (xhr, settings) {
          settings.async = false;
          return beforeSend.call(this, xhr, settings);
        };
        sync.options.success = function (...args) {
          ran = true;
          return success.apply(this, args);
        };
        sync.execute();

        const button = document.body.appendChild(document.createElement('button'));
        const throwing = Drupal.ajax({ element: button, url: Drupal.url('ajax-test/echo-request'), event: 'native-throw' });
        const prototypeBeforeSend = throwing.beforeSend;
        throwing.beforeSend = function (...args) {
          prototypeBeforeSend.apply(this, args);
          throw new Error('before send');
        };
        throwing.execute();
        return [ran, throwing.ajaxing, button.disabled, Boolean(button.nextElementSibling?.classList.contains('ajax-progress'))];
      })()
      JS));

    // The response is classified by the method beforeSend chose.
    $session->executeScript(<<<'JS'
      const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-request'), progress: false, httpMethod: 'GET' });
      const { beforeSend } = ajax.options;
      ajax.options.beforeSend = function (xhr, settings) {
        settings.type = 'HEAD';
        return beforeSend.call(this, xhr, settings);
      };
      ajax.execute().network.then(({ status }) => {
        window.nativeHead = status;
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.nativeHead)'));
    $this->assertSame('nocontent', $session->evaluateScript('window.nativeHead'));

    // A beforeSend veto on a multipart form does not send, and can be retried
    // at once.
    $this->assertSame([0, FALSE, FALSE, NULL], $session->evaluateScript(<<<'JS'
      (() => {
        document.body.insertAdjacentHTML('beforeend', '<form id="native-veto"><button name="op" value="clicked">Go</button><input type="file" name="upload"></form>');
        const form = document.getElementById('native-veto');
        const transfer = new DataTransfer();
        transfer.items.add(new File(['veto'], 'veto.txt', { type: 'text/plain' }));
        form.querySelector('input').files = transfer.files;
        const button = form.querySelector('button');
        const ajax = Drupal.ajax({ element: button, url: Drupal.url('ajax-test/echo-request'), event: 'native-veto', setClick: true });
        const { beforeSend } = ajax;
        let vetoes = 1;
        ajax.beforeSend = function (...args) {
          beforeSend.apply(this, args);
          return vetoes-- > 0 ? false : undefined;
        };
        const send = XMLHttpRequest.prototype.send;
        let sends = 0;
        XMLHttpRequest.prototype.send = function (...args) {
          sends++;
          return send.apply(this, args);
        };
        try {
          button.dispatchEvent(new Event('native-veto', { bubbles: true, cancelable: true }));
          const state = [sends, ajax.ajaxing, button.disabled, form.clk ?? null];
          document.getElementById('request-lane-echo').textContent = '';
          button.dispatchEvent(new Event('native-veto', { bubbles: true, cancelable: true }));
          return state;
        }
        finally {
          XMLHttpRequest.prototype.send = send;
        }
      })()
      JS));
    $this->assertTrue($session->wait(10000, "document.getElementById('request-lane-echo').textContent.length > 0"));
    $result = json_decode($session->evaluateScript("document.getElementById('request-lane-echo').textContent"), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('veto.txt', $result['upload'][0]);

    // A file input associated from outside the form is uploaded.
    $session->executeScript(<<<'JS'
      const echo = document.getElementById('request-lane-echo');
      echo.textContent = '';
      document.body.insertAdjacentHTML('beforeend', `
        <form id="external-file-form"><button name="op" value="clicked">Upload</button></form>
        <input type="file" name="upload" form="external-file-form">
      `);
      const form = document.getElementById('external-file-form');
      const input = document.querySelector('input[form="external-file-form"]');
      const transfer = new DataTransfer();
      transfer.items.add(new File(['external'], 'external.txt', { type: 'text/plain' }));
      input.files = transfer.files;
      const button = form.querySelector('button');
      Drupal.ajax({ element: button, url: Drupal.url('ajax-test/echo-request'), event: 'external-file', setClick: true, progress: false });
      button.dispatchEvent(new Event('external-file', { bubbles: true, cancelable: true }));
      JS);
    $this->assertTrue($session->wait(10000, "document.getElementById('request-lane-echo').textContent.length > 0"));
    $result = json_decode($session->evaluateScript("document.getElementById('request-lane-echo').textContent"), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertStringStartsWith('multipart/form-data; boundary=', $result['contentType']);
    $this->assertSame(['external.txt', 8, hash('sha256', 'external')], $result['upload']);
  }

  /**
   * Assert insert.
   *
   * @param string $render_type
   *   Render type.
   * @param string $expected
   *   Expected result.
   * @param string $script
   *   Script for additional theming.
   *
   * @internal
   */
  public function assertInsert(string $render_type, string $expected, string $script = ''): void {
    // Check insert to block element.
    $this->drupalGet('ajax-test/insert-block-wrapper');
    $this->getSession()->executeScript($script);
    $this->clickLink("Link html $render_type");
    $this->assertWaitPageContains('<div class="ajax-target-wrapper"><div id="ajax-target">' . $expected . '</div></div>');

    $this->drupalGet('ajax-test/insert-block-wrapper');
    $this->getSession()->executeScript($script);
    $this->clickLink("Link replaceWith $render_type");
    $this->assertWaitPageContains('<div class="ajax-target-wrapper">' . $expected . '</div>');

    // Check insert to inline element.
    $this->drupalGet('ajax-test/insert-inline-wrapper');
    $this->getSession()->executeScript($script);
    $this->clickLink("Link html $render_type");
    $this->assertWaitPageContains('<div class="ajax-target-wrapper"><span id="ajax-target-inline">' . $expected . '</span></div>');

    $this->drupalGet('ajax-test/insert-inline-wrapper');
    $this->getSession()->executeScript($script);
    $this->clickLink("Link replaceWith $render_type");
    $this->assertWaitPageContains('<div class="ajax-target-wrapper">' . $expected . '</div>');
  }

  /**
   * Asserts that page contains an expected value after waiting.
   *
   * @param string $expected
   *   A needle text.
   *
   * @internal
   */
  protected function assertWaitPageContains(string $expected): void {
    $page = $this->getSession()->getPage();
    $this->assertTrue($page->waitFor(10, function () use ($page, $expected): bool {
      // Clear content from empty styles and "processed" classes after effect.
      $content = str_replace([' class="processed"', ' processed', ' style=""'], '', $page->getContent());
      return stripos($content, $expected) !== FALSE;
    }), "Page contains expected value: $expected");
  }

  /**
   * Tests that Ajax errors are visible in the UI.
   */
  public function testUiAjaxException(): void {
    $themes = [
      'default_admin',
      'stark',
    ];
    \Drupal::service('theme_installer')->install($themes);

    foreach ($themes as $theme) {
      $theme_config = \Drupal::configFactory()->getEditable('system.theme');
      $theme_config->set('default', $theme);
      $theme_config->save();
      \Drupal::service('router.builder')->rebuildIfNeeded();

      $this->drupalGet('ajax-test/exception-link');
      $page = $this->getSession()->getPage();
      // We don't want the test to error out because of an expected Javascript
      // console error.
      $this->failOnJavascriptConsoleErrors = FALSE;
      // Click on the AJAX link.
      $this->clickLink('Ajax Exception');
      $this->assertSession()
        ->statusMessageContainsAfterWait("Oops, something went wrong. Check your browser's developer console for more details.", 'error');

      if ($theme === 'default_admin') {
        // Check that the message can be closed.
        $this->click('.button--dismiss');
        $this->assertTrue($page->find('css', '.messages--error')
          ->hasClass('visually-hidden'));
      }
    }

    // This is needed to avoid an unfinished AJAX request error from tearDown()
    // because this test intentionally does not complete all AJAX requests.
    $this->getSession()->executeScript("delete window.drupalActiveXhrCount");
  }

  /**
   * Tests ajax focus handling.
   */
  public function testAjaxFocus(): void {
    $this->drupalGet('/ajax_forms_test_get_form');

    $this->assertNotNull($select = $this->assertSession()->elementExists('css', '#edit-select'));
    $select->setValue('green');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $has_focus_id = $this->getSession()->evaluateScript('document.activeElement.id');
    $this->assertEquals('edit-select', $has_focus_id);

    $this->assertNotNull($checkbox = $this->assertSession()->elementExists('css', '#edit-checkbox'));
    $checkbox->check();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $has_focus_id = $this->getSession()->evaluateScript('document.activeElement.id');
    $this->assertEquals('edit-checkbox', $has_focus_id);

    $this->assertNotNull($textfield1 = $this->assertSession()->elementExists('css', '#edit-textfield'));
    $this->assertNotNull($textfield2 = $this->assertSession()->elementExists('css', '#edit-textfield-2'));
    $this->assertNotNull($textfield3 = $this->assertSession()->elementExists('css', '#edit-textfield-3'));

    // Test textfield with 'blur' event listener.
    $textfield1->setValue('Kittens say purr');
    $textfield2->focus();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $has_focus_id = $this->getSession()->evaluateScript('document.activeElement.id');
    $this->assertEquals('edit-textfield-2', $has_focus_id);

    // Moving focus elsewhere in the form leaves it outside the replaced group.
    $textfield1->focus();
    $textfield1->setValue('Owls hoot');
    $select->focus();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSame('edit-select', $this->getSession()->evaluateScript('document.activeElement.id'));

    // Moving focus to a control outside the form leaves it there as well.
    $textfield1->focus();
    $textfield1->setValue('Frogs croak');
    $this->getSession()->executeScript(<<<'JS'
      const outside = document.createElement('button');
      outside.id = 'outside-ajax-form';
      outside.textContent = 'Outside the form';
      document.querySelector('#edit-textfield').form.after(outside);
      outside.focus();
      JS);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSame('outside-ajax-form', $this->getSession()->evaluateScript('document.activeElement.id'));

    // Test textfield with 'change' event listener with refocus-blur set to
    // FALSE.
    $textfield3->focus();
    // Trigger the change event via JavaScript so it fires while focus is on
    // textfield-3, which is where the refocus must not return to.
    $this->getSession()->executeScript(<<<JS
const textfield2 = document.querySelector('#edit-textfield-2');
textfield2.value = 'Llamas say hi';
textfield2.dispatchEvent(new Event('change', { bubbles: true }));
JS);
    // Wait for the replaced form group to finish attaching its behaviors
    // before trying to change another field in that group.
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSame('edit-textfield-2', $this->getSession()->evaluateScript('document.activeElement.id'));

    // Test textfield with 'change' event.
    $textfield3->focus();
    $textfield3->setValue('Wasps buzz');
    $textfield3->blur();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $has_focus_id = $this->getSession()->evaluateScript('document.activeElement.id');
    $this->assertEquals('edit-textfield-3', $has_focus_id);

    $this->assertNotNull($email_field1 = $this->assertSession()->elementExists('css', '#edit-email-field-1'));
    // Test email field with 'blur' event listener.
    $email_field1->setValue('user@example.com');
    $email_field1->focus();
    $email_field1->blur();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $has_focus_id = $this->getSession()->evaluateScript('document.activeElement.id');
    $this->assertEquals('edit-email-field-1', $has_focus_id);
  }

  /**
   * Tests restoring focus when an Ajax insert replaces two forms at once.
   */
  public function testFocusInSharedReplacement(): void {
    $this->drupalGet('/ajax_forms_test_get_form');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const region = document.createElement('div');
      region.id = 'shared-replacement';
      region.innerHTML = `
        <form id="first-form" data-drupal-selector="first-form">
          <input id="shared-field--first" data-drupal-selector="shared-field">
        </form>
        <form id="second-form" data-drupal-selector="second-form">
          <input name="form_build_id" type="hidden" value="second-build">
          <input id="shared-field--old" data-drupal-selector="shared-field">
        </form>`;
      document.body.append(region);
      const replace = (formId, buildId, fieldId, replacement) => {
        const field = document.getElementById(fieldId);
        field.dataset.refocusBlur = 'true';
        field.focus();
        const ajax = Drupal.ajax({ element: field, url: location.href, event: 'test-focus' });
        return ajax.success([{
          command: 'insert',
          selector: '#shared-replacement',
          method: 'html',
          data: `
            <form id="first-form" data-drupal-selector="first-form">
              <input id="shared-field--first" data-drupal-selector="shared-field">
            </form>
            <form id="${formId}" data-drupal-selector="second-form">
              <input name="form_build_id" type="hidden" value="${buildId}">
              <div id="shared-field--description">Field description</div>
              ${replacement}
            </form>`,
        }], 'success');
      };
      window.replaceSharedForms = replace;
      replace('second-form', 'second-build--1', 'shared-field--old',
        '<input id="shared-field--exact" data-drupal-selector="shared-field">');
      JS);
    $this->assertJsCondition('document.activeElement.id === "shared-field--exact"');

    // Both form IDs change on rebuild. Match the stable form selector, then
    // fall back to the generated field ID without matching its description.
    $session->executeScript(<<<'JS'
      window.replaceSharedForms('second-form--1', 'second-build--2', 'shared-field--exact',
        '<input id="shared-field--rebuilt" data-drupal-selector="shared-field--rebuilt">');
      JS);
    $this->assertJsCondition('document.activeElement.id === "shared-field--rebuilt"');
  }

  /**
   * Tests URLs that are also a JS object property are not mistakenly trusted.
   */
  public function testPropertyUrl(): void {
    $this->drupalGet('ajax-test/property-link');
    $this->clickLink('Ajax constructor');

    // The AJAX request should fail because the URL is not trusted.
    $this->failOnJavascriptConsoleErrors = FALSE;
    $this->assertSession()
      ->statusMessageContainsAfterWait("Oops, something went wrong. Check your browser's developer console for more details.", 'error');

    // This is needed to avoid an unfinished AJAX request error from tearDown()
    // because this test intentionally does not complete all AJAX requests.
    $this->getSession()->executeScript("delete window.drupalActiveXhrCount");
  }

}
