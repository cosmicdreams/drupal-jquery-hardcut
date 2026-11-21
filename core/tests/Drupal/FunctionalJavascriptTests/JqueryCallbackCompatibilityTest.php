<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Characterizes legacy Ajax form callback assignments.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
final class JqueryCallbackCompatibilityTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['jquery_bc_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests chained assignments during a native change and form request.
   */
  public function testBeforeSubmitChain(): void {
    $this->drupalGet('jquery-bc-test/events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      (() => {
        const prototype = Drupal.Ajax.prototype;
        const original = prototype.beforeSubmit;
        const trace = [];
        const select = document.querySelector('select[name="choice"]');
        const ajax = Drupal.ajax.instances.find((instance) => instance && instance.element === select);
        let collection;
        let options;
        const first = function (...args) {
          collection = args[1];
          trace.push(['first', this === ajax, args.length,
            Array.isArray(args[0]), args[1] instanceof jQuery,
            args[1][0] === document.querySelector('form#jquery-bc-test-events'),
            typeof args[2] === 'object']);
          const result = original.apply(this, args);
          trace.push(['original return', result === undefined]);
          return 'first return';
        };
        prototype.beforeSubmit = first;
        const previous = prototype.beforeSubmit;
        prototype.beforeSubmit = function (...args) {
          options = args[2];
          trace.push(['second', this === ajax, args.length]);
          const result = previous.apply(this, args);
          trace.push(['first return', result, collection === args[1], options === args[2]]);
          return result;
        };
        window.callbackTrace = trace;
        window.restoreCallback = () => {
          prototype.beforeSubmit = original;
          return prototype.beforeSubmit === original;
        };
        select.value = 'show';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      })();
      JS);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementTextContains('css', '#jquery-bc-test-result', 'show');
    $this->assertSame([
      ['second', TRUE, 3],
      ['first', TRUE, 3, TRUE, TRUE, TRUE, TRUE],
      ['original return', TRUE],
      ['first return', 'first return', TRUE, TRUE],
    ], $session->evaluateScript('window.callbackTrace'));
    $this->assertTrue($session->evaluateScript('window.restoreCallback()'));
  }

  /**
   * Tests callback assignment and explicit opt-in on the fixture pages.
   */
  public function testCallbackAssignmentSeam(): void {
    $session = $this->getSession();
    $native = [
      ['built-in', TRUE, 3, TRUE, TRUE, TRUE],
      ['native default result', TRUE],
      ['native override', TRUE, 0],
      ['built-in', TRUE, 3, TRUE, TRUE, TRUE],
      ['before opt-in', TRUE, TRUE],
    ];
    $legacy_call = [
      ['second', TRUE, 3],
      ['first', TRUE, 3, TRUE, TRUE, TRUE, TRUE, TRUE],
      ['built-in', TRUE, 3, TRUE, TRUE, TRUE],
      ['predecessor result', TRUE, TRUE],
    ];
    $mixed = [
      ...$native,
      ['late opt-in', TRUE, 0],
      ['native override', TRUE, 0],
      ['built-in', TRUE, 3, TRUE, TRUE, TRUE],
      ['mixed native', TRUE, TRUE],
      ...$legacy_call,
      ['legacy veto', TRUE, 1],
      ...$legacy_call,
      ['legacy veto again', TRUE, 2],
      ['built-in', TRUE, 3, TRUE, TRUE, TRUE],
      ['restored', TRUE, TRUE, 3],
      ['disconnected', TRUE, TRUE, TRUE, TRUE, TRUE, 3],
      ['native override', TRUE, 3],
      ['built-in', TRUE, 3, TRUE, TRUE, TRUE],
      ['native after teardown', TRUE, 3],
    ];
    foreach (['native-events' => $native, 'bridge-events' => $mixed] as $page => $expected) {
      $this->drupalGet("jquery-bc-test/$page");
      $session->executeScript(<<<'JS'
        const path = 'core/modules/system/tests/modules/jquery_bc_test/js/callback-chain.js';
        import(drupalSettings.path.baseUrl + path).then(({ runCallbackChain }) => {
          window.runCallbackChain = runCallbackChain;
        });
        JS);
      $this->assertJsCondition('typeof window.runCallbackChain === "function"');
      $this->assertSame($expected, $session->evaluateScript(<<<'JS'
        window.runCallbackChain(document.getElementById('event-bridge-fixture'), window.jQuery)
        JS), $page);
    }
  }

}
