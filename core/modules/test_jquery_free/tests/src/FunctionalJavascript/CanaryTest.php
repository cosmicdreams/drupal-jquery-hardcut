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
    $this->assertCount(1, $warnings);
    $this->assertSame('jQuery readiness: %s accessed window.%s', $warnings[0][0]);
    $this->assertStringEndsWith('/jqf_script/js/probe.js', parse_url($warnings[0][1], PHP_URL_PATH));
    $this->assertSame('jQuery', $warnings[0][2]);

    $this->drupalGet('test-jquery-free/probe', ['query' => ['preset' => 1]]);
    $this->assertSession()->elementExists('css', 'body[data-jquery="string"][data-alias="undefined"]');
    $this->assertSame('existing jQuery', $session->evaluateScript('window.jQuery'));
    $this->assertSame(
      [['jQuery readiness: jQuery is present. Existing globals are left in place.']],
      $session->evaluateScript('window.jqfWarnings'),
    );
  }

}
