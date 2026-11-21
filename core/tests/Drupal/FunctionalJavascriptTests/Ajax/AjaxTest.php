<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Ajax;

use Drupal\Component\Utility\UrlHelper;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore Completeajax handleajax jqxhr nocontent prefilter prefilters Successajax
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
  #[IgnoreDeprecations]
  public function testInsertAjaxResponse(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.theme.ajaxWrapperMultipleRootElements() with a jQuery collection is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use an array of native nodes. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.theme.ajaxWrapperNewContent() with a jQuery collection is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use an array of native nodes. See https://www.drupal.org/node/CHANGE_RECORD.');
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

    // This is temporary behavior for BC reason.
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

    // Checking custom ajaxWrapperMultipleRootElements wrapping.
    $custom_wrapper_multiple_root = <<<JS
    (function($, Drupal){
      Drupal.theme.ajaxWrapperMultipleRootElements = function (elements) {
        return $('<div class="my-favorite-div"></div>').append(elements);
      };
    }(jQuery, Drupal));
JS;
    $expected = '<div class="my-favorite-div"><span>element #1</span> <span>element #2</span></div>';
    $this->assertInsert('top-level-only-middle-whitespace-span--effect', $expected, $custom_wrapper_multiple_root);

    // Checking custom ajaxWrapperNewContent wrapping.
    $custom_wrapper_new_content = <<<JS
    (function($, Drupal){
      Drupal.theme.ajaxWrapperNewContent = function (elements) {
        return $('<div class="div-wrapper-forever"></div>').append(elements);
      };
    }(jQuery, Drupal));
JS;
    $expected = '<div class="div-wrapper-forever"></div>';
    $this->assertInsert('empty', $expected, $custom_wrapper_new_content);

    // Checking inserting table elements.
    $expected = '<tr><td>table-row</td></tr>';
    $this->drupalGet('ajax-test/insert-table-wrapper');
    $this->clickLink('Link table-row');
    $this->assertWaitPageContains('<div class="ajax-target-wrapper"><table><tbody id="ajax-target">' . $expected . '</tbody></table></div>');
  }

  /**
   * Tests that jQuery's global Ajax events are triggered at the correct time.
   */
  public function testGlobalEvents(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();
    $expected_event_order = implode('', ['ajaxSuccess', 'ajaxComplete', 'ajaxStop']);

    $this->drupalGet('ajax-test/global-events');

    // Ensure that a non-Drupal Ajax request triggers the expected events, in
    // the correct order, a single time.
    $session->executeScript('jQuery.get(Drupal.url("core/COPYRIGHT.txt"))');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementTextEquals('css', '#test_global_events_log', $expected_event_order);
    $assert->elementTextEquals('css', '#test_global_events_log2', $expected_event_order);

    // Ensure that an Ajax request to a Drupal Ajax response, but that was not
    // initiated with Drupal.Ajax(), triggers the expected events, in the
    // correct order, a single time. We expect $expected_event_order to appear
    // twice in each log element, because Drupal Ajax response commands (such
    // as the one to clear the log element) are only executed for requests
    // initiated with Drupal.Ajax(), and these elements already contain the
    // text that was added above.
    $session->executeScript('jQuery.get(Drupal.url("ajax-test/global-events/clear-log"))');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementTextEquals('css', '#test_global_events_log', str_repeat($expected_event_order, 2));
    $assert->elementTextEquals('css', '#test_global_events_log2', str_repeat($expected_event_order, 2));

    // Ensure that a Drupal Ajax request triggers the expected events, in the
    // correct order, a single time.
    // - We expect the first log element to list the events exactly once,
    //   because the Ajax response clears it, and we expect the events to be
    //   triggered after the commands are executed.
    // - We expect the second log element to list the events exactly three
    //   times, because it already contains the two from the code that was
    //   already executed above. This additional log element that isn't cleared
    //   by the response's command ensures that the events weren't triggered
    //   additional times before the response commands were executed.
    $this->click('#test_global_events_drupal_ajax_link');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementTextEquals('css', '#test_global_events_log', $expected_event_order);
    $assert->elementTextEquals('css', '#test_global_events_log2', str_repeat($expected_event_order, 3));
  }

  /**
   * Tests the distinct request paths into Drupal Ajax.
   */
  public function testRequestEntryLanes(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form', ['query' => ['legacy_requests' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const jqueryAjax = jQuery.ajax;
      window.jqueryAjaxCalls = 0;
      jQuery.ajax = function (...args) {
        window.jqueryAjaxCalls++;
        return jqueryAjax.apply(this, args);
      };
      window.runRequestLane = (lane) => {
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
        const trace = window.requestLaneTrace = [];
        let legacyForm;
        jQuery(form).on('form-pre-serialize form-submit-validate form-submit-notify', (event, argument) => {
          if (event.type === 'form-pre-serialize') {
            legacyForm = argument;
          }
          trace.push(event.type);
        });
        const { beforeSerialize, beforeSend, beforeSubmit } = ajax;
        ajax.beforeSerialize = function (element, options) {
          trace.push(['serialize', element === button ? 'button' : element === legacyForm && element?.[0] === form ? 'legacy-form' : 'native-form', options === ajax.options]);
          beforeSerialize.call(this, element, options);
          return lane !== 'execute' && lane !== 'veto';
        };
        ajax.beforeSubmit = Drupal.ajax.useNativeForm(function (values, element, options) {
          trace.push(['submit', element === form, options === ajax.options]);
          return beforeSubmit.call(this, values, element, options);
        });
        ajax.beforeSend = function (xhr, options) {
          trace.push(['send', xhr instanceof XMLHttpRequest]);
          if (lane === 'multipart') {
            window.requestLaneParts = Array.from(options.data, ([name, value]) =>
              [name, value instanceof File ? [value.name, value.size] : value],
            );
          }
          return beforeSend.call(this, xhr, options);
        };
        ajax.options.context = button;
        const { success, complete } = ajax.options;
        ajax.options.success = function (data, status, xhr, element) {
          trace.push(['success', this === button, element?.[0] === form]);
          return success.apply(this, arguments);
        };
        ajax.options.complete = function (xhr, status, element) {
          trace.push(['complete', this === button, element?.[0] === form]);
          return complete.apply(this, arguments);
        };
        const event = new Event('request-lane', { bubbles: true, cancelable: true });
        window.requestLaneResult = lane === 'execute'
          ? !!ajax.execute()
          : button.dispatchEvent(event);
      };
      JS);

    $direct_trace = [
      ['serialize', 'button', TRUE],
      ['send', TRUE],
      ['success', TRUE, FALSE],
      ['complete', TRUE, FALSE],
    ];
    foreach (['execute', 'outside', 'form', 'multipart', 'veto'] as $lane) {
      $session->executeScript("window.runRequestLane('$lane')");
      $this->assertSame($lane === 'execute', $session->evaluateScript('window.requestLaneResult'));
      if ($lane === 'veto') {
        $this->assertSame([
          'form-pre-serialize',
          ['serialize', 'legacy-form', FALSE],
        ], $session->evaluateScript('window.requestLaneTrace'));
        $this->assertSession()->elementTextEquals('css', '#request-lane-echo', '');
        continue;
      }

      $this->assertTrue($session->wait(10000, "document.getElementById('request-lane-echo').textContent.length > 0 && jQuery.active === 0"));
      $request = json_decode($session->evaluateScript("document.getElementById('request-lane-echo').textContent"), TRUE, flags: JSON_THROW_ON_ERROR);
      $this->assertSame('XMLHttpRequest', $request['requestedWith']);
      $this->assertSame($lane === 'outside' ? 'GET' : 'POST', $request['method']);
      $this->assertSame(in_array($lane, ['form', 'multipart'], TRUE) ? [
        'form-pre-serialize',
        ['serialize', 'legacy-form', FALSE],
        ['submit', TRUE, FALSE],
        'form-submit-validate',
        ['send', TRUE],
        'form-submit-notify',
        ['success', TRUE, TRUE],
        ['complete', TRUE, TRUE],
      ] : $direct_trace, $session->evaluateScript('window.requestLaneTrace'));
      if ($lane === 'outside') {
        $this->assertSame('', $request['body']);
        $this->assertStringContainsString('extra%5B%5D=a%20b&extra%5B%5D=%2B%26', $request['query']);
        $this->assertStringContainsString('_drupal_ajax=1', $request['query']);
      }
      elseif ($lane === 'multipart') {
        $this->assertStringStartsWith('multipart/form-data; boundary=', $request['contentType']);
        $this->assertSame([
          ['tags[]', 'a b'],
          ['tags[]', '+&'],
          ['op', 'clicked'],
          ['upload', ['payload.txt', 11]],
          ['extra[]', 'a b'],
          ['extra[]', '+&'],
          ['_drupal_ajax', '1'],
        ], array_slice($session->evaluateScript('window.requestLaneParts'), 0, 7));
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
        else {
          $this->assertStringNotContainsString('tags%5B%5D=', $request['body']);
          $this->assertStringNotContainsString('op=', $request['body']);
        }
      }
    }
    // Core requests use the native transport even with the adapter present.
    $this->assertSame(0, $session->evaluateScript('window.jqueryAjaxCalls'));
  }

  /**
   * Tests the request paths without the jQuery adapter.
   *
   * Core requests use the native request path. The jQuery adapter is detached
   * for each request, so callbacks receive the form element, no jQuery Form
   * plugin events fire, and jQuery.ajax() is never called.
   */
  public function testNativeRequestEntryLanes(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form', ['query' => ['legacy_requests' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const jqueryAjax = jQuery.ajax;
      window.jqueryAjaxCalls = 0;
      jQuery.ajax = function (...args) {
        window.jqueryAjaxCalls++;
        return jqueryAjax.apply(this, args);
      };
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
        jQuery(form).on('form-pre-serialize form-submit-validate form-submit-notify', (event) => {
          trace.push(event.type);
        });
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
        const adapter = Drupal.ajax.compatibility;
        ajax.options.success = function (data, status, xhr, element) {
          trace.push(['success', this === button, xhr instanceof XMLHttpRequest, describe(element)]);
          const result = success.apply(this, arguments);
          // Keep the adapter detached until the commands have run.
          Promise.resolve(result).finally(() => {
            Drupal.ajax.compatibility = adapter;
          });
          return result;
        };
        ajax.options.complete = function (xhr, status, element) {
          trace.push(['complete', this === button, status, describe(element)]);
          return complete.apply(this, arguments);
        };
        Drupal.ajax.compatibility = null;
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
      ['success', TRUE, TRUE, 'form'],
      ['complete', TRUE, 'success', 'form'],
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
      $this->assertTrue($session->wait(10000, 'window.requestLaneTrace.length === ' . count($expected_trace) . ' && Boolean(Drupal.ajax.compatibility)'));
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
    $this->assertSame(0, $session->evaluateScript('window.jqueryAjaxCalls'));
  }

  /**
   * Tests that native request failures surface as uncaught Ajax errors.
   */
  public function testNativeRequestError(): void {
    // The Ajax errors are the subject of this test.
    $this->failOnJavascriptConsoleErrors = FALSE;
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form', ['query' => ['legacy_requests' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      window.nativeErrors = [];
      window.addEventListener('error', (event) => {
        window.nativeErrors.push(event.error?.name ?? event.message);
      });
      window.runNativeFailure = (url) => {
        window.nativeFailure = null;
        const adapter = Drupal.ajax.compatibility;
        // Keep the adapter detached until the request has completed.
        Drupal.ajax.compatibility = null;
        const ajax = Drupal.ajax({ url, progress: false });
        const handle = ajax.execute();
        handle.commands.then(
          () => ['resolved'],
          (error) => ['rejected', error.name, ajax.ajaxing],
        ).then((result) => {
          Drupal.ajax.compatibility = adapter;
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
   * Tests that the adapter handle carries the native request promises.
   */
  public function testAdapterRequestHandle(): void {
    $this->drupalGet('ajax-test/global-events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const url = Drupal.url('ajax-test/echo-header');
      const ajax = Drupal.ajax({ url });
      const insert = ajax.commands.insert;
      const gate = new Promise((resolve) => {
        window.releaseAdapterCommands = resolve;
      });
      ajax.commands.insert = function (...args) {
        return gate.then(() => insert.apply(this, args));
      };
      ajax.options.headers = { 'X-Test-Ajax-Send': 'adapter handle' };
      const handle = ajax.execute();
      window.adapterHandle = [typeof handle.done, typeof handle.network.then, typeof handle.commands.then];
      handle.network.then(({ status, xhr }) => {
        window.adapterNetwork = [status, xhr === handle, ajax.ajaxing];
      });
      handle.commands.then(() => {
        window.adapterCommands = [ajax.ajaxing, document.getElementById('test_global_events_log').textContent];
      });

      // A failure before sending returns a rejected handle.
      window.alert = () => {};
      const failing = Drupal.ajax({ url });
      failing.beforeSerialize = () => {
        throw new Error('before serialize');
      };
      const rejected = failing.execute();
      window.adapterRejected = [rejected.state()];
      rejected.commands.catch((error) => {
        window.adapterRejected.push(error.message);
      });
      JS);
    $this->assertSame(['function', 'function', 'function'], $session->evaluateScript('window.adapterHandle'));
    $this->assertTrue($session->wait(10000, 'Boolean(window.adapterNetwork)'));
    $this->assertSame(['success', TRUE, TRUE], $session->evaluateScript('window.adapterNetwork'));
    $this->assertNull($session->evaluateScript('window.adapterCommands ?? null'));
    $session->executeScript('window.releaseAdapterCommands();');
    $this->assertTrue($session->wait(10000, 'Boolean(window.adapterCommands)'));
    // The delayed global completion events run before the commands promise
    // settles, and log themselves on this page.
    $this->assertSame([FALSE, 'adapter handleajaxSuccessajaxCompleteajaxStop'], $session->evaluateScript('window.adapterCommands'));
    $this->assertSame(['rejected', 'before serialize'], $session->evaluateScript('window.adapterRejected'));
  }

  /**
   * Tests the shared transport on a page without jQuery.
   */
  public function testNativeRequestTransport(): void {
    $this->drupalGet('ajax-test/native-request');
    $session = $this->getSession();

    // The response and the load callback are available before control returns.
    $this->assertSame([
      'undefined',
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
          typeof jQuery, request.status, response.method, response.query,
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
   * Tests the Ajax behavior and command queue without a compatibility library.
   */
  public function testAjaxPageWithoutJquery(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof jQuery'));
    $this->clickLink('Send native Ajax request');
    $this->assertSession()->assertExpectedAjaxRequest(1);
    $result = json_decode($this->assertSession()->elementExists('css', '#request-lane-echo')->getText(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('XMLHttpRequest', $result['requestedWith']);
    $this->assertSame('POST', $result['method']);
    $this->assertSame('undefined', $session->evaluateScript('typeof jQuery'));
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
      document.body.append(progress.element);
      progress.startMonitoring(Drupal.url('ajax-test/echo-native-request?progress=1'), 10);
      JS);
    $this->assertSession()->assertExpectedAjaxRequest(1);
    $this->assertSession()->elementTextEquals('css', '#native-progress .progress__label', 'Finished');
    $this->assertSession()->elementTextEquals('css', '#native-progress .progress__description', 'POST XMLHttpRequest');
    $this->assertSession()->elementTextEquals('css', '#native-progress .progress__percentage', '100%');
  }

  /**
   * Tests legacy overrides against the production Ajax runtime.
   */
  #[IgnoreDeprecations]
  public function testLegacyAjaxOverrides(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.Ajax#$form is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use Drupal.Ajax#form. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalGet('ajax-test/global-events', ['query' => ['legacy_overrides' => 1]]);
    $session = $this->getSession();
    $this->assertJsCondition('Boolean(window.legacyAjaxOverrides)');
    $this->assertTrue($session->evaluateScript('legacyAjaxOverrides.ajax.$form === legacyAjaxOverrides.$form'));

    $session->executeScript(<<<'JS'
      jQuery('#legacy-ajax-overrides button').trigger('mousedown.legacyOverrides');
      JS);
    $this->assertSame(0, $session->evaluateScript('window.drupalCumulativeXhrCount'));
    $this->assertSame([], $session->evaluateScript('legacyAjaxOverrides.trace'));

    $session->executeScript(<<<'JS'
      const form = document.querySelector('#legacy-ajax-overrides');
      form.querySelector('select').value = 'enabled';
      jQuery(form.querySelector('button')).trigger('mousedown.legacyOverrides');
      JS);
    $this->assertSession()->assertExpectedAjaxRequest(1);
    $this->assertSame(['beforeSend', 'success', 'ajaxComplete'], $session->evaluateScript('legacyAjaxOverrides.trace'));
    $this->assertSame('override', $session->evaluateScript('document.activeElement.name'));
    $data = json_decode($this->assertSession()->elementExists('css', '#request-lane-echo')->getText(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('enabled', $data['fields']['override']);
  }

  /**
   * Tests the Ajax listener during jQuery synthetic and native dispatch.
   */
  public function testLegacySyntheticAjaxEvents(): void {
    $this->drupalGet('ajax-test/global-events');
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const trace = [];
        const bind = (element, event, name) => {
          document.body.append(element);
          const ajax = Drupal.ajax({
            element,
            event,
            url: Drupal.url('ajax-test/echo-header'),
          });
          ajax.eventResponse = (target, event) => {
            trace.push(name);
            event.preventDefault();
          };
        };

        const button = document.createElement('button');
        bind(button, 'click.ajaxProbe', 'button');
        jQuery(button).trigger('click.unrelated');
        jQuery(button).triggerHandler('click.ajaxProbe');
        button.click();

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        bind(checkbox, 'click.ajaxProbe', 'checkbox');
        jQuery(checkbox).trigger('click.ajaxProbe');
        const checked = checkbox.checked;

        const radio = document.createElement('input');
        radio.type = 'radio';
        jQuery(radio).on('click.ajaxProbe', () => trace.push('first'));
        bind(radio, 'click.ajaxProbe', 'radio');
        jQuery(radio).trigger('click.ajaxProbe');

        const input = document.createElement('input');
        jQuery(input).on('blur.ajaxProbe', (event) => {
          trace.push('stopped');
          event.stopImmediatePropagation();
        });
        bind(input, 'blur.ajaxProbe', 'blur');
        input.focus();
        jQuery(input).trigger('blur.ajaxProbe');

        return [trace, checked, radio.checked];
      })()
      JS);
    $this->assertSame([
      ['button', 'button', 'checkbox', 'first', 'radio', 'stopped'],
      TRUE,
      TRUE,
    ], $result);
  }

  /**
   * Tests physical event overrides and synthetic link bubbling.
   */
  public function testCouncilEventDispatch(): void {
    $this->drupalGet('ajax-test/global-events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const link = document.querySelector('#test_global_events_drupal_ajax_link');
      link.innerHTML = '<span>Drupal Ajax</span>';
      const linkAjax = Drupal.ajax.instances.find((instance) => instance?.element === link);
      const physical = document.createElement('button');
      document.body.append(physical);
      const buttonAjax = Drupal.ajax({ element: physical, event: 'click.scope', keypress: true, url: location.href });
      const trace = window.councilEventTrace = [];
      buttonAjax.eventResponse = (element, event) => trace.push(event instanceof jQuery.Event);
      physical.click();
      buttonAjax.eventResponse = Drupal.ajax.useNativeCallback((element, event) => trace.push(event instanceof MouseEvent));
      physical.click();
      buttonAjax.keypressResponse = (element, event) => trace.push(event instanceof jQuery.Event && event.key === 'z');
      physical.dispatchEvent(new KeyboardEvent('keypress', { key: 'z' }));
      const nested = document.createElement('button');
      document.body.append(nested);
      Drupal.ajax({ element: nested, event: 'click', url: location.href }).eventResponse = () => trace.push('nested');
      physical.addEventListener('click', (event) => {
        if (event.detail !== 7) {
          nested.click();
          physical.dispatchEvent(new MouseEvent('click', { detail: 7 }));
        }
      });
      jQuery(physical).trigger('click.other');
      const response = linkAjax.eventResponse;
      linkAjax.eventResponse = function (...args) {
        trace.push('descendant');
        return response.apply(this, args);
      };
      jQuery(link.querySelector('span')).trigger('click');
      JS);
    $this->assertSame([TRUE, TRUE, TRUE, 'nested', TRUE, 'descendant'], $session->evaluateScript('window.councilEventTrace'));
    $this->assertSession()->assertExpectedAjaxRequest(1);
  }

  /**
   * Tests that late legacy form options are honored and cancellation cleans up.
   */
  #[IgnoreDeprecations]
  public function testCouncilLegacyFormCancellation(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.Ajax#$form is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use Drupal.Ajax#form. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage('Javascript Deprecation: Submitting Drupal Ajax forms through the jQuery Form plugin is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use Drupal AJAX with native form options, or supply your own form plugin. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalGet('ajax-test/global-events', ['query' => ['legacy_overrides' => 1, 'legacy_plugin' => 1]]);
    $session = $this->getSession();
    $result = $session->evaluateScript(<<<'JS'
      (async () => {
        const { ajax, $form } = legacyAjaxOverrides;
        const form = $form[0];
        const button = form.querySelector('button');
        form.querySelector('select').value = 'enabled';
        let attempt;
        let trace = [];
        const alerts = [];
        window.alert = (message) => alerts.push(message);
        Drupal.behaviors.councilForm = {
          attach(context) { if (context === form) trace.push('attach'); },
          detach(context, settings, trigger) {
            if (context === form && trigger === 'serialize') trace.push('detach');
          },
        };
        $form.on('form-pre-serialize', (event, collection, options) => {
          trace.push('serialize');
          if (attempt === 'prefilter') options.clearForm = true;
        });
        jQuery.ajaxPrefilter((options, original, xhr) => {
          if (attempt === 'prefilter' && options.clearForm) {
            trace.push('prefilter');
            xhr.abort();
          }
        });
        const { beforeSubmit } = ajax.options;
        ajax.options.beforeSubmit = function (values, element, options) {
          trace.push('submit');
          if (attempt === 'multipart') options.uploadProgress = () => {};
          if (attempt === 'iframe') {
            options.iframe = true;
            options.closeKeepAlive = Drupal.url('ajax-test/echo-native-request');
          }
          return beforeSubmit.call(this, values, element, options);
        };
        const { beforeSend } = ajax.options;
        ajax.options.beforeSend = function (xhr, settings) {
          beforeSend.call(this, xhr, settings);
          if (attempt === 'throw') throw new Error('beforeSend failed');
          return false;
        };
        for (const event of ['drupal:ajax-before-send', 'drupal:ajax-complete']) {
          document.addEventListener(event, (e) => {
            if (e.detail.ajax === ajax) trace.push(event);
          });
        }
        const requests = window.drupalCumulativeXhrCount;
        const run = (method, name) => {
          attempt = method;
          trace = [];
          form.enctype = method === 'multipart' ? 'multipart/form-data' : 'application/x-www-form-urlencoded';
          ajax.options.clearForm = method === 'throw';
          jQuery(button).trigger('mousedown.legacyOverrides');
          return [name, trace, ajax.ajaxing, form.clk, button.disabled, jQuery.active];
        };
        const prefilter = run('prefilter', 'prefilter');
        const multipart = run('multipart', 'multipart');
        const thrown = run('throw', 'throw');
        const iframe = await new Promise((resolve) => {
          document.addEventListener('drupal:ajax-complete', () => {
            setTimeout(() => resolve(['iframe', trace, ajax.ajaxing, form.clk, button.disabled, jQuery.active]), 0);
          }, { once: true });
          run('iframe', 'iframe');
          setTimeout(() => resolve(['iframe timeout', trace, ajax.ajaxing]), 1500);
        });
        return [prefilter, multipart, thrown, iframe, alerts.some((alert) => alert.endsWith('beforeSend failed')), window.drupalCumulativeXhrCount - requests];
      })()
      JS);
    $driver = $session->getDriver();
    assert($driver instanceof DrupalSelenium2Driver);
    $driver->processJavascriptDeprecations();
    $attempt = ['serialize', 'detach', 'submit', 'drupal:ajax-before-send', 'attach', 'drupal:ajax-complete'];
    $this->assertSame([
      ['prefilter', ['serialize', 'detach', 'submit', 'prefilter', 'attach'], FALSE, NULL, FALSE, 0],
      ['multipart', $attempt, FALSE, NULL, FALSE, 0],
      ['throw', $attempt, FALSE, NULL, FALSE, 0],
      ['iframe', $attempt, FALSE, NULL, FALSE, 0],
      TRUE,
      1,
    ], $result);
  }

  /**
   * Tests legacy form selection and the old Views UI submission contract.
   */
  #[IgnoreDeprecations]
  public function testCouncilLegacyViewsForm(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.Ajax#$form is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use Drupal.Ajax#form. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage('Javascript Deprecation: Submitting Drupal Ajax forms through the jQuery Form plugin is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use Drupal AJAX with native form options, or supply your own form plugin. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.views.ajaxView#$view is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native views property. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.views.ajaxView#$exposed_form is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native exposedForms property. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalGet('ajax-test/global-events', ['query' => ['legacy_overrides' => 1, 'legacy_plugin' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const form = document.createElement('form');
      document.body.append(form);
      form.innerHTML = '<input name="value" value="default"><input type="submit" name="op" value="Apply">';
      const $form = jQuery(form);
      const submit = form.querySelector('[type="submit"]');
      const input = form.querySelector('[name="value"]');
      const ajax = Drupal.ajax({
        url: Drupal.url('ajax-test/echo-request'),
        event: 'submit',
        element: form,
      });
      ajax.$form = $form;
      jQuery(submit).on('mousedown', () => { form.clk = submit; });
      $form.on('form-pre-serialize', (event, collection, settings) => {
        settings.resetForm = true;
      });
      input.value = 'changed';
      jQuery(submit).trigger('mousedown');
      $form.trigger('submit');
      window.councilViewsForm = { ajax, form, input };
      JS);
    $this->assertSession()->assertExpectedAjaxRequest(1);
    $result = json_decode($this->assertSession()->elementExists('css', '#request-lane-echo')->getText(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('Apply', $result['fields']['op']);
    $this->assertSame('changed', $result['fields']['value']);
    $this->assertSame(['default', NULL], $session->evaluateScript('[councilViewsForm.input.value, councilViewsForm.form.clk]'));
    // Load Views after jQuery to exercise the late bridge registration.
    $this->assertSame([TRUE, TRUE, TRUE, TRUE], $session->evaluateScript(<<<'JS'
      (async () => {
        await new Promise((success, error) => loadjs(Drupal.url('core/modules/views/js/ajax_view.js'), { success, error }));
        const view = Object.create(Drupal.views.ajaxView.prototype);
        const row = document.createElement('div');
        view.views = [row];
        view.exposedForms = [councilViewsForm.form];
        const exposed = jQuery(document.createElement('form'));
        const result = [view.$view instanceof jQuery, view.$exposed_form[0] === view.exposedForms[0]];
        view.$exposed_form = exposed;
        return [...result, view.$exposed_form === exposed, view.exposedForms[0] === exposed[0]];
      })()
      JS));

    // Views UI itself must track a button that was activated by script.
    $session->evaluateScript(<<<'JS'
      (async () => {
        await new Promise((success, error) => loadjs(Drupal.url('core/modules/views_ui/js/ajax.js'), { success, error }));
        const wrapper = document.body.appendChild(document.createElement('div'));
        wrapper.className = 'js-views-ui-dialog';
        wrapper.innerHTML = '<form><input class="js-form-submit" type="submit" name="op" value="Preview"></form>';
        const form = window.councilNativeViewsForm = wrapper.firstElementChild;
        Drupal.AjaxCommands.prototype.viewsSetForm(null, { url: Drupal.url('ajax-test/echo-request') });
        jQuery(form.querySelector('input')).trigger('mousedown');
        jQuery(form).trigger('submit');
      })()
      JS);
    $this->assertSession()->assertExpectedAjaxRequest(2);
    $result = json_decode($this->assertSession()->elementExists('css', '#request-lane-echo')->getText(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('Preview', $result['fields']['op']);
    $this->assertNull($session->evaluateScript('window.councilNativeViewsForm.clk'));
    $driver = $session->getDriver();
    assert($driver instanceof DrupalSelenium2Driver);
    $driver->processJavascriptDeprecations();
  }

  /**
   * Tests diagnostics for explicit legacy options on a jQuery-free page.
   */
  public function testCouncilLegacyFormMissing(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const form = document.body.appendChild(document.createElement('form'));
        const button = form.appendChild(document.createElement('button'));
        const ajax = Drupal.ajax({ element: form, event: 'submit', url: location.href });
        ajax.form = form;
        let message = '';
        window.alert = (value) => { message = value; };
        const { beforeSubmit } = ajax.options;
        ajax.options.beforeSubmit = function (values, element, settings) {
          settings.resetForm = true;
          return beforeSubmit.apply(this, arguments);
        };
        form.requestSubmit(button);
        return [message.endsWith('Legacy Ajax form options require the core/internal.jquery.form library.'), ajax.ajaxing, form.clk, window.drupalCumulativeXhrCount];
      })()
      JS);
    $this->assertSame([TRUE, FALSE, NULL, 0], $result);
  }

  /**
   * Tests the opted-in request factory and cross-origin settings.
   */
  #[IgnoreDeprecations]
  public function testLegacyXhrFactory(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Using jQuery request options with Drupal Ajax is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native Ajax lifecycle, request handle, form element or request options. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalGet('ajax-test/global-events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const output = document.createElement('div');
      output.id = 'request-lane-echo';
      document.body.append(output);
      const run = async (crossDomain) => {
        const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-request') });
        let customRequest;
        let method;
        let headerWrites = 0;
        Object.assign(ajax.options, {
          crossDomain,
          xhr() {
            method = this.type;
            customRequest = new XMLHttpRequest();
            customRequest.setRequestHeader = (name, value) => {
              ++headerWrites;
              XMLHttpRequest.prototype.setRequestHeader.call(customRequest, name, value);
            };
            return customRequest;
          },
        });
        await ajax.execute().commands;
        return [customRequest.status, method, headerWrites > 0, JSON.parse(output.textContent).requestedWith];
      };
      (async () => {
        window.factoryResults = [await run(true), await run(false)];
      })();
      JS);
    $this->assertJsCondition('Boolean(window.factoryResults)');
    $this->assertSame([[200, 'POST', TRUE, NULL], [200, 'POST', TRUE, 'XMLHttpRequest']], $session->evaluateScript('window.factoryResults'));
  }

  /**
   * Tests a forced iframe submission using the explicitly attached plug-in.
   */
  #[IgnoreDeprecations]
  public function testLegacyIframeSubmission(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.Ajax#$form is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use Drupal.Ajax#form. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->expectUserDeprecationMessage('Javascript Deprecation: Submitting Drupal Ajax forms through the jQuery Form plugin is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use Drupal AJAX with native form options, or supply your own form plugin. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalGet('ajax-test/global-events', ['query' => ['legacy_overrides' => 1, 'legacy_plugin' => 1]]);
    $session = $this->getSession();
    $this->assertJsCondition('Boolean(window.legacyAjaxOverrides)');
    $session->executeScript(<<<'JS'
      const { ajax, $form, trace } = window.legacyAjaxOverrides;
      const insert = ajax.commands.insert;
      const gate = new Promise((resolve) => { window.releaseIframeCommands = resolve; });
      ajax.commands.insert = function (...args) {
        window.iframeCommandsPending = true;
        return gate.then(() => insert.apply(this, args));
      };
      document.addEventListener('drupal:ajax-complete', ({ detail }) => {
        if (detail.ajax === ajax) {
          trace.push('nativeComplete');
        }
      });
      ajax.options.url = Drupal.url('ajax-test/echo-request?iframe_test=1');
      drupalSettings.ajaxTrustedUrl[ajax.url] = true;
      Object.assign(ajax.options, { iframe: true, forceSync: true, clearForm: true });
      $form.find('select').val('enabled');
      $form.find('button').trigger('mousedown.legacyOverrides');
      JS);
    $this->assertJsCondition('window.iframeCommandsPending === true');
    $this->assertSame([TRUE, 1, ['beforeSend']], $session->evaluateScript('[legacyAjaxOverrides.ajax.ajaxing, jQuery.active, legacyAjaxOverrides.trace]'));
    $session->executeScript('window.releaseIframeCommands();');
    $this->assertJsCondition("document.querySelector('#request-lane-echo').textContent !== '' && !legacyAjaxOverrides.ajax.ajaxing");
    $this->assertSame([0, ['beforeSend', 'success', 'nativeComplete', 'ajaxComplete']], $session->evaluateScript('[jQuery.active, legacyAjaxOverrides.trace]'));
    $data = json_decode($this->assertSession()->elementExists('css', '#request-lane-echo')->getText(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('enabled', $data['fields']['override']);
    $this->assertNull($data['requestedWith']);
    $this->assertSame(-1, $session->evaluateScript('legacyAjaxOverrides.$form.find("select")[0].selectedIndex'));
    $this->assertSame('function', $session->evaluateScript('typeof legacyAjaxOverrides.$form.data("jqxhr").abort'));
  }

  /**
   * Tests native request completion separately from the Ajax command queue.
   */
  public function testNativeCommandRequest(): void {
    $this->drupalGet('ajax-test/global-events');
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
        window.nativeCommands = [ajax.ajaxing, document.getElementById('test_global_events_log').textContent];
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
   * Tests header mutation and abort in a global Ajax send handler.
   */
  public function testAjaxSendHeaderAndAbort(): void {
    $this->drupalGet('ajax-test/global-events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
(function ($) {
  const send = XMLHttpRequest.prototype.send;
  window.ajaxSendResult = { sends: 0, failures: [], complete: [] };
  XMLHttpRequest.prototype.send = function () {
    window.ajaxSendResult.sends++;
    return send.apply(this, arguments);
  };
  $(document).on('ajaxSend.characterization', function (event, xhr) {
    xhr.setRequestHeader('X-Test-Ajax-Send', 'from-ajax-send');
  });
  Drupal.ajax({ url: Drupal.url('ajax-test/echo-header') }).execute()
    .fail(function (xhr, status) { window.ajaxSendResult.failures.push(status); });
})(jQuery);
JS);
    $this->assertTrue($session->wait(10000, "document.querySelector('#test_global_events_log').textContent.startsWith('from-ajax-send') && jQuery.active === 0"));
    $this->assertSame(1, $session->evaluateScript('window.ajaxSendResult.sends'));
    $this->assertSame([], $session->evaluateScript('window.ajaxSendResult.failures'));

    $session->executeScript(<<<'JS'
(function ($) {
  $(document).off('ajaxSend.characterization');
  window.ajaxSendResult.sends = 0;
  $(document).on('ajaxSend.characterization', function (event, xhr) {
    xhr.abort();
  });
  $(document).on('ajaxComplete.characterization', function (event, xhr) {
    window.ajaxSendResult.complete.push(xhr.statusText);
  });
  const request = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header') }).execute();
  request.fail(function (xhr, status) {
    window.ajaxSendResult.failures.push([xhr.status, status, xhr.statusText]);
  });
  window.ajaxSendResult.state = request.state();
  window.ajaxSendResult.active = $.active;
  $(document).off('.characterization');
})(jQuery);
JS);
    $this->assertSame(0, $session->evaluateScript('window.ajaxSendResult.sends'));
    $this->assertSame([[0, 'abort', 'abort']], $session->evaluateScript('window.ajaxSendResult.failures'));
    $this->assertSame(['abort'], $session->evaluateScript('window.ajaxSendResult.complete'));
    $this->assertSame('rejected', $session->evaluateScript('window.ajaxSendResult.state'));
    $this->assertSame(0, $session->evaluateScript('window.ajaxSendResult.active'));
  }

  /**
   * Tests adapter accounting for a prefilter abort and a raw jQuery request.
   */
  public function testAdapterAbortAndRawRequest(): void {
    $this->drupalGet('ajax-test/global-events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const trace = [];
      window.adapterTrace = trace;
      jQuery(document).on(
        'ajaxStart.adapter ajaxSend.adapter ajaxError.adapter ajaxSuccess.adapter ajaxComplete.adapter ajaxStop.adapter',
        (event) => trace.push([event.type, jQuery.active]),
      );
      let abortNext = true;
      jQuery.ajaxPrefilter((settings, original, request) => {
        if (abortNext) {
          abortNext = false;
          request.abort();
        }
      });
      // A prefilter abort happens before the global events start.
      const handle = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header') }).execute();
      window.prefilterAbort = [handle.state(), jQuery.active, trace.length];
      // A raw jQuery request with Drupal Ajax settings delays its completion
      // events until the commands have run.
      const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header') });
      const insert = ajax.commands.insert;
      const gate = new Promise((resolve) => {
        window.releaseRawCommands = resolve;
      });
      ajax.commands.insert = function (...args) {
        window.rawCommandsWaiting = true;
        return gate.then(() => insert.apply(this, args));
      };
      ajax.beforeSerialize(ajax.element, ajax.options);
      jQuery.ajax(ajax.options);
      JS);
    $this->assertSame(['rejected', 0, 0], $session->evaluateScript('window.prefilterAbort'));
    $this->assertTrue($session->wait(10000, 'window.rawCommandsWaiting === true'));
    $this->assertSame([
      ['ajaxStart', 1],
      ['ajaxSend', 1],
    ], $session->evaluateScript('window.adapterTrace'));
    $session->executeScript('window.releaseRawCommands();');
    $this->assertTrue($session->wait(10000, 'window.adapterTrace.some(([type]) => type === "ajaxStop")'));
    $this->assertSame([
      ['ajaxStart', 1],
      ['ajaxSend', 1],
      ['ajaxSuccess', 1],
      ['ajaxComplete', 1],
      ['ajaxStop', 0],
    ], $session->evaluateScript('window.adapterTrace'));
  }

  /**
   * Tests adapter completion after failures and throwing observers.
   */
  public function testAdapterFailureCompletion(): void {
    // The Ajax errors and observer errors are the subject of this test.
    $this->failOnJavascriptConsoleErrors = FALSE;
    $this->drupalGet('ajax-test/global-events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      window.failureErrors = [];
      window.addEventListener('error', (event) => {
        window.failureErrors.push(event.error?.name ?? event.message);
      });
      const trace = window.failureTrace = [];
      jQuery(document).on(
        'ajaxStart.failure ajaxSend.failure ajaxError.failure ajaxSuccess.failure ajaxComplete.failure ajaxStop.failure',
        (event) => trace.push(event.type),
      );
      const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/native-error-does-not-exist'), progress: false });
      ajax.options.statusCode = { 404: () => trace.push('statusCode 404') };
      const handle = ajax.execute();
      Promise.allSettled([handle.network, handle.commands]).then(([network, commands]) => {
        window.adapterFailure = [network.value.status, commands.reason?.name, ajax.ajaxing, jQuery.active];
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.adapterFailure)'));
    $this->assertSame(['error', 'AjaxError', FALSE, 0], $session->evaluateScript('window.adapterFailure'));
    $this->assertSame(['ajaxStart', 'ajaxSend', 'statusCode 404', 'ajaxError', 'ajaxComplete', 'ajaxStop'], $session->evaluateScript('window.failureTrace'));
    $this->assertSame(['AjaxError'], $session->evaluateScript('window.failureErrors'));

    // An observer that throws does not leave the request counted.
    $session->executeScript(<<<'JS'
      window.failureTrace.length = 0;
      jQuery(document).one('ajaxComplete', () => {
        throw new Error('observer');
      });
      const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header') });
      ajax.options.dataFilter = (data, type) => {
        window.failureTrace.push(`dataFilter ${type}`);
        return data;
      };
      ajax.execute().commands.then(() => {
        window.observerFailure = [jQuery.active, ajax.ajaxing];
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.observerFailure)'));
    $this->assertSame([0, FALSE], $session->evaluateScript('window.observerFailure'));
    $this->assertSame(['ajaxStart', 'ajaxSend', 'dataFilter json', 'ajaxSuccess', 'ajaxComplete', 'ajaxStop'], $session->evaluateScript('window.failureTrace'));

    // A throwing data filter and a response that is a JSON string both fail
    // the request without leaving it counted once both have finished.
    $session->executeScript(<<<'JS'
      window.failureResults = {};
      const run = (name, configure) => {
        const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header'), progress: false });
        configure(ajax);
        const handle = ajax.execute();
        Promise.allSettled([handle.network, handle.commands]).then(([network, commands]) => {
          window.failureResults[name] = [network.value.status, commands.reason?.name === 'AjaxError' ? 'AjaxError' : commands.reason?.message, ajax.ajaxing];
        });
      };
      run('filter', (ajax) => {
        ajax.options.dataFilter = () => {
          throw new Error('filter');
        };
      });
      run('string', (ajax) => {
        ajax.options.converters = { 'text json': () => 'not json' };
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Object.keys(window.failureResults).length === 2 && jQuery.active === 0'));
    $results = $session->evaluateScript('window.failureResults');
    $this->assertSame(['parsererror', 'filter', FALSE], $results['filter']);
    $this->assertSame(['success', 'AjaxError', FALSE], $results['string']);

    // A success callback that throws before Drupal's leaves nothing waiting.
    $session->executeScript(<<<'JS'
      const button = document.body.appendChild(document.createElement('button'));
      const ajax = Drupal.ajax({ element: button, url: Drupal.url('ajax-test/echo-header'), event: 'success-list' });
      ajax.options.success = [() => {
        throw new Error('first');
      }, ajax.options.success];
      ajax.execute().commands.catch((error) => {
        window.successList = [error.message, ajax.ajaxing, button.disabled, Boolean(button.nextElementSibling?.classList.contains('ajax-progress'))];
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.successList) && jQuery.active === 0'));
    $this->assertSame(['first', FALSE, FALSE, FALSE], $session->evaluateScript('window.successList'));

    // A callback that throws after Drupal's success callback leaves the
    // completion events waiting for the commands, and a success callback that
    // replaces Drupal's lets them fire at once.
    $session->executeScript(<<<'JS'
      const trace = window.statusTrace = [];
      jQuery(document).on('ajaxSuccess.status ajaxComplete.status ajaxStop.status', (event) => trace.push([event.type, jQuery.active]));
      const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header'), progress: false });
      const insert = ajax.commands.insert;
      const gate = new Promise((resolve) => {
        window.releaseStatusCommands = resolve;
      });
      ajax.commands.insert = function (...args) {
        window.statusCommandsWaiting = true;
        return gate.then(() => insert.apply(this, args));
      };
      ajax.options.statusCode = { 200: () => {
        throw new Error('status');
      } };
      ajax.execute().commands.catch((error) => {
        window.statusResult = [error.message, ajax.ajaxing, trace.map(([type]) => type)];
      });
      JS);
    $this->assertTrue($session->wait(10000, 'window.statusCommandsWaiting === true'));
    $this->assertSame([], $session->evaluateScript('window.statusTrace'));
    $this->assertSame(1, $session->evaluateScript('jQuery.active'));
    $this->assertNull($session->evaluateScript('window.statusResult ?? null'));
    $session->executeScript('window.releaseStatusCommands();');
    $this->assertTrue($session->wait(10000, 'Boolean(window.statusResult)'));
    $this->assertSame(['status', FALSE, ['ajaxSuccess', 'ajaxComplete', 'ajaxStop']], $session->evaluateScript('window.statusResult'));
    $session->executeScript(<<<'JS'
      jQuery(document).off('.status');
      const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header'), progress: false });
      ajax.options.success = () => {};
      ajax.execute().commands.then(() => {
        window.replacedSuccess = ajax.ajaxing;
      });
      JS);
    $this->assertTrue($session->wait(10000, 'window.replacedSuccess === false && jQuery.active === 0'));

    // A throwing start observer does not leave the request counted.
    $this->assertSame([0, FALSE, 'rejected'], $session->evaluateScript(<<<'JS'
      (() => {
        window.alert = () => {};
        jQuery(document).one('ajaxStart', () => {
          throw new Error('start');
        });
        const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header'), progress: false });
        const handle = ajax.execute();
        return [jQuery.active, ajax.ajaxing, handle.state()];
      })()
      JS));
  }

  /**
   * Tests that a vetoed or aborted request leaves its element usable.
   */
  public function testVetoAndAbortCleanup(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form', ['query' => ['legacy_requests' => 1]]);
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
      jQuery(document).one('ajaxSend', (event, xhr) => {
        window.abortProgress = [hasProgress(), outside.disabled];
        xhr.abort();
      });
      trigger(outside);
      window.abortState = [window.abortProgress, aborted.ajaxing, outside.disabled, hasProgress()];
      JS);
    $this->assertSame([FALSE, FALSE, NULL, 0], $session->evaluateScript('window.vetoState'));
    $this->assertSame([[TRUE, TRUE], FALSE, FALSE, FALSE], $session->evaluateScript('window.abortState'));
    $this->assertTrue($session->wait(10000, "document.getElementById('request-lane-echo').textContent.length > 0 && jQuery.active === 0"));
    $request = json_decode($session->evaluateScript("document.getElementById('request-lane-echo').textContent"), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertStringContainsString('op=clicked', $request['body']);
  }

  /**
   * Tests native command completion, synchronous requests and late adapters.
   */
  public function testNativeRequestCompletion(): void {
    $this->drupalGet('ajax-test/global-events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const adapter = Drupal.ajax.compatibility;
      Drupal.ajax.compatibility = null;
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
        window.nativeArrayCommands = [ajax.ajaxing, document.getElementById('test_global_events_log').textContent];
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
      Drupal.ajax.compatibility = adapter;
      JS);
    $this->assertTrue($session->evaluateScript('window.syncRan'));
    $this->assertTrue($session->wait(10000, 'window.nativeNetworkDone === true'));
    $this->assertNull($session->evaluateScript('window.nativeArrayCommands ?? null'));
    $session->executeScript('window.releaseNativeCommands();');
    $this->assertTrue($session->wait(10000, 'Boolean(window.nativeArrayCommands)'));
    $this->assertFalse($session->evaluateScript('window.nativeArrayCommands[0]'));

    // An adapter that arrives during a native request projects no events.
    $session->executeScript(<<<'JS'
      const trace = window.lateTrace = [];
      jQuery(document).on(
        'ajaxStart.late ajaxSend.late ajaxSuccess.late ajaxComplete.late ajaxStop.late',
        (event) => trace.push(event.type),
      );
      const adapter = Drupal.ajax.compatibility;
      Drupal.ajax.compatibility = null;
      const handle = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header') }).execute();
      Drupal.ajax.compatibility = adapter;
      handle.commands.then(() => {
        window.lateAdapter = [jQuery.active, trace.length];
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.lateAdapter)'));
    $this->assertSame([0, 0], $session->evaluateScript('window.lateAdapter'));
  }

  /**
   * Tests the adapter's form accessor, global option and overlapping requests.
   */
  #[IgnoreDeprecations]
  public function testAdapterSurface(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.Ajax#$form is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use Drupal.Ajax#form. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalGet('ajax-test/global-events');
    $session = $this->getSession();
    $this->assertSame([TRUE, TRUE, TRUE, TRUE, TRUE], $session->evaluateScript(<<<'JS'
      (() => {
        const form = document.body.appendChild(document.createElement('form'));
        const other = document.body.appendChild(document.createElement('form'));
        const button = form.appendChild(document.createElement('button'));
        const ajax = Drupal.ajax({ element: button, url: Drupal.url('ajax-test/echo-header'), event: 'surface' });
        const result = [ajax.$form === ajax.$form, ajax.$form[0] === form];
        const collection = jQuery(other);
        ajax.$form = collection;
        result.push(ajax.form === other, ajax.$form === collection);
        ajax.$form = form;
        result.push(ajax.$form[0] === form);
        return result;
      })()
      JS));

    $session->executeScript(<<<'JS'
      const trace = window.surfaceTrace = [];
      jQuery(document).on(
        'ajaxStart.surface ajaxSend.surface ajaxSuccess.surface ajaxComplete.surface ajaxStop.surface',
        (event) => trace.push(event.type),
      );
      // A request without global events still completes its commands.
      const quiet = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header') });
      quiet.options.global = false;
      quiet.execute().commands.then(() => {
        window.quietResult = [jQuery.active, trace.length];
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.quietResult)'));
    $this->assertSame([0, 0], $session->evaluateScript('window.quietResult'));

    // A script request with attributes uses a script tag, as jQuery did.
    $session->executeScript(<<<'JS'
      window.scriptTags = [];
      const observer = new MutationObserver((records) => {
        records.forEach(({ addedNodes }) => addedNodes.forEach((node) => {
          if (node.nodeName === 'SCRIPT' && node.src.includes('scriptTransportRan')) {
            window.scriptTags.push([node.nonce || node.getAttribute('nonce'), node.hasAttribute('nomodule')]);
          }
        }));
      });
      observer.observe(document.head, { childList: true });
      Drupal.ajax.compatibility.send({
        url: 'data:text/javascript,window.scriptTransportRan=true',
        type: 'POST',
        cache: true,
        dataType: 'script',
        scriptAttrs: { nonce: 'test-nonce', nomodule: false },
        global: false,
      }).always((data, status) => {
        window.scriptResult = [status, window.scriptTags, window.scriptTransportRan === true];
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.scriptResult)'));
    $this->assertSame(['success', [['test-nonce', FALSE]], TRUE], $session->evaluateScript('window.scriptResult'));

    // A raw jQuery request and a Drupal request share one start and stop.
    $session->executeScript(<<<'JS'
      jQuery.ajax({ url: Drupal.url('ajax-test/echo-header'), dataType: 'json' });
      window.overlapActive = jQuery.active;
      Drupal.ajax({ url: Drupal.url('ajax-test/echo-header') }).execute();
      window.overlapActive = [window.overlapActive, jQuery.active];
      JS);
    $this->assertSame([1, 2], $session->evaluateScript('window.overlapActive'));
    $this->assertTrue($session->wait(10000, 'window.surfaceTrace.filter((type) => type === "ajaxComplete").length === 2 && jQuery.active === 0'));
    $trace = $session->evaluateScript('window.surfaceTrace');
    $this->assertSame(['ajaxStart', 'ajaxSend', 'ajaxSend'], array_slice($trace, 0, 3));
    $this->assertSame('ajaxStop', end($trace));
    $this->assertSame(1, count(array_keys($trace, 'ajaxStart', TRUE)));
    $this->assertSame(1, count(array_keys($trace, 'ajaxStop', TRUE)));
  }

  /**
   * Tests request options and cancellation on the native request path.
   */
  public function testNativeRequestOptions(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form', ['query' => ['legacy_requests' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      Drupal.ajax.compatibility = null;
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
   * Tests cancellation, notification and command failure through the adapter.
   */
  public function testAdapterCancellation(): void {
    // The observer and command errors are part of this test.
    $this->failOnJavascriptConsoleErrors = FALSE;
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('ajax_forms_test_ajax_commands_form', ['query' => ['legacy_requests' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      window.alert = () => {};
      const send = XMLHttpRequest.prototype.send;
      window.adapterSends = 0;
      XMLHttpRequest.prototype.send = function (...args) {
        window.adapterSends++;
        return send.apply(this, args);
      };
      document.body.insertAdjacentHTML('beforeend', '<div id="request-lane-echo"></div>');
      window.buildForm = (id, multipart) => {
        document.body.insertAdjacentHTML('beforeend', `<form id="${id}"><button name="op" value="clicked">Go</button></form>`);
        const form = document.getElementById(id);
        if (multipart) {
          const input = form.appendChild(document.createElement('input'));
          input.type = 'file';
          input.name = 'upload';
          const transfer = new DataTransfer();
          transfer.items.add(new File(['upload'], 'upload.txt', { type: 'text/plain' }));
          input.files = transfer.files;
        }
        const button = form.querySelector('button');
        const ajax = Drupal.ajax({ element: button, url: Drupal.url('ajax-test/echo-request'), event: id, setClick: true, submit: { a: [1, 2] } });
        const trigger = () => button.dispatchEvent(new Event(id, { bubbles: true, cancelable: true }));
        return { form, button, ajax, trigger };
      };
      window.restored = ({ form, button, ajax }) => !ajax.ajaxing && !button.disabled && !form.clk && !form.querySelector('.ajax-progress');
      JS);

    // A beforeSend veto on a multipart form does not send, and can be retried.
    $session->executeScript(<<<'JS'
      const fixture = window.multipartFixture = window.buildForm('multipart-veto', true);
      let vetoes = 1;
      const { beforeSend } = fixture.ajax;
      fixture.ajax.beforeSend = function (...args) {
        beforeSend.apply(this, args);
        return vetoes-- > 0 ? false : undefined;
      };
      window.adapterSends = 0;
      fixture.trigger();
      window.multipartSends = window.adapterSends;
      JS);
    $this->assertSame(0, $session->evaluateScript('window.multipartSends'));
    $this->assertTrue($session->wait(10000, 'window.restored(window.multipartFixture)'));
    $session->executeScript("document.getElementById('request-lane-echo').textContent = ''; window.multipartFixture.trigger();");
    $this->assertTrue($session->wait(10000, "document.getElementById('request-lane-echo').textContent.length > 0 && jQuery.active === 0"));
    $result = json_decode($session->evaluateScript("document.getElementById('request-lane-echo').textContent"), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('upload.txt', $result['upload'][0]);

    // A prefilter abort on a form submission restores the form, which then
    // sends with traditional encoding from the site defaults and stores its
    // request before notifying.
    $session->executeScript(<<<'JS'
      const fixture = window.prefilterFixture = window.buildForm('prefilter-abort', false);
      let abortNext = true;
      jQuery.ajaxPrefilter((settings, original, request) => {
        if (abortNext) {
          abortNext = false;
          request.abort('skipped');
        }
      });
      jQuery(fixture.form).on('form-submit-notify', () => {
        window.notifiedRequest = typeof jQuery(fixture.form).data('jqxhr')?.abort;
      });
      fixture.trigger();
      window.prefilterRestored = window.restored(fixture);
      // The restored form can be submitted again at once.
      document.getElementById('request-lane-echo').textContent = '';
      window.notifiedRequest = null;
      jQuery.ajaxSetup({ traditional: true });
      fixture.trigger();
      jQuery.ajaxSetup({ traditional: false });
      JS);
    $this->assertTrue($session->evaluateScript('window.prefilterRestored'));
    $this->assertTrue($session->wait(10000, "document.getElementById('request-lane-echo').textContent.length > 0 && jQuery.active === 0"));
    $result = json_decode($session->evaluateScript("document.getElementById('request-lane-echo').textContent"), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertStringContainsString('a=1&a=2', $result['body']);
    $this->assertSame('function', $session->evaluateScript('window.notifiedRequest'));

    // The serialize callback can still choose the encoding.
    $session->executeScript(<<<'JS'
      const fixture = window.buildForm('serialize-encoding', false);
      const { beforeSerialize } = fixture.ajax;
      fixture.ajax.beforeSerialize = function (element, options) {
        options.traditional = true;
        return beforeSerialize.call(this, element, options);
      };
      document.getElementById('request-lane-echo').textContent = '';
      fixture.trigger();
      JS);
    $this->assertTrue($session->wait(10000, "document.getElementById('request-lane-echo').textContent.length > 0 && jQuery.active === 0"));
    $result = json_decode($session->evaluateScript("document.getElementById('request-lane-echo').textContent"), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertStringContainsString('a=1&a=2', $result['body']);

    // An abort in beforeSend, a throwing send observer and an abort with a
    // custom status all leave the element usable and nothing counted.
    $session->executeScript(<<<'JS'
      const button = document.body.appendChild(document.createElement('button'));
      const make = (event) => Drupal.ajax({ element: button, url: Drupal.url('ajax-test/echo-request'), event });
      window.adapterSends = 0;
      const cancelled = make('adapter-cancel');
      cancelled.beforeSend = (xhr) => xhr.abort();
      const handle = cancelled.execute();
      window.cancelState = [window.adapterSends, handle.state()];

      jQuery(document).one('ajaxSend', () => {
        throw new Error('observer');
      });
      const observed = make('adapter-observer');
      observed.execute();
      window.observerState = [window.adapterSends, observed.ajaxing, button.disabled, jQuery.active];

      // A throwing beforeSend and a throwing transport factory.
      const throwing = make('adapter-throwing');
      const { beforeSend } = throwing;
      throwing.beforeSend = function (...args) {
        beforeSend.apply(this, args);
        throw new Error('before send');
      };
      throwing.execute();
      let failFactory = true;
      jQuery.ajaxTransport('json', () => {
        if (failFactory) {
          failFactory = false;
          throw new Error('factory');
        }
      });
      make('adapter-factory').execute();
      window.throwState = [window.adapterSends, throwing.ajaxing, button.disabled, Boolean(button.nextElementSibling?.classList.contains('ajax-progress')), jQuery.active];

      // An abort whose status text reads like success still restores.
      jQuery(document).one('ajaxSend', (event, xhr) => xhr.abort('success'));
      const misnamed = make('adapter-misnamed');
      misnamed.execute();
      window.misnamedState = [misnamed.ajaxing, button.disabled, Boolean(button.nextElementSibling?.classList.contains('ajax-progress'))];

      const custom = make('adapter-custom');
      custom.execute().abort('cancelled');
      window.customState = [custom.ajaxing, button.disabled, Boolean(button.nextElementSibling?.classList.contains('ajax-progress')), jQuery.active];
      window.cancelButton = button;
      window.cancelInstance = cancelled;
      JS);
    $this->assertSame([0, 'rejected'], $session->evaluateScript('window.cancelState'));

    // A beforeSend that vetoes without touching a disabled control leaves it
    // disabled, and a replaced beforeSend that disables it still has it
    // re-enabled after a failed response.
    $this->assertTrue($session->evaluateScript(<<<'JS'
      (() => {
        const disabled = document.body.appendChild(document.createElement('button'));
        disabled.disabled = true;
        const ajax = Drupal.ajax({ element: disabled, url: Drupal.url('ajax-test/echo-request'), event: 'disabled-veto' });
        ajax.beforeSend = () => false;
        ajax.execute();
        return disabled.disabled;
      })()
      JS));
    $session->executeScript(<<<'JS'
      const failing = document.body.appendChild(document.createElement('button'));
      window.failingButton = failing;
      const ajax = Drupal.ajax({ element: failing, url: Drupal.url('ajax-test/native-error-does-not-exist'), event: 'replaced-send' });
      ajax.options.beforeSend = function (xhr, settings) {
        return ajax.beforeSend(xhr, settings);
      };
      ajax.execute();
      window.replacedDisabled = failing.disabled;
      JS);
    $this->assertTrue($session->evaluateScript('window.replacedDisabled'));
    $this->assertTrue($session->wait(10000, '!window.failingButton.disabled && jQuery.active === 0'));

    // A throwing prefilter on a form submission restores the form.
    $session->executeScript(<<<'JS'
      const fixture = window.throwingFixture = window.buildForm('prefilter-throw', false);
      let throwNext = true;
      jQuery.ajaxPrefilter(() => {
        if (throwNext) {
          throwNext = false;
          throw new Error('prefilter');
        }
      });
      fixture.trigger();
      JS);
    $this->assertTrue($session->evaluateScript('window.restored(window.throwingFixture)'));

    // An early cancellation leaves a control that started disabled alone.
    $this->assertTrue($session->evaluateScript(<<<'JS'
      (() => {
        const disabled = document.body.appendChild(document.createElement('button'));
        disabled.disabled = true;
        let abortNext = true;
        jQuery.ajaxPrefilter((settings, original, request) => {
          if (abortNext) {
            abortNext = false;
            request.abort();
          }
        });
        Drupal.ajax({ element: disabled, url: Drupal.url('ajax-test/echo-request'), event: 'disabled-cancel' }).execute();
        return disabled.disabled;
      })()
      JS));
    $this->assertSame([0, FALSE, FALSE, 0], $session->evaluateScript('window.observerState'));
    $this->assertSame([FALSE, FALSE, FALSE, 0], $session->evaluateScript('window.customState'));
    $this->assertSame([0, FALSE, FALSE, FALSE, 0], $session->evaluateScript('window.throwState'));
    $this->assertSame([FALSE, FALSE, FALSE], $session->evaluateScript('window.misnamedState'));
    $this->assertTrue($session->wait(10000, '!window.cancelInstance.ajaxing && !window.cancelButton.disabled'));

    // A failed command still restores focus and behaviors, and finishes.
    $session->executeScript(<<<'JS'
      const fixture = window.buildForm('command-failure', false);
      fixture.ajax.commands.insert = () => Promise.reject(new Error('command'));
      const attach = Drupal.attachBehaviors;
      let attached = 0;
      Drupal.attachBehaviors = function (context, ...args) {
        if (context === fixture.form) {
          attached++;
        }
        return attach.call(this, context, ...args);
      };
      fixture.ajax.execute().commands.then(() => {
        window.commandFailure = [attached, fixture.ajax.ajaxing, jQuery.active];
      });
      JS);
    $this->assertTrue($session->wait(10000, 'Boolean(window.commandFailure)'));
    $this->assertSame([1, FALSE, 0], $session->evaluateScript('window.commandFailure'));
  }

  /**
   * Tests the order of Ajax settings, prefilters, and a custom transport.
   */
  public function testAjaxSettingsAndTransport(): void {
    $this->drupalGet('ajax-test/global-events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const trace = [];
      window.ajaxSettingsTrace = trace;
      let facade;
      jQuery.ajaxSetup({
        headers: {
          'X-Test-Ajax-Send': 'site-default',
          'X-Test-Default': 'inherited',
        },
      });
      jQuery.ajaxPrefilter('json', (settings, original, request) => {
        trace.push(['ordinary', request === facade, settings.headers['X-Test-Ajax-Send']]);
      });
      jQuery.ajaxPrefilter('+json', (settings, original, request) => {
        facade = request;
        trace.push([
          'priority',
          settings.headers['X-Test-Ajax-Send'],
          settings.headers['X-Test-Default'],
          Object.hasOwn(original.headers, 'X-Test-Default'),
          request.readyState,
        ]);
        settings.headers['X-Test-Ajax-Send'] = 'from-prefilter';
      });
      jQuery.ajaxTransport('+json', (settings, original, request) => {
        trace.push(['selected', request === facade, original.headers['X-Test-Ajax-Send']]);
        let xhr;
        return {
          send(headers, complete) {
            trace.push(['send', headers['X-Test-Ajax-Send'], headers['X-Test-Default']]);
            xhr = new XMLHttpRequest();
            xhr.open(settings.type, settings.url);
            Object.entries(headers).forEach(([name, value]) => xhr.setRequestHeader(name, value));
            xhr.onload = () => complete(xhr.status, xhr.statusText, { text: xhr.responseText }, xhr.getAllResponseHeaders());
            xhr.onerror = () => complete(0, 'error');
            xhr.send(settings.hasContent ? settings.data : null);
          },
          abort() {
            xhr?.abort();
          },
        };
      });
      jQuery(document)
        .on('ajaxSend.characterization', (event, request, settings) => {
          trace.push(['ajaxSend', request === facade, request.readyState, jQuery.active, settings.headers['X-Test-Ajax-Send']]);
          request.setRequestHeader('X-Test-Ajax-Send', 'from-ajax-send');
        })
        .on('ajaxComplete.characterization', (event, request) => {
          trace.push(['ajaxComplete', request === facade, jQuery.active]);
        })
        .on('ajaxStop.characterization', () => trace.push(['ajaxStop', jQuery.active]));
      const ajax = Drupal.ajax({ url: Drupal.url('ajax-test/echo-header') });
      ajax.options.headers = { 'X-Test-Ajax-Send': 'per-request' };
      const handle = ajax.execute();
      handle.done((data, status, request) => {
        trace.push(['done', request === facade, request === handle, request.status]);
      });
      JS);
    $this->assertTrue($session->wait(10000, "document.querySelector('#test_global_events_log').textContent.startsWith('from-ajax-send') && jQuery.active === 0"));
    $this->assertSame([
      ['priority', 'per-request', 'inherited', FALSE, 0],
      ['ordinary', TRUE, 'from-prefilter'],
      ['selected', TRUE, 'per-request'],
      ['ajaxSend', TRUE, 1, 1, 'from-prefilter'],
      ['send', 'from-ajax-send', 'inherited'],
      ['done', TRUE, TRUE, 200],
      ['ajaxComplete', TRUE, 1],
      ['ajaxStop', 0],
    ], $session->evaluateScript('window.ajaxSettingsTrace'));
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
