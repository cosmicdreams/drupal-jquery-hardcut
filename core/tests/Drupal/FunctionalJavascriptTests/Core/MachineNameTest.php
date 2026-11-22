<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core;

use Drupal\FunctionalJavascriptTests\JqueryCallCounterTrait;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for the machine name field.
 */
#[Group('field')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
class MachineNameTest extends WebDriverTestBase {

  use JqueryCallCounterTrait;

  /**
   * Required modules.
   *
   * Node is required because the machine name callback checks for
   * access_content.
   *
   * @var array
   */
  protected static $modules = ['node', 'form_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $account = $this->drupalCreateUser([
      'access content',
    ]);
    $this->drupalLogin($account);
  }

  /**
   * Tests configured replacements and truncation in the machine name preview.
   */
  public function testReplacementAndTruncation(): void {
    $this->drupalGet('form-test/machine-name');
    $this->assertSame('undefined', $this->getSession()->evaluateScript('typeof window.jQuery'));
    $assert = $this->assertSession();
    $label = $assert->fieldExists('machine_name_2_label');
    $preview = '#edit-machine-name-2-label-machine-name-suffix .machine-name-value';

    $label->setValue('!Hi! There & 7?');
    $assert->elementTextEquals('css', $preview, 'hi-there-7');

    $label->setValue('ABCDEFGHIJKLMN');
    $assert->elementTextEquals('css', $preview, 'abcdefghijkl');
    $assert->fieldValueEquals('machine_name_2', 'abcdefghijkl');
  }

  /**
   * Tests legacy overrides and namespaced events on an explicitly mixed page.
   */
  public function testLegacyMachineNameCallback(): void {
    $this->drupalGet('form-test/machine-name', ['query' => ['legacy-machine-name' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const behavior = Drupal.behaviors.machineName;
        const original = behavior.showMachineName;
        const deprecation = Drupal.deprecationError;
        const warnings = [];
        Drupal.deprecationError = ({ message }) => warnings.push(message);
        const source = jQuery('#edit-machine-name-2-label');
        const target = document.getElementById('edit-machine-name-2');
        const calls = [];
        try {
          behavior.showMachineName = function (value, data) {
            calls.push([value, this === behavior && data.$target instanceof jQuery && data.$target[0] === target, data]);
            original.call(this, value, data);
          };
          source.val('First value').trigger('change.machineName');
          source.val('Second value').trigger('input.machineName');
          const shared = calls[0][2] === calls[1][2];
          source.off('.machineName');
          source.val('Third value').trigger('change.machineName');
          return [calls.map(([name, correct]) => [name, correct]), shared, target.value, warnings.length];
        } finally {
          behavior.showMachineName = original;
          Drupal.deprecationError = deprecation;
        }
      })()
      JS);
    $this->assertSame([
      [['first-value', TRUE], ['second-value', TRUE]],
      TRUE,
      'second-value',
      1,
    ], $actual);
  }

  /**
   * Tests that late bridge loading preserves machine-name before Ajax change order.
   */
  public function testLateBridgeLoadPreservesAjaxOrder(): void {
    $this->drupalGet('form-test/machine-name', ['query' => ['ajax' => 1]]);
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $scripts = json_encode(array_column(
      \Drupal::service('library.discovery')->getLibraryByName('core', 'jquery')['js'],
      'data',
    ));
    $session->executeScript(<<<JS
      (async () => {
        const source = document.getElementById('edit-machine-name-2-label');
        const target = document.getElementById('edit-machine-name-2');
        const seen = [];
        const ajax = Drupal.ajax({ element: source, event: 'change', url: location.href });
        ajax.eventResponse = () => {
          seen.push(target.value);
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
        source.value = 'Native Order';
        source.dispatchEvent(new Event('change', { bubbles: true }));
        source.value = 'Legacy Order';
        jQuery(source).trigger('change');
        ajax.removeEventBindings();
        window.lateMachineNameOrderResult = seen;
      })();
      JS);
    $this->assertJsCondition('Array.isArray(window.lateMachineNameOrderResult)');
    $this->assertSame(['native-order', 'legacy-order'], $session->evaluateScript('window.lateMachineNameOrderResult'));
  }

  /**
   * Tests a contrib handler bound after core's machine-name binding.
   *
   * Contrib runs after core, reads the new machine name and cannot suppress
   * it, whether the adapters load early, only the form adapter loads late, or
   * all of core/jquery loads after core binds.
   *
   * A contrib handler bound after core but before jquery.drupal-bc.js loads
   * is not covered: its order relative to core is unknown. core/jquery loads
   * its files consecutively, so no contrib code runs in that window.
   */
  public function testLateAdapterWindowKeepsCoreFirst(): void {
    foreach ($this->loadingModes() as $mode => $phases) {
      $this->drupalGet('form-test/machine-name');
      $session = $this->getSession();
      $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
      [$beforeAttach, $beforeContrib, $afterContrib] = array_map('json_encode', $phases);
      $session->executeScript($this->widgetHelpers() . <<<JS
        (async () => {
          await load($beforeAttach);
          const { source, target } = attachWidget(createWidget('window'));
          await load($beforeContrib);
          const seen = [];
          jQuery(source).on('input', (event) => {
            seen.push([event.isTrigger ? 'synthetic' : 'native', target.value]);
            event.stopImmediatePropagation();
          });
          await load($afterContrib);
          source.value = 'Native Window';
          source.dispatchEvent(new Event('input'));
          const afterNative = target.value;
          jQuery(source).val('Synthetic Window').trigger('input');
          window.lateAdapterWindowResult = [seen, afterNative, target.value];
        })();
        JS);
      $this->assertJsCondition('Array.isArray(window.lateAdapterWindowResult)');
      $this->assertSame([
        [['native', 'native_window'], ['synthetic', 'synthetic_window']],
        'native_window',
        'synthetic_window',
      ], $session->evaluateScript('window.lateAdapterWindowResult'), "Loading mode: $mode.");
    }
  }

  /**
   * Tests a contrib handler bound before core's machine-name binding.
   *
   * The handler stops immediate propagation, so core leaves the machine name
   * untouched for real and synthetic events, as with the pinned bindings.
   * This holds even when jquery.drupal-bc.js loads after both bindings.
   */
  public function testContribBeforeCoreStopsMachineName(): void {
    $modes = $this->loadingModes();
    unset($modes['late']);
    $scripts = $this->jqueryScripts();
    $bridge = array_key_first(array_filter($scripts, fn ($path) => str_ends_with($path, 'jquery.drupal-bc.js')));
    $modes['late base bridge'] = [array_slice($scripts, 0, $bridge), [], array_slice($scripts, $bridge)];
    foreach ($modes as $mode => $phases) {
      $this->drupalGet('form-test/machine-name');
      $session = $this->getSession();
      $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
      // Contrib needs jQuery, so the first phase always loads it.
      [$beforeContrib, $beforeAttach, $afterAttach] = array_map('json_encode', $phases);
      $session->executeScript($this->widgetHelpers() . <<<JS
        (async () => {
          await load($beforeContrib);
          const container = createWidget('stop');
          const source = container.querySelector('#stop-source');
          const target = container.querySelector('#stop-target');
          const seen = [];
          jQuery(source).on('input', (event) => {
            seen.push([event.isTrigger ? 'synthetic' : 'native', target.value]);
            event.stopImmediatePropagation();
          });
          await load($beforeAttach);
          attachWidget(container);
          await load($afterAttach);
          source.value = 'Native Stop';
          source.dispatchEvent(new Event('input'));
          jQuery(source).val('Synthetic Stop').trigger('input');
          window.contribFirstResult = [seen, target.value];
        })();
        JS);
      $this->assertJsCondition('Array.isArray(window.contribFirstResult)');
      $this->assertSame([
        [['native', ''], ['synthetic', '']],
        '',
      ], $session->evaluateScript('window.contribFirstResult'), "Loading mode: $mode.");
    }
  }

  /**
   * Tests Ajax and machine-name change bindings on one element.
   *
   * Real and synthetic change events run both bindings in registration order,
   * whether core/jquery loads before or after the bindings exist.
   */
  public function testAjaxAndMachineNameRegistrationOrder(): void {
    $scripts = $this->jqueryScripts();
    foreach (['early' => [$scripts, []], 'late' => [[], $scripts]] as $mode => $phases) {
      foreach (['ajax first' => TRUE, 'machine name first' => FALSE] as $order => $ajaxFirst) {
        $this->drupalGet('form-test/machine-name', ['query' => ['ajax' => 1]]);
        $session = $this->getSession();
        $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
        [$beforeBindings, $afterBindings] = array_map('json_encode', $phases);
        $first = json_encode($ajaxFirst);
        $session->executeScript($this->widgetHelpers() . <<<JS
          (async () => {
            await load($beforeBindings);
            const container = createWidget('order');
            const source = container.querySelector('#order-source');
            const target = container.querySelector('#order-target');
            const seen = [];
            let ajax;
            const bindAjax = () => {
              ajax = Drupal.ajax({ element: source, event: 'change', url: location.href });
              ajax.eventResponse = () => {
                seen.push(target.value);
              };
            };
            if ($first) {
              bindAjax();
              attachWidget(container);
            }
            else {
              attachWidget(container);
              bindAjax();
            }
            await load($afterBindings);
            source.value = 'Native Order';
            source.dispatchEvent(new Event('change', { bubbles: true }));
            source.value = 'Legacy Order';
            jQuery(source).trigger('change');
            ajax.removeEventBindings();
            window.registrationOrderResult = seen;
          })();
          JS);
        $this->assertJsCondition('Array.isArray(window.registrationOrderResult)');
        $this->assertSame(
          $ajaxFirst ? ['', 'native_order'] : ['native_order', 'legacy_order'],
          $session->evaluateScript('window.registrationOrderResult'),
          "Loading mode: $mode, $order.",
        );
      }
    }
  }

  /**
   * Tests that a slot claimed by the late adapter keeps the pinned order.
   *
   * Contrib handlers bound later share jQuery's dispatch with core's slot. As
   * with the pinned jQuery binding, that dispatch runs before a native
   * listener added after the slot.
   */
  public function testReleasedSlotKeepsNativeOrder(): void {
    $this->drupalGet('form-test/machine-name');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $scripts = json_encode($this->jqueryScripts());
    $session->executeScript($this->widgetHelpers() . <<<JS
      (async () => {
        await load($scripts);
        const source = document.getElementById('edit-machine-name-1-label');
        const target = document.getElementById('edit-machine-name-1');
        const order = [];
        source.addEventListener('input', () => order.push(['other', target.value]));
        jQuery(source).on('input', () => order.push(['contrib', target.value]));
        source.value = 'Released Slot';
        source.dispatchEvent(new Event('input'));
        jQuery(source).val('Synthetic Slot').trigger('input');
        window.releasedSlotResult = order;
      })();
      JS);
    $this->assertJsCondition('Array.isArray(window.releasedSlotResult)');
    $this->assertSame([
      ['contrib', 'released_slot'],
      ['other', 'released_slot'],
      ['contrib', 'synthetic_slot'],
    ], $session->evaluateScript('window.releasedSlotResult'));
  }

  /**
   * Tests that core's slot exists only while another jQuery handler does.
   *
   * With no other handler, a real event makes no jQuery dispatch. A later
   * handler still runs after core, also after the slot was released.
   */
  public function testSlotFollowsLegacyHandlers(): void {
    $this->drupalGet('form-test/machine-name', ['query' => ['legacy-machine-name' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const source = document.getElementById('edit-machine-name-1-label');
        const target = document.getElementById('edit-machine-name-1');
        const dispatch = jQuery.event.dispatch;
        let calls = 0;
        jQuery.event.dispatch = function (...args) {
          calls += 1;
          return dispatch.apply(this, args);
        };
        const input = (value) => {
          calls = 0;
          source.value = value;
          source.dispatchEvent(new Event('input'));
          return [target.value, calls];
        };
        const seen = [];
        try {
          const before = input('Before Contrib');
          jQuery(source).on('input.first', (event) => {
            seen.push([event.isTrigger ? 'synthetic' : 'native', target.value]);
          });
          input('With Contrib');
          jQuery(source).off('.first');
          const released = input('Released');
          jQuery(source).on('input.second', (event) => {
            seen.push([event.isTrigger ? 'synthetic' : 'native', target.value]);
          });
          jQuery(source).val('Synthetic Again').trigger('input');
          jQuery(source).off('.second');
          return [before, released, seen, input('Released Again')];
        }
        finally {
          jQuery.event.dispatch = dispatch;
        }
      })()
      JS);
    $this->assertSame([
      ['before_contrib', 0],
      ['released', 0],
      [['native', 'with_contrib'], ['synthetic', 'synthetic_again']],
      ['released_again', 0],
    ], $actual);
  }

  /**
   * Tests that dispatching one Event object twice runs core each time.
   */
  public function testRepeatedDispatchRunsCoreEachTime(): void {
    $this->drupalGet('form-test/machine-name', ['query' => ['legacy-machine-name' => 1]]);
    $session = $this->getSession();
    $session->executeScript($this->widgetHelpers() . <<<'JS'
      (() => {
        const container = createWidget('again');
        const source = container.querySelector('#again-source');
        const target = container.querySelector('#again-target');
        // A handler bound first makes core deliver real events from its slot.
        jQuery(source).on('input.test', () => {});
        attachWidget(container);
        const event = new Event('input');
        source.value = 'First Value';
        source.dispatchEvent(event);
        const first = target.value;
        source.value = 'Second Value';
        source.dispatchEvent(event);
        jQuery(source).off('.test');
        window.repeatedDispatchResult = [first, target.value];
      })();
      JS);
    $this->assertSame(['first_value', 'second_value'], $session->evaluateScript('window.repeatedDispatchResult'));
  }

  /**
   * Tests an instance connected before the form adapter loads.
   */
  public function testForeignInstanceConnectedBeforeAdapter(): void {
    $this->drupalGet('form-test/machine-name');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $scripts = $this->jqueryScripts();
    $split = 1 + (int) array_key_first(array_filter($scripts, fn ($path) => str_ends_with($path, 'jquery.drupal-bc.js')));
    $vendor = json_encode([$scripts[0]]);
    $early = json_encode(array_slice($scripts, 0, $split));
    $late = json_encode(array_slice($scripts, $split));
    $session->executeScript($this->widgetHelpers() . <<<JS
      (async () => {
        await load($vendor);
        const foreign = window.jQuery.noConflict(true);
        await load($early);
        const disconnect = Drupal.eventBindings.connectLegacy(foreign);
        await load($late);
        const source = document.getElementById('edit-machine-name-1-label');
        const target = document.getElementById('edit-machine-name-1');
        foreign(source).val('Foreign Early').trigger('input.machineName');
        const synthetic = target.value;
        const payloads = [];
        const element = document.getElementById('edit-machine-name-2');
        foreign(document.body).on('formFragmentLinkClickOrHashChange.test', (event, collection) => {
          payloads.push([collection instanceof foreign, collection?.[0] === element]);
        });
        Drupal.formEvents.publish(document.body, 'formFragmentLinkClickOrHashChange', element);
        foreign(document.body).off('.test');
        disconnect();
        foreign(source).val('After Disconnect').trigger('input.machineName');
        window.earlyForeignResult = [synthetic, payloads, target.value];
      })();
      JS);
    $this->assertJsCondition('Array.isArray(window.earlyForeignResult)');
    $this->assertSame([
      'foreign_early',
      [[TRUE, TRUE]],
      'foreign_early',
    ], $session->evaluateScript('window.earlyForeignResult'));
  }

  /**
   * Tests a native listener added before the bridge loads late.
   *
   * The pinned binding created jQuery's listener when core bound, so a
   * contrib jQuery handler bound later still ran before a native listener
   * added in between.
   */
  public function testLateReplayKeepsCorePosition(): void {
    $this->drupalGet('form-test/machine-name');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $scripts = json_encode($this->jqueryScripts());
    $session->executeScript($this->widgetHelpers() . <<<JS
      (async () => {
        const { source, target } = attachWidget(createWidget('replay'));
        const order = [];
        source.addEventListener('input', () => order.push(['native', target.value]));
        await load($scripts);
        jQuery(source).on('input', () => order.push(['contrib', target.value]));
        source.value = 'Replay Order';
        source.dispatchEvent(new Event('input'));
        window.lateReplayResult = order;
      })();
      JS);
    $this->assertJsCondition('Array.isArray(window.lateReplayResult)');
    $this->assertSame([
      ['contrib', 'replay_order'],
      ['native', 'replay_order'],
    ], $session->evaluateScript('window.lateReplayResult'));
  }

  /**
   * Tests a .one() handler that removes itself during a trigger.
   *
   * Core's slot is released once the trigger ends, so later native input
   * makes no jQuery calls.
   */
  public function testOneHandlerReleasesSlot(): void {
    $this->drupalGet('form-test/machine-name', ['query' => ['legacy-machine-name' => 1]]);
    $session = $this->getSession();
    $session->executeScript($this->jqueryCallCounter());
    $actual = $session->evaluateScript(<<<'JS'
      (() => {
        const source = document.getElementById('edit-machine-name-1-label');
        const target = document.getElementById('edit-machine-name-1');
        let once = 0;
        jQuery(source).one('input', () => {
          once += 1;
        });
        jQuery(source).val('One Value').trigger('input');
        const synthetic = target.value;
        jqueryCalls.start();
        source.value = 'After One';
        source.dispatchEvent(new Event('input'));
        return [once, synthetic, target.value, jqueryCalls.stop()];
      })()
      JS);
    [$once, $synthetic, $value, $counts] = $actual;
    $this->assertSame([1, 'one_value', 'after_one'], [$once, $synthetic, $value]);
    $this->assertNoJqueryCalls($counts);
  }

  /**
   * Tests a contrib special event whose setup() forwards natively.
   *
   * jQuery adds no listener of its own then, so the contrib handler receives
   * each native event once.
   */
  public function testContribSpecialSetupDeliversOnce(): void {
    $this->drupalGet('form-test/machine-name', ['query' => ['legacy-machine-name' => 1]]);
    $session = $this->getSession();
    $session->executeScript($this->widgetHelpers() . <<<'JS'
      (() => {
        const previous = jQuery.event.special.input;
        function forward(event) {
          return jQuery.event.dispatch.call(this, event);
        }
        jQuery.event.special.input = {
          setup() {
            this.addEventListener('input', forward);
            return true;
          },
          teardown() {
            this.removeEventListener('input', forward);
            return true;
          },
        };
        try {
          const { source, target } = attachWidget(createWidget('special'));
          const seen = [];
          jQuery(source).on('input.special', () => seen.push(target.value));
          source.value = 'Special Value';
          source.dispatchEvent(new Event('input'));
          jQuery(source).off('.special');
          window.specialSetupResult = seen;
        }
        finally {
          if (previous) {
            jQuery.event.special.input = previous;
          }
          else {
            delete jQuery.event.special.input;
          }
        }
      })();
      JS);
    $this->assertSame(['special_value'], $session->evaluateScript('window.specialSetupResult'));
  }

  /**
   * Tests that a mixed page with no legacy use makes no jQuery calls.
   *
   * Plan section 6.4 instruments jQuery dispatch, $.fn.init and selectors.
   * Attaching machine-name, typing into it and clicking a fragment link make
   * none of these calls, whether jQuery loads before or after core binds.
   */
  public function testMixedPageMakesNoJqueryCalls(): void {
    foreach (['early', 'late'] as $mode) {
      $this->drupalGet('form-test/machine-name', $mode === 'early' ? ['query' => ['legacy-machine-name' => 1]] : []);
      $session = $this->getSession();
      if ($mode === 'late') {
        $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
        $scripts = json_encode($this->jqueryScripts());
        $session->executeScript($this->widgetHelpers() . "load($scripts).then(() => { window.lateScriptsLoaded = true; });");
        $this->assertJsCondition('window.lateScriptsLoaded === true');
      }
      $session->executeScript($this->jqueryCallCounter() . $this->widgetHelpers() . <<<'JS'
        jqueryCalls.start();
        const container = createWidget('calls');
        attachWidget(container);
        const link = document.createElement('a');
        link.id = 'calls-link';
        link.href = '#calls-target';
        link.textContent = 'Fragment';
        container.prepend(link);
        window.fragmentPublications = 0;
        document.body.addEventListener('formFragmentLinkClickOrHashChange', () => {
          window.fragmentPublications += 1;
        });
        JS);
      $page = $session->getPage();
      $page->findById('calls-source')->setValue('Typed Name');
      $this->assertJsCondition("document.getElementById('calls-target').value === 'typed_name'");
      $page->findById('calls-link')->click();
      $this->assertJsCondition('window.fragmentPublications > 0');
      $this->assertNoJqueryCalls($session->evaluateScript('jqueryCalls.stop()'), "Loading mode: $mode.");
    }
  }

  /**
   * Tests that a nested synthetic trigger leaves native delivery to core.
   */
  public function testNestedTriggerReleasesBindings(): void {
    $this->drupalGet('form-test/machine-name', ['query' => ['legacy-machine-name' => 1]]);
    $this->getSession()->executeScript($this->jqueryCallCounter());
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const behavior = Drupal.behaviors.machineName;
        const source = document.getElementById('edit-machine-name-1-label');
        const target = document.getElementById('edit-machine-name-1');
        const outer = document.body.appendChild(document.createElement('div'));
        jQuery(outer).on('outer', () => {
          jQuery(source).val('Nested Value').trigger('input');
        });
        jQuery(outer).trigger('outer');
        jQuery(outer).off('outer');
        outer.remove();
        const nested = target.value;
        const show = behavior.showMachineNameNative;
        let calls = 0;
        behavior.showMachineNameNative = function (...args) {
          calls += 1;
          return show.apply(this, args);
        };
        jqueryCalls.start();
        let counts;
        try {
          source.value = 'After Nested';
          source.dispatchEvent(new Event('input'));
        }
        finally {
          behavior.showMachineNameNative = show;
          counts = jqueryCalls.stop();
        }
        return [nested, target.value, calls, counts];
      })()
      JS);
    [$nested, $value, $calls, $counts] = $actual;
    $this->assertSame(['nested_value', 'after_nested', 1], [$nested, $value, $calls]);
    $this->assertNoJqueryCalls($counts);
  }

  /**
   * Tests form compatibility for a jQuery instance connected explicitly.
   *
   * Real events follow the native listener order. The order of a handler
   * bound before the connection is unknown, so its synthetic trigger reaches
   * it before core's slot.
   */
  public function testConnectedForeignInstance(): void {
    $this->drupalGet('form-test/machine-name', ['query' => ['legacy-machine-name' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const vendor = Array.from(document.scripts).find(({ src }) => src.includes('/assets/vendor/jquery/jquery.min.js'));
      const script = document.createElement('script');
      script.src = vendor.src;
      script.onload = () => {
        window.foreignInstance = window.jQuery.noConflict(true);
      };
      document.head.append(script);
      JS);
    $this->assertJsCondition('typeof window.foreignInstance === "function"');
    $actual = $session->evaluateScript(<<<'JS'
      (() => {
        const foreign = window.foreignInstance;
        const source = document.getElementById('edit-machine-name-1-label');
        const target = document.getElementById('edit-machine-name-1');
        const seen = [];
        foreign(source).on('input.test', (event) => {
          seen.push([event.isTrigger ? 'synthetic' : 'native', target.value]);
          event.stopImmediatePropagation();
        });
        const disconnect = Drupal.eventBindings.connectLegacy(foreign);
        const sameDisconnect = Drupal.eventBindings.connectLegacy(foreign) === disconnect;
        source.value = 'Foreign Native';
        source.dispatchEvent(new Event('input'));
        foreign(source).val('Foreign Synthetic').trigger('input.machineName');
        const synthetic = target.value;
        foreign(source).val('Foreign Contrib').trigger('input');
        const contrib = target.value;

        const payloads = [];
        const element = document.getElementById('edit-machine-name-2');
        foreign(document.body).on('formFragmentLinkClickOrHashChange.test', (event, collection) => {
          payloads.push(['fragment', collection instanceof foreign, collection?.[0] === element]);
        });
        foreign(element).on('value:copy.test', (event, value) => payloads.push(['copy', value]));
        foreign(document).on('drupalTabbingConstrained.test', (event, context) => {
          payloads.push(['tabbing', context?.marker]);
        });
        Drupal.formEvents.publish(document.body, 'formFragmentLinkClickOrHashChange', element);
        Drupal.formEvents.publish(element, 'value:copy', 'copied');
        Drupal.eventBindings.publish(document, new CustomEvent('drupalTabbingConstrained', { detail: { marker: 'context' } }));
        foreign(document).add(document.body).add(element).add(source).off('.test');

        disconnect();
        foreign(source).val('After Disconnect').trigger('input.machineName');
        return [seen, synthetic, contrib, payloads, sameDisconnect, target.value];
      })()
      JS);
    $this->assertSame([
      [['native', 'foreign_native'], ['synthetic', 'foreign_synthetic']],
      'foreign_synthetic',
      'foreign_synthetic',
      [['fragment', TRUE, TRUE], ['copy', 'copied'], ['tabbing', 'context']],
      TRUE,
      'foreign_synthetic',
    ], $actual);
  }

  /**
   * Returns script phases for each way core/jquery can load.
   *
   * @return array<string, array<int, string[]>>
   *   Three script lists per mode, loaded before core binds, after core binds,
   *   and after a contrib handler binds.
   */
  private function loadingModes(): array {
    $all = $this->jqueryScripts();
    $index = array_key_first(array_filter($all, fn ($path) => str_ends_with($path, 'jquery.drupal-form-bc.js')));
    $this->assertNotNull($index);
    $others = $all;
    $adapter = array_splice($others, $index, 1);
    return [
      'early' => [$all, [], []],
      'late adapter' => [$others, [], $adapter],
      'late' => [[], $all, []],
    ];
  }

  /**
   * Returns JavaScript helpers that load scripts and build widgets.
   *
   * @return string
   *   Function declarations for load(), createWidget() and attachWidget().
   */
  private function widgetHelpers(): string {
    return <<<'JS'
      var load = async (paths) => {
        for (const path of paths) {
          await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = drupalSettings.path.baseUrl + path;
            script.onload = resolve;
            script.onerror = reject;
            document.head.append(script);
          });
        }
      };
      var createWidget = (id) => {
        const container = document.createElement('div');
        container.innerHTML = `<input id="${id}-source"><span id="${id}-suffix"></span><div class="js-form-item"><input id="${id}-target" maxlength="64"></div>`;
        document.querySelector('form').append(container);
        drupalSettings.machineName[`#${id}-source`] = {
          ...drupalSettings.machineName['#edit-machine-name-1-label'],
          target: `#${id}-target`,
          suffix: `#${id}-suffix`,
        };
        return container;
      };
      var attachWidget = (container) => {
        Drupal.behaviors.machineName.attach(container, drupalSettings);
        return {
          source: container.querySelector('input[id$="-source"]'),
          target: container.querySelector('input[id$="-target"]'),
        };
      };
      JS;
  }

  /**
   * Returns the core/jquery script paths in load order.
   *
   * @return string[]
   *   The script paths relative to the base URL.
   */
  private function jqueryScripts(): array {
    return array_column(
      \Drupal::service('library.discovery')->getLibraryByName('core', 'jquery')['js'],
      'data',
    );
  }

  /**
   * Tests that machine name field functions.
   *
   * Makes sure that the machine name field automatically provides a valid
   * machine name and that the manual editing mode functions.
   */
  public function testMachineName(): void {
    // Visit the machine name test page which contains two machine name fields.
    $this->drupalGet('form-test/machine-name');
    $this->assertSame('undefined', $this->getSession()->evaluateScript('typeof window.jQuery'));

    // Test values for conversion.
    $test_values = [
      [
        'input' => 'Test value !0-9@',
        'message' => 'A title that should be transliterated must be equal to the php generated machine name',
        'expected' => 'test_value_0_9',
      ],
      [
        'input' => 'Test value',
        'message' => 'A title that should not be transliterated must be equal to the php generated machine name',
        'expected' => 'test_value',
      ],
      [
        'input' => ' Test Value ',
        'message' => 'A title that should not be transliterated must be equal to the php generated machine name',
        'expected' => 'test_value',
      ],
      [
        'input' => ', Neglect?! ',
        'message' => 'A title that should not be transliterated must be equal to the php generated machine name',
        'expected' => 'neglect',
      ],
      [
        'input' => '0123456789!"$%&/()=?Test value?=)(/&%$"!9876543210',
        'message' => 'A title that should not be transliterated must be equal to the php generated machine name',
        'expected' => '0123456789_test_value_9876543210',
      ],
      [
        'input' => '_Test_Value_',
        'message' => 'A title that should not be transliterated must be equal to the php generated machine name',
        'expected' => 'test_value',
      ],
    ];

    // Get page and session.
    $page = $this->getSession()->getPage();

    // Get elements from the page.
    $title_1 = $page->findField('machine_name_1_label');
    $machine_name_1_field = $page->findField('machine_name_1');
    $machine_name_2_field = $page->findField('machine_name_2');
    $machine_name_1_wrapper = $machine_name_1_field->getParent();
    $machine_name_2_wrapper = $machine_name_2_field->getParent();
    $machine_name_1_value = $page->find('css', '#edit-machine-name-1-label-machine-name-suffix .machine-name-value');
    $machine_name_2_value = $page->find('css', '#edit-machine-name-2-label-machine-name-suffix .machine-name-value');
    $machine_name_3_value = $page->find('css', '#edit-machine-name-3-label-machine-name-suffix .machine-name-value');
    $button_1 = $page->find('css', '#edit-machine-name-1-label-machine-name-suffix button.link');

    // Assert all fields are initialized correctly.
    $this->assertNotEmpty($machine_name_1_value, 'Machine name field 1 must be initialized');
    $this->assertNotEmpty($machine_name_2_value, 'Machine name field 2 must be initialized');
    $this->assertNotEmpty($machine_name_3_value, 'Machine name field 3 must be initialized');

    // Assert that a machine name based on a default value is initialized.
    $this->assertJsCondition('document.querySelector("#edit-machine-name-3-label-machine-name-suffix .machine-name-value").innerHTML === "yet_another_machine_name"');

    // Test each value for conversion to a machine name.
    foreach ($test_values as $test_info) {
      // Set the value for the field, triggering the machine name update.
      $title_1->setValue($test_info['input']);

      // Wait the set timeout for fetching the machine name.
      $this->assertJsCondition('document.querySelector("#edit-machine-name-1-label-machine-name-suffix .machine-name-value").innerHTML === "' . $test_info['expected'] . '"');

      // Validate the generated machine name.
      $this->assertEquals($test_info['expected'], $machine_name_1_value->getHtml(), $test_info['message']);

      // Validate the second machine name field is empty.
      $this->assertEmpty($machine_name_2_value->getHtml(), 'The second machine name field should still be empty');
    }

    // Validate the machine name field is hidden.
    $this->assertFalse($machine_name_1_wrapper->isVisible(), 'The ID field must not be visible');
    $this->assertFalse($machine_name_2_wrapper->isVisible(), 'The ID field must not be visible');

    // Test switching back to the manual editing mode by clicking the edit link.
    $button_1->click();

    // Validate the visibility of the machine name field.
    $this->assertTrue($machine_name_1_wrapper->isVisible(), 'The ID field must now be visible');

    // Validate the visibility of the second machine name field.
    $this->assertFalse($machine_name_2_wrapper->isVisible(), 'The ID field must not be visible');

    // Validate if the element contains the correct value.
    $this->assertEquals(end($test_values)['expected'], $machine_name_1_field->getValue(), 'The ID field value must be equal to the php generated machine name');

    // Test that machine name generation still occurs after an HTML 5
    // validation failure.
    $this->drupalGet('form-test/machine-name');
    $this->assertSession()->buttonExists('Submit')->press();

    // Assert all fields are initialized correctly.
    $this->assertNotEmpty($machine_name_1_value, 'Machine name field 1 must be initialized');
    $this->assertNotEmpty($machine_name_2_value, 'Machine name field 2 must be initialized');
    $this->assertNotEmpty($machine_name_3_value, 'Machine name field 3 must be initialized');

    // Assert that a machine name based on a default value is initialized.
    $this->assertJsCondition('document.querySelector("#edit-machine-name-3-label-machine-name-suffix .machine-name-value").innerHTML === "yet_another_machine_name"');

    // Test each value for conversion to a machine name.
    foreach ($test_values as $test_info) {
      // Set the value for the field, triggering the machine name update.
      $title_1->setValue($test_info['input']);

      // Wait the set timeout for fetching the machine name.
      $this->assertJsCondition('document.querySelector("#edit-machine-name-1-label-machine-name-suffix .machine-name-value").innerHTML === "' . $test_info['expected'] . '"');

      // Validate the generated machine name.
      $this->assertEquals($test_info['expected'], $machine_name_1_value->getHtml(), $test_info['message']);

      // Validate the second machine name field is empty.
      $this->assertEmpty($machine_name_2_value->getHtml(), 'The second machine name field should still be empty');
    }

    // Validate the machine name field is hidden. Elements are visually hidden
    // using positioning, isVisible() will therefore not work.
    $this->assertTrue($machine_name_1_wrapper->hasClass('hidden'), 'The ID field must not be visible');
    $this->assertTrue($machine_name_2_wrapper->hasClass('hidden'), 'The ID field must not be visible');

    // Test switching back to the manual editing mode by clicking the edit link.
    $button_1->click();

    // Validate the visibility of the machine name field.
    $this->assertFalse($machine_name_1_wrapper->hasClass('hidden'), 'The ID field must now be visible');

    // Validate the visibility of the second machine name field.
    $this->assertTrue($machine_name_2_wrapper->hasClass('hidden'), 'The ID field must not be visible');

    // Validate if the element contains the correct value.
    $this->assertEquals($test_values[1]['expected'], $machine_name_1_field->getValue(), 'The ID field value must be equal to the php generated machine name');

    $assert = $this->assertSession();
    $this->drupalGet('/form-test/form-test-machine-name-validation');

    // Test errors after with no AJAX.
    $assert->buttonExists('Save')->press();
    $assert->pageTextContains('Machine-readable name field is required.');
    // Ensure only the first machine name field has an error.
    $this->assertTrue($assert->fieldExists('id')->hasClass('error'));
    $this->assertFalse($assert->fieldExists('id2')->hasClass('error'));

    // Test a successful submit after using AJAX.
    $assert->fieldExists('Name')->setValue('test 1');
    $machine_name_value = $page->find('css', '#edit-name-machine-name-suffix .machine-name-value');
    $this->assertNotEmpty($machine_name_value, 'Machine name field must be initialized');
    $this->assertJsCondition('document.querySelector("#edit-name-machine-name-suffix .machine-name-value").innerHTML === "test_1"');

    // Ensure that machine name generation still occurs after a non-HTML 5
    // validation failure.
    $this->assertEquals('test_1', $machine_name_value->getHtml(), $test_values[1]['message']);
    $machine_name_wrapper = $page->find('css', '#edit-id')->getParent();
    // Machine name field should not expand after failing validation.
    $this->assertTrue($machine_name_wrapper->hasClass('hidden'), 'The ID field must not be visible');
    $assert->selectExists('snack')->selectOption('apple');
    $assert->assertWaitOnAjaxRequest();
    $assert->buttonExists('Save')->press();
    $assert->pageTextContains('The form_test_machine_name_validation_form form has been submitted successfully.');

    // Test errors after using AJAX.
    $assert->fieldExists('Name')->setValue('duplicate');
    $this->assertJsCondition('document.forms[0].id.value === "duplicate"');
    $assert->fieldExists('id2')->setValue('duplicate2');
    $assert->selectExists('snack')->selectOption('potato');
    $assert->assertWaitOnAjaxRequest();
    $assert->buttonExists('Save')->press();
    $assert->pageTextContains('The machine-readable name is already in use. It must be unique.');
    // Ensure both machine name fields both have errors.
    $this->assertTrue($assert->fieldExists('id')->hasClass('error'));
    $this->assertTrue($assert->fieldExists('id2')->hasClass('error'));
  }

}
