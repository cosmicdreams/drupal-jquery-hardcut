<?php

declare(strict_types=1);

namespace Drupal\Tests\test_jquery_free\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

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
   * Tests missing and preexisting jQuery globals.
   */
  public function testCanary(): void {
    $session = $this->getSession();
    $this->drupalGet('test-jquery-free/probe');
    $this->assertSession()->elementExists('css', 'body[data-jquery="undefined"][data-alias="undefined"]');
    $warnings = $session->evaluateScript('window.jqfWarnings');
    $this->assertSession()->elementExists('css', 'body[data-late-jquery="undefined"][data-late-alias="undefined"]');
    $this->assertCount(3, $warnings);
    $this->assertSame(['jQuery', 'jQuery', '$'], array_column($warnings, 2));
    $this->assertMatchesRegularExpression('~/jqf_script/js/probe\.js(?:\?[^ ]*)?:7$~D', $warnings[0][1]);
    $this->assertMatchesRegularExpression('~/jqf_script/js/late-probe\.js(?:\?[^ ]*)?:10$~D', $warnings[1][1]);
    $this->assertMatchesRegularExpression('~/jqf_script/js/late-probe\.js(?:\?[^ ]*)?:12$~D', $warnings[2][1]);
    $this->assertSame('jQuery readiness: %s accessed window.%s', $warnings[0][0]);
    $this->assertFalse($session->evaluateScript('Object.prototype.propertyIsEnumerable.call(window, "jQuery")'));

    $this->assertTrue($session->evaluateScript(<<<'JS'
      (() => {
        const assigned = {
          get fn() {
            throw new Error('The canary must not read fn');
          },
        };
        window.jQuery = assigned;
        return window.jQuery === assigned;
      })()
      JS));
    $this->assertSame($warnings, $session->evaluateScript('window.jqfWarnings'));

    $this->drupalGet('test-jquery-free/probe', ['query' => ['preset' => 1]]);
    $this->assertSession()->elementExists('css', 'body[data-jquery="string"][data-alias="undefined"]');
    $this->assertSame('existing jQuery', $session->evaluateScript('window.jQuery'));
    $this->assertSame(
      [['jQuery readiness: jQuery is present. Existing globals are left in place.']],
      $session->evaluateScript('window.jqfWarnings'),
    );
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
    $this->drupalGet($report);
    $this->assertSession()->pageTextContains('No jQuery references found');
  }

}
