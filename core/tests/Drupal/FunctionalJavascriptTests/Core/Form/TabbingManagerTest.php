<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core\Form;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests page tab order and focus constraints.
 */
#[Group('form')]
#[RunTestsInSeparateProcesses]
class TabbingManagerTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['tabbingmanager_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'default_admin';

  /**
   * Tests unrestricted tabbing, wrapping and resetting stacked constraints.
   */
  public function testTabbingOrder(): void {
    $this->drupalGet('tabbingmanager-test');
    $session = $this->getSession();
    $focus = fn (): string => $session->evaluateScript('document.activeElement.id');

    $session->executeScript("document.getElementById('third').focus()");
    $this->tab();
    $this->assertSame('fourth', $focus());

    $session->executeScript(<<<'JS'
      document.getElementById('sixth').setAttribute('tabindex', '0');
      document.getElementById('sixth').setAttribute('autofocus', 'autofocus');
      window.firstConstraint = Drupal.tabbingManager.constrain('#tabbingmanager-test-container', { trapFocus: true });
      JS);
    $this->assertSame('first', $focus());
    $this->assertSame('-1', $session->evaluateScript("document.getElementById('sixth').getAttribute('tabindex')"));
    $this->assertFalse($session->evaluateScript("document.getElementById('sixth').hasAttribute('autofocus')"));

    $this->tab(TRUE);
    $this->assertSame('third', $focus());
    $this->tab();
    $this->assertSame('first', $focus());

    $session->executeScript(<<<'JS'
      window.secondConstraint = Drupal.tabbingManager.constrain('#tabbingmanager-test-another-container', { trapFocus: true });
      JS);
    $this->assertSame('sixth', $focus());
    $session->executeScript('window.secondConstraint.release()');
    $this->assertSame('first', $focus());
    $session->executeScript('window.firstConstraint.release()');
    $this->assertSame('0', $session->evaluateScript("document.getElementById('sixth').getAttribute('tabindex')"));
    $this->assertTrue($session->evaluateScript("document.getElementById('sixth').hasAttribute('autofocus')"));
    $this->assertSame(0, $session->evaluateScript('Drupal.tabbingManager.stack.length'));
    $session->executeScript("document.getElementById('third').focus()");
    $this->tab();
    $this->assertSame('fourth', $focus());
  }

  /**
   * Sends a Tab keystroke through the browser's keyboard input source.
   */
  private function tab(bool $reverse = FALSE): void {
    $actions = [
      ['type' => 'keyDown', 'value' => "\u{E004}"],
      ['type' => 'keyUp', 'value' => "\u{E004}"],
    ];
    if ($reverse) {
      array_unshift($actions, ['type' => 'keyDown', 'value' => "\u{E008}"]);
      $actions[] = ['type' => 'keyUp', 'value' => "\u{E008}"];
    }
    $driver = $this->getSession()->getDriver()->getWebDriverSession();
    $driver->postActions(['actions' => [
      ['type' => 'key', 'id' => 'keyboard', 'actions' => $actions],
    ]]);
  }

}
