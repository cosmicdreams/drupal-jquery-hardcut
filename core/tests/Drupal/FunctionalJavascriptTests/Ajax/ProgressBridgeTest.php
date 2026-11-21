<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Ajax;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests progress polling on native and mixed pages.
 */
#[Group('Ajax')]
#[RunTestsInSeparateProcesses]
class ProgressBridgeTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ajax_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the progress adapter preserves request options and events.
   */
  #[IgnoreDeprecations]
  public function testMixedProgressPolling(): void {
    $this->expectUserDeprecationMessage('Javascript Deprecation: Drupal.ProgressBar#element is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native elementNode property. See https://www.drupal.org/node/CHANGE_RECORD.');
    $this->drupalGet('ajax-test/global-events');
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const uri = Drupal.url('ajax-test/echo-native-request?progress=1&value=a%20b%7e');
        const trace = [];
        const namespace = '.progressProbe';
        jQuery.ajaxSetup({ headers: { 'X-Requested-With': 'ProgressProbe' } });
        jQuery.ajaxPrefilter('json', (options) => {
          if (options.url.includes('progress=1')) trace.push('prefilter');
        });
        jQuery(document).on('ajaxStart' + namespace, () => trace.push(jQuery.active));
        const response = new Promise((resolve) => {
          jQuery(document).on('ajaxComplete' + namespace, (event, xhr) => resolve(xhr.responseJSON));
        });
        const progress = new Drupal.ProgressBar('mixed', (percentage, message, bar) => {
          trace.push(bar.element instanceof jQuery && bar.element[0] === bar.elementNode);
          bar.stopMonitoring();
        }, 'POST');
        document.body.append(progress.elementNode ?? progress.element);
        progress.startMonitoring(uri, 10);
        const data = await Promise.race([response, new Promise((resolve) => setTimeout(() => resolve({}), 1500))]);
        return [trace, data.method, data.query, data.contentType, data.body, data.requestedWith, jQuery.active];
      })()
      JS);
    $this->assertSame([
      ['prefilter', 1, TRUE],
      'POST',
      'progress=1&value=a%20b%7e&_format=json',
      NULL,
      '',
      'ProgressProbe',
      0,
    ], $result);
  }

  /**
   * Tests that empty and not-modified responses leave progress intact.
   */
  public function testProgressWithoutResponseBody(): void {
    $this->drupalGet('ajax-test/global-events');
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (async () => {
        const trace = [];
        const responses = [[204, ''], [304, ''], [200, 'null'], [200, '{"percentage":100}']];
        jQuery.ajaxTransport('+json', (options) => {
          if (!options.url.includes('progress-status=1')) return;
          return {
            send(headers, complete) {
              const [status, body] = responses.shift();
              setTimeout(() => complete(status, '', { text: body }, 'Content-Type: application/json'), 0);
            },
            abort() {},
          };
        });
        const done = new Promise((resolve) => {
          jQuery(document).on('ajaxComplete', (event, xhr) => {
            trace.push(xhr.status);
            if (responses.length === 0) resolve();
          });
        });
        const bar = new Drupal.ProgressBar('no-content', (percentage, message, progress) => {
          trace.push(percentage);
          progress.stopMonitoring();
        }, 'GET', () => trace.push('error'));
        const element = bar.elementNode ?? bar.element;
        document.body.append(element);
        bar.startMonitoring(Drupal.url('ajax-test/echo-native-request?progress-status=1'), 5);
        await Promise.race([done, new Promise((resolve) => setTimeout(resolve, 1500))]);
        bar.stopMonitoring();
        return [trace, element.querySelector('.progress__percentage').textContent, jQuery.active];
      })()
      JS);
    $this->assertSame([[204, 304, 200, 100, 200], '100%', 0], $result);
  }

}
