<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Ajax;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests progress polling.
 */
#[Group('Ajax')]
#[RunTestsInSeparateProcesses]
class ProgressTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ajax_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the empty native POST and its original query bytes.
   */
  public function testNativeProgressRequest(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const uri = Drupal.url('ajax-test/echo-native-request?progress=1&value=a%20b%7e');
        const trace = [];
        const { open, setRequestHeader, send } = XMLHttpRequest.prototype;
        XMLHttpRequest.prototype.open = function (method, url, ...args) {
          trace.push([method, new URL(url, location.href).search]);
          return open.call(this, method, url, ...args);
        };
        XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
          if (name.toLowerCase() === 'content-type') trace.push(value);
          return setRequestHeader.call(this, name, value);
        };
        XMLHttpRequest.prototype.send = function (body) {
          trace.push(body);
          return send.call(this, body);
        };
        try {
          const done = new Promise((resolve) => {
            const progress = new Drupal.ProgressBar('native', (percentage, message, bar) => {
              bar.stopMonitoring();
              resolve([percentage, message]);
            }, 'POST');
            document.body.append(progress.elementNode);
            progress.startMonitoring(uri, 10);
          });
          return [trace, await done];
        }
        finally {
          Object.assign(XMLHttpRequest.prototype, { open, setRequestHeader, send });
        }
      })()
      JS);
    $this->assertSame([
      [['POST', '?progress=1&value=a%20b%7e&_format=json'], NULL],
      [100, 'POST XMLHttpRequest'],
    ], $result);
  }

  /**
   * Tests polling through responses without a progress payload.
   */
  public function testProgressWithoutResponseBody(): void {
    $this->drupalGet('ajax-test/native-request', ['query' => ['commands' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const replies = [
          { status: 'nocontent' },
          { status: 'notmodified' },
          { status: 'success', response: null },
          { status: 'success', response: { percentage: 100 } },
        ];
        const trace = [];
        const request = Drupal.ajaxRequest;
        Drupal.ajaxRequest = (options, callback) => ({
          commands: Promise.resolve().then(() => {
            const reply = replies.shift();
            trace.push(reply.status);
            return callback(reply);
          }),
        });
        let bar;
        try {
          const done = new Promise((resolve, reject) => {
            bar = new Drupal.ProgressBar('no-content', (percentage) => {
              trace.push(percentage);
              bar.stopMonitoring();
              resolve();
            }, 'GET', reject);
            document.body.append(bar.elementNode);
            bar.startMonitoring(location.href, 5);
          });
          await done;
          return [trace, bar.elementNode.querySelector('.progress__percentage').textContent];
        }
        finally {
          Drupal.ajaxRequest = request;
          bar?.stopMonitoring();
        }
      })()
      JS);
    $this->assertSame([['nocontent', 'notmodified', 'success', 'success', 100], '100%'], $result);
  }

}
