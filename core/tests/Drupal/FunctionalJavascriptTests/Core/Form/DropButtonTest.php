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
        const originalFocusOut = prototype.focusOut;
        const originalHoverIn = prototype.hoverIn;
        const originalHoverOut = prototype.hoverOut;
        const calls = [];
        prototype.focusOut = function (event) {
          calls.push(['focusOut', event instanceof jQuery.Event, this.$actions.length, this.$dropbutton[0] === event.target]);
          originalFocusOut.call(this, event);
        };
        prototype.hoverIn = function (event) {
          calls.push(['hoverIn', event instanceof jQuery.Event, this.$list.length]);
          originalHoverIn.call(this, event);
        };
        prototype.hoverOut = function (event) {
          calls.push(['hoverOut', event instanceof jQuery.Event]);
          originalHoverOut.call(this, event);
          return false;
        };
        try {
          const context = document.body.appendChild(document.createElement('div'));
          context.innerHTML = '<div class="dropbutton-wrapper"><ul class="dropbutton"><li>First</li><li>Second</li></ul></div>';
          Drupal.behaviors.dropButton.attach(context, drupalSettings);
          const element = context.querySelector('.dropbutton-wrapper');
          jQuery(element).trigger('focusout.dropbutton');
          jQuery(element).trigger('mouseenter.dropbutton');
          const leaveEvent = jQuery.Event('mouseleave.dropbutton');
          jQuery(element).trigger(leaveEvent);
          jQuery(element).off('.dropbutton');
          jQuery(element).trigger('focusout.dropbutton');

          // Legacy constructor accepts a jQuery collection.
          const legacyWrapper = document.createElement('div');
          legacyWrapper.className = 'dropbutton-wrapper';
          legacyWrapper.innerHTML = '<ul class="dropbutton"><li>One</li><li>Two</li></ul>';
          const legacyInstance = new Drupal.DropButton(jQuery(legacyWrapper), {});
          return [
            calls,
            [leaveEvent.result, leaveEvent.isDefaultPrevented(), leaveEvent.isPropagationStopped()],
            legacyInstance.wrapperElement === legacyWrapper && legacyInstance.$dropbutton[0] === legacyWrapper,
          ];
        } finally {
          prototype.focusOut = originalFocusOut;
          prototype.hoverIn = originalHoverIn;
          prototype.hoverOut = originalHoverOut;
          Drupal.deprecationError = warn;
        }
      })()
      JS);
    $this->assertSame([
      [
        ['focusOut', TRUE, 2, TRUE],
        ['hoverOut', TRUE],
        ['hoverIn', TRUE, 1],
        ['hoverOut', TRUE],
      ],
      [FALSE, TRUE, TRUE],
      TRUE,
    ], $result);
  }

  /**
   * Tests pre-bridge prototype overrides and native mouseenter/mouseleave delivery.
   */
  public function testPreBridgeOverridesAndNativeMouseEvents(): void {
    $this->drupalGet('form-test/dropbutton');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $scripts = json_encode(array_column(
      \Drupal::service('library.discovery')->getLibraryByName('core', 'jquery')['js'],
      'data',
    ));
    $session->executeScript(<<<JS
      (async () => {
        const calls = [];
        Drupal.DropButton.prototype.hoverIn = function (event) {
          calls.push(['hoverIn', typeof window.jQuery === 'function' && event instanceof jQuery.Event]);
        };
        Drupal.DropButton.prototype.hoverOut = function (event) {
          calls.push(['hoverOut', typeof window.jQuery === 'function' && event instanceof jQuery.Event]);
        };
        for (const path of $scripts) {
          await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = drupalSettings.path.baseUrl + path;
            script.onload = resolve;
            script.onerror = reject;
            document.head.append(script);
          });
        }
        const originalDispatch = jQuery.event.dispatch;
        let dispatchCalls = 0;
        jQuery.event.dispatch = function (...args) {
          dispatchCalls += 1;
          return originalDispatch.apply(this, args);
        };
        try {
          const wrapper = document.querySelector('.dropbutton-wrapper');
          wrapper.dispatchEvent(new MouseEvent('mouseenter'));
          wrapper.dispatchEvent(new MouseEvent('mouseleave'));
          window.dropButtonPreBridgeResult = [calls, dispatchCalls];
        } finally {
          jQuery.event.dispatch = originalDispatch;
        }
      })();
      JS);
    $this->assertJsCondition('Array.isArray(window.dropButtonPreBridgeResult)');
    $this->assertSame([
      [['hoverIn', TRUE], ['hoverOut', TRUE]],
      0,
    ], $session->evaluateScript('window.dropButtonPreBridgeResult'));
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
