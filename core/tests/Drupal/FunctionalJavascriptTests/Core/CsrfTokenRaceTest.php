<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Test race condition for CSRF tokens for simultaneous requests.
 */
#[Group('Session')]
#[RunTestsInSeparateProcesses]
class CsrfTokenRaceTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['csrf_race_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests race condition for CSRF tokens for simultaneous requests.
   */
  public function testCsrfRace(): void {
    $user = $this->createUser(['access content']);
    $this->drupalLogin($user);
    $this->drupalGet('/csrf_race/test');
    $this->assertSame('function', $this->getSession()->evaluateScript('typeof Drupal.debounce'));
    // Delay the first response to overlap the two token requests.
    $this->getSession()->executeScript(<<<JS
      [1, 0].forEach(async (delay) => {
        const response = await fetch("$this->baseUrl/csrf_race/get_csrf_token/" + delay, {
          headers: { "Content-Type": "application/json" },
        });
        const token = document.createElement('p');
        token.className = 'csrf' + delay;
        token.textContent = await response.json();
        document.body.append(token);
      });
      JS);
    $assert_session = $this->assertSession();
    $assert_session->assertExpectedAjaxRequest(2);
    $token0 = $assert_session->elementExists('css', '.csrf0')->getText();
    $token1 = $assert_session->elementExists('css', '.csrf1')->getText();
    $this->assertNotEmpty($token0);
    $this->assertNotEmpty($token1);
    $this->assertEquals($token0, $token1);
  }

}
