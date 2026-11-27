<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core\Form;

use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests visible dropbutton actions and explicit legacy adapters.
 */
#[Group('form')]
#[RunTestsInSeparateProcesses]
class DropButtonTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['form_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the toggle exposes and hides secondary actions by keyboard.
   */
  #[TestWith(['stark'])]
  #[TestWith(['default_admin'])]
  public function testKeyboardToggle(string $theme): void {
    \Drupal::service('theme_installer')->install([$theme]);
    $this->config('system.theme')->set('default', $theme)->save();
    $this->drupalGet('form-test/dropbutton');

    $assert = $this->assertSession();
    $wrapper = $assert->elementExists('css', '.dropbutton-wrapper');
    $secondary = $assert->elementExists('named', ['link', 'Secondary action']);
    $this->assertFalse($wrapper->hasClass('open'));
    $this->assertFalse($secondary->isVisible());

    $this->getSession()->executeScript("document.querySelector('.dropbutton-toggle button').focus()");
    $this->pressEnter();
    $this->assertTrue($wrapper->hasClass('open'));
    $this->assertTrue($secondary->isVisible());

    $this->pressEnter();
    $this->assertFalse($wrapper->hasClass('open'));
    $this->assertFalse($secondary->isVisible());
  }

  /**
   * Activates the focused button through the browser keyboard input source.
   */
  private function pressEnter(): void {
    $driver = $this->getSession()->getDriver();
    assert($driver instanceof DrupalSelenium2Driver);
    $driver->getWebDriverSession()->postActions([
      'actions' => [
        [
          'type' => 'key',
          'id' => 'keyboard',
          'actions' => [
            ['type' => 'keyDown', 'value' => "\u{E007}"],
            ['type' => 'keyUp', 'value' => "\u{E007}"],
          ],
        ],
      ],
    ]);
  }

}
