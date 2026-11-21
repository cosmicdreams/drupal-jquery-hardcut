<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core\Form;

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
    if ($theme === 'stark') {
      $this->assertSame('undefined', $this->getSession()->evaluateScript('typeof window.jQuery'));
    }


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
   * Tests the legacy collection and event passed to a contributed override.
   */
  public function testLegacyDropButton(): void {
    $this->drupalGet('form-test/dropbutton', ['query' => ['legacy' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const warn = Drupal.deprecationError;
        Drupal.deprecationError = () => {};
        const prototype = Drupal.DropButton.prototype;
        const original = prototype.focusOut;
        const calls = [];
        prototype.focusOut = function (event) {
          calls.push([event instanceof jQuery.Event, this.$actions.length, this.$dropbutton[0] === event.target]);
          original.call(this, event);
        };
        try {
          const context = document.body.appendChild(document.createElement('div'));
          context.innerHTML = '<div class="dropbutton-wrapper"><ul class="dropbutton"><li>First</li><li>Second</li></ul></div>';
          Drupal.behaviors.dropButton.attach(context, drupalSettings);
          const element = context.querySelector('.dropbutton-wrapper');
          jQuery(element).trigger('focusout.dropbutton');
          jQuery(element).off('.dropbutton');
          jQuery(element).trigger('focusout.dropbutton');
          return calls;
        } finally {
          prototype.focusOut = original;
          Drupal.deprecationError = warn;
        }
      })()
      JS);
    $this->assertSame([[TRUE, 2, TRUE]], $result);
  }

  /**
   * Activates the focused button through the browser keyboard input source.
   */
  private function pressEnter(): void {
    $this->getSession()->getDriver()->getWebDriverSession()->postActions([
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
