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
    $this->assertTrue($session->evaluateScript(<<<'JS'
      !Array.from(document.querySelectorAll('link[rel="stylesheet"]')).some(
        (asset) => asset.href.includes('/jquery.ui/')
      )
      JS));
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
   * Tests that tabbing works without the jQuery library.
   */
  public function testTabbingWithoutJquery(): void {
    \Drupal::service('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->drupalGet('tabbingmanager-test');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $this->assertSame(['none', 'none', FALSE], $session->evaluateScript(<<<'JS'
      (() => {
        const probe = document.body.appendChild(document.createElement('div'));
        probe.style.transition = 'opacity 1s';
        probe.style.animation = 'test-animation 1s';
        const style = getComputedStyle(probe);
        const legacyScript = Array.from(document.scripts).some(
          (script) => script.src.includes('/disable_transitions.theme.js')
        );
        return [style.transitionProperty, style.animationName, legacyScript];
      })()
      JS));
    $this->assertTrue($session->evaluateScript(<<<'JS'
      !Array.from(document.querySelectorAll('script[src], link[href]')).some((asset) =>
        /[\/]jquery(?:[./-]|$)/i.test(asset.src || asset.href)
      )
      JS));
    $session->executeScript(<<<'JS'
      const root = document.getElementById('tabbingmanager-test-container');
      Drupal.tabbingManager.constrainElements(root, { trapFocus: true });
      JS);
    $this->tab(TRUE);
    $this->assertSame('third', $session->evaluateScript('document.activeElement.id'));

    // Tabbing manager accepts document as input natively.
    $docResult = $session->evaluateScript(<<<'JS'
      (() => {
        const ctx = Drupal.tabbingManager.constrain(document);
        const ids = ctx.tabbableElements.map((el) => el.id);
        const disabled = ctx.disabledElements.length;
        ctx.release();
        return [ids.includes('first') && ids.includes('sixth'), disabled];
      })()
      JS);
    $this->assertSame([TRUE, 0], $docResult);
  }

  /**
   * Tests the lifecycle and legacy inputs on an explicitly mixed page.
   */
  public function testLegacyTabbingContext(): void {
    $this->drupalGet('tabbingmanager-test', ['query' => ['legacy' => 1]]);
    $this->assertTrue($this->getSession()->evaluateScript('jQuery.fx.off === true'));
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const warnings = [];
        const warn = Drupal.deprecationError;
        Drupal.deprecationError = ({ message }) => warnings.push(message);
        try {
          const events = [
            'drupalTabbingContextActivated',
            'drupalTabbingConstrained',
            'drupalTabbingContextDeactivated',
            'drupalTabbingContextReleased',
          ];
          const native = [];
          const legacy = [];
          events.forEach((type) => {
            document.addEventListener(type, (event) => native.push([type, event.detail]));
            jQuery(document).on(type, (event, context) => legacy.push([type, context]));
          });
          const manager = Drupal.tabbingManager;
          const record = manager.recordTabindex;
          const restore = manager.restoreTabindex;
          const nativeRecord = manager.recordTabindexElement;
          const nativeRestore = manager.restoreTabindexElement;
          let legacyOverride = false;
          manager.recordTabindex = function (element, level) {
            legacyOverride = element instanceof jQuery && this === manager;
            record.call(this, element, level);
          };
          const context = manager.constrain(
            jQuery('#tabbingmanager-test-container'),
            { trapFocus: true },
          );
          const collection = context.$tabbableElements;
          const original = context.$disabledElements;
          context.$disabledElements = original;
          const first = document.getElementById('first');
          const keyboard = jQuery.Event('keydown.focus-trap', { key: 'Tab', shiftKey: true });
          jQuery(first).trigger(keyboard);
          const reverse = [document.activeElement.id, keyboard.isDefaultPrevented()];
          context.release();
          const initialWarnings = warnings.length;

          // Restoring recordTabindex/restoreTabindex must not re-wrap native methods.
          manager.recordTabindex = record;
          manager.restoreTabindex = restore;
          const restoredCleanly =
            manager.recordTabindexElement === nativeRecord &&
            manager.restoreTabindexElement === nativeRestore;
          const docContext = manager.constrain(jQuery(document));
          const docCount = docContext.tabbableElements.length;
          docContext.release();

          return {
            types: legacy.map(([type]) => type).slice(0, 4),
            sameContext: native.length >= events.length && events.every(
              (type, index) => native[index][1] === context && legacy[index][0] === type && legacy[index][1] === context
            ),
            legacyOverride,
            reverse,
            collection: collection === context.$tabbableElements && collection[0] === first,
            disabled: original === context.$disabledElements && original[0] === context.disabledElements[0],
            warnings: initialWarnings,
            restoredCleanly,
            docCount: docCount >= 6,
          };
        } finally {
          Drupal.deprecationError = warn;
        }
      })()
      JS);
    $this->assertSame([
      'drupalTabbingContextActivated',
      'drupalTabbingConstrained',
      'drupalTabbingContextDeactivated',
      'drupalTabbingContextReleased',
    ], $actual['types']);
    $this->assertTrue($actual['sameContext']);
    $this->assertTrue($actual['legacyOverride']);
    $this->assertSame(['third', TRUE], $actual['reverse']);
    $this->assertTrue($actual['collection']);
    $this->assertTrue($actual['disabled']);
    $this->assertSame(4, $actual['warnings']);
    $this->assertTrue($actual['restoredCleanly']);
    $this->assertTrue($actual['docCount']);
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
    $driver->postActions([
      'actions' => [
        ['type' => 'key', 'id' => 'keyboard', 'actions' => $actions],
      ],
    ]);
  }

}
