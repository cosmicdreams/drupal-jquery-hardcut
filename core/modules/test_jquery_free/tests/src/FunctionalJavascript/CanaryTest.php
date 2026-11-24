<?php

declare(strict_types=1);

namespace Drupal\Tests\test_jquery_free\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore rescan

/**
 * Tests the browser canary on a fixture page.
 */
#[Group('test_jquery_free')]
#[RunTestsInSeparateProcesses]
final class CanaryTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['test_jquery_free', 'jqf_script'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests reads and assignments of jQuery globals.
   */
  public function testCanary(): void {
    $session = $this->getSession();
    $this->drupalGet('test-jquery-free/probe');
    $this->assertSession()->elementExists('css', 'body[data-jquery="undefined"][data-alias="undefined"]');
    $warnings = $session->evaluateScript('window.jqfWarnings');
    $this->assertSession()->elementExists('css', 'body[data-late-jquery="undefined"][data-late-alias="undefined"]');
    $this->assertCount(3, $warnings);
    $this->assertSame(['jQuery', 'jQuery', '$'], array_column($warnings, 2));
    $this->assertMatchesRegularExpression('~/jqf_script/js/probe\.js(?:\?[^ ]*)?:\d+$~D', $warnings[0][1]);
    $this->assertMatchesRegularExpression('~/jqf_script/js/late-probe\.js(?:\?[^ ]*)?:\d+$~D', $warnings[1][1]);
    $this->assertMatchesRegularExpression('~/jqf_script/js/late-probe\.js(?:\?[^ ]*)?:\d+$~D', $warnings[2][1]);
    $this->assertSame('jQuery readiness: %s accessed window.%s', $warnings[0][0]);
    $this->assertFalse($session->evaluateScript('Object.prototype.propertyIsEnumerable.call(window, "jQuery")'));

    $this->assertNotSame($warnings[1][1], $warnings[2][1]);
    $this->drupalGet('test-jquery-free/probe', ['query' => ['define' => 1]]);
    $this->assertTrue($session->evaluateScript('window.jQuery === null && typeof window.$ === "object"'));
    $warnings = $session->evaluateScript('window.jqfWarnings');
    $this->assertCount(4, $warnings);
    $this->assertSame('jQuery readiness: %s replaced a jQuery global', $warnings[3][0]);
    $this->assertMatchesRegularExpression('~/jqf_script/js/late-probe\.js(?:\?[^ ]*)?$~D', $warnings[3][1]);

    $this->drupalGet('test-jquery-free/probe', ['query' => ['preset' => 1]]);
    $this->assertSession()->elementExists('css', 'body[data-jquery="string"][data-alias="undefined"]');
    $this->assertSame('existing jQuery', $session->evaluateScript('window.jQuery'));
    $this->assertSame(
      [['jQuery readiness: jQuery is present. Existing globals are left in place.']],
      $session->evaluateScript('window.jqfWarnings'),
    );
  }

  /**
   * Checks that core pages load no jQuery assets and arm both accessors.
   */
  public function testCorePages(): void {
    $this->config('system.performance')
      ->set('css.preprocess', FALSE)
      ->set('js.preprocess', FALSE)
      ->save();
    $account = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($account);
    $session = $this->getSession();
    foreach ([
      '<front>' => 'main',
      'user/' . $account->id() => 'main',
      'admin/reports/status' => '.system-status-report-counters',
    ] as $page => $selector) {
      $this->drupalGet($page);
      $this->assertSession()->elementExists('css', $selector);
      $this->assertSession()->pageTextNotContains('Access denied');
      $this->assertSame([], $session->evaluateScript(<<<'JS'
        (() => {
          const resources = [
            ...performance.getEntriesByType('resource').map(({ name }) => name),
            ...Array.from(document.querySelectorAll('script[src], link[rel~="stylesheet"][href]'),
              (asset) => asset.src || asset.href),
          ];
          return resources.filter((url) => /(?:^|[/@])jquery(?:[./_@-]|$)/i.test(new URL(url).pathname));
        })()
        JS), $page);
      $this->assertTrue($session->evaluateScript(<<<'JS'
        ['jQuery', '$'].every((name) => {
          const descriptor = Object.getOwnPropertyDescriptor(window, name);
          return typeof descriptor?.get === 'function' &&
            typeof descriptor?.set === 'function' &&
            typeof descriptor.get.call(window) === 'undefined';
        })
        JS), $page);
    }
  }

  /**
   * Tests a rescan from the administrative report.
   */
  public function testReportRescan(): void {
    $report = 'admin/reports/jquery-readiness';
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet($report);
    $this->assertSession()->pageTextContains('You are not authorized to access this page.');
    $this->assertSession()->buttonNotExists('Rescan');

    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    foreach ([
      'document.querySelector("[name=form_token]").remove()',
      'document.querySelector("[name=form_token]").value = "invalid"',
    ] as $tamper) {
      $this->drupalGet($report);
      $this->assertSession()->pageTextContains('No scan yet');
      $this->getSession()->executeScript($tamper);
      $this->submitForm([], 'Rescan');
      $this->assertSession()->pageTextContains('The form has become outdated.');
      $this->drupalGet($report);
      $this->assertSession()->pageTextContains('No scan yet');
    }
    $this->submitForm([], 'Rescan');
    $this->assertSession()->addressEquals('admin/reports/jquery-readiness');
    $this->assertSession()->pageTextContains('No jQuery references found');
    $this->assertSession()->pageTextContains('Advisory: static analysis is best-effort. Enable this module and exercise the site to confirm, using the browser console canary.');
    $this->drupalGet($report);
    $this->assertSession()->pageTextContains('No jQuery references found');
  }

}
