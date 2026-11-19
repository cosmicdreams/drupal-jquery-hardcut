<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the event fixture with a theme override of the jQuery vendor asset.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
final class JqueryVendorOverrideTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['jquery_bc_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'jquery_bc_test_theme';

  /**
   * Tests both bridge orders and the native route under the override.
   */
  public function testVendorOverride(): void {
    $session = $this->getSession();
    $this->drupalGet('jquery-bc-test/native-events');
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));

    foreach (['bridge-events' => FALSE, 'bridge-events-early' => TRUE] as $path => $early) {
      $this->drupalGet("jquery-bc-test/$path");
      $this->assertSame(['ready', 1], $session->evaluateScript("JSON.parse(document.getElementById('event-bridge-fixture').dataset.bridgeTrace)"));
      $this->assertSame([TRUE, FALSE, TRUE, TRUE, TRUE, TRUE, $early], $session->evaluateScript(<<<'JS'
        (() => {
          const scripts = Array.from(document.scripts, ({ src }) => src ? new URL(src).pathname : '');
          const index = (path) => scripts.findIndex((src) => src.endsWith(path));
          const vendor = index('/core/assets/vendor/jquery/jquery.js');
          const original = index('/core/assets/vendor/jquery/jquery.min.js');
          const alias = index('/core/misc/jquery.no-conflict.js');
          const adapter = index('/core/misc/jquery.drupal-bc.js');
          const drupal = index('/core/misc/drupal.js');
          const dependent = index('/js/event-bridge-dependent.js');
          return [vendor >= 0, original >= 0, alias >= 0, alias < vendor,
            adapter > vendor, dependent > adapter && dependent > drupal, adapter < drupal];
        })()
        JS));
    }
  }

}
