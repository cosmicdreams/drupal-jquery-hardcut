<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that unnecessary or untracked XHRs will cause a test failure.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
class AjaxWaitTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ajax_test'];

  /**
   * Tests that an unnecessary wait triggers an error.
   */
  public function testUnnecessaryWait(): void {
    $this->drupalGet('user');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageIs('There are no AJAX requests to wait for.');

    $this->assertSession()->assertWaitOnAjaxRequest(500);
  }

  /**
   * Tests that an untracked XHR triggers an error.
   */
  public function testUntrackedXhr(): void {
    $this->getSession()->executeScript(<<<JS
let xhr = new XMLHttpRequest();
xhr.open('GET', '/foobar');
xhr.send();
JS);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageIs('0 tracked AJAX requests, but 1 observed in the browser — this requires js_testing_ajax_request_test.js to be updated.');

    $this->assertSession()->assertExpectedAjaxRequest(1, 500);
  }

  /**
   * Tests that XMLHttpRequest and fetch are tracked.
   */
  public function testNativeRequests(): void {
    $this->drupalGet('user');
    $this->getSession()->executeScript(<<<JS
      const xhr = new XMLHttpRequest();
      xhr.open('GET', window.location.href);
      xhr.send();
      fetch(window.location.href);
      JS);
    $this->assertSession()->assertExpectedAjaxRequest(2);
  }

  /**
   * Tests that idle waits include commands pending after the response.
   */
  public function testPendingCommands(): void {
    $this->drupalGet('ajax-test/promise-form');
    $session = $this->getSession();
    $condition = JSWebAssert::ajaxIdleCondition();
    $this->assertTrue($session->wait(500, $condition));
    $session->executeScript(<<<'JS'
      const commands = Drupal.AjaxCommands.prototype;
      const original = commands.ajaxCommandReturnPromise;
      commands.ajaxCommandReturnPromise = function (ajax, response) {
        commands.ajaxCommandReturnPromise = original;
        return new Promise((resolve) => {
          window.releaseAjaxCommand = () => {
            this.insert(ajax, response);
            resolve();
          };
        });
      };
      JS);
    try {
      $session->getPage()->pressButton('Execute commands button');
      $this->assertTrue($session->wait(5000, 'window.drupalActiveXhrCount === 0 && typeof window.releaseAjaxCommand === "function"'));
      $this->assertSession()->elementTextEquals('css', '#ajax_test_form_promise_wrapper', '1');
      $this->assertFalse($session->wait(250, $condition));
    }
    finally {
      $session->executeScript('window.releaseAjaxCommand?.()');
    }
    $this->assertTrue($session->wait(5000, $condition));
    $this->assertSession()->elementTextEquals('css', '#ajax_test_form_promise_wrapper', '12345');
  }

}
