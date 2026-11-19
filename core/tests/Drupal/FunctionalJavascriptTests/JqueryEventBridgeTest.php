<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests fixture-only delivery between native and jQuery listeners.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
final class JqueryEventBridgeTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['jquery_bc_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that only the explicitly loaded jQuery library releases the alias.
   */
  public function testDollarAliasOwnership(): void {
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(DrupalSelenium2Driver::class, $driver);
    $webdriver = $driver->getWebDriverSession();
    if (($webdriver->capabilities()['browserName'] ?? NULL) !== 'chrome') {
      $this->markTestSkipped('The document-start fixture requires Chromium.');
    }
    $source = <<<'JS'
      window.$ = 'other library';
      window.foreignNoConflictCalls = 0;
      window.jQuery = { fx: {}, noConflict() { window.foreignNoConflictCalls += 1; } };
      JS;
    \Drupal::httpClient()->post($webdriver->getURL() . '/goog/cdp/execute', [
      'json' => [
        'cmd' => 'Page.addScriptToEvaluateOnNewDocument',
        'params' => ['source' => $source],
      ],
    ]);

    $session = $this->getSession();
    $this->drupalGet('jquery-bc-test/native-events');
    $this->assertSame(0, $session->evaluateScript('window.foreignNoConflictCalls'));
    $this->assertSame('other library', $session->evaluateScript('window.$'));

    $this->drupalGet('jquery-bc-test/bridge-events');
    $this->assertSame('4.0.0', $session->evaluateScript('window.jQuery.fn.jquery'));
    $this->assertSame('other library', $session->evaluateScript('window.$'));
  }

  /**
   * Tests that the fixture can use native listeners without legacy assets.
   */
  public function testNativeWithoutBridge(): void {
    $this->drupalGet('jquery-bc-test/native-events');
    $this->assertSame([
      'undefined', FALSE, FALSE, TRUE, ['compatibility:probe'],
    ], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const target = document.getElementById('event-bridge-fixture');
        const type = 'compatibility:probe';
        const trace = [];
        const listener = (event) => {
          trace.push(event.type);
          event.preventDefault();
        };
        target.addEventListener(type, listener);
        const unregister = Drupal.eventBindings.register({ target, type, listener });
        const delivered = target.dispatchEvent(new Event(type, { cancelable: true }));
        unregister();
        target.removeEventListener(type, listener);
        const removed = target.dispatchEvent(new Event(type, { cancelable: true }));
        return [
          typeof window.jQuery,
          Boolean(document.querySelector('script[src*="/core/misc/jquery.drupal-bc.js"]')),
          delivered,
          removed,
          trace,
        ];
      })()
      JS));
  }

  /**
   * Compares anchor click and prevented submit with native jQuery bindings.
   */
  public function testSyntheticActivation(): void {
    $this->loadAdapter();
    $url = $this->getSession()->getCurrentUrl();
    $results = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        function exercise(tag, type, mode) {
          const trace = [];
          const target = document.createElement(tag);
          if (tag === 'a') {
            target.href = '#unexpected-navigation';
          }
          else {
            // Record a default method call without leaving the fixture page.
            target.submit = () => trace.push('native submit method');
          }
          document.getElementById('event-bridge-fixture').append(target);
          const legacy = jQuery(target);
          legacy.on(type, (event, value) => {
            trace.push('before');
            if (value === 'blocked') {
              event.preventDefault();
              event.stopImmediatePropagation();
            }
          });
          const listener = (event, value) => {
            trace.push(['owned', event.target === target, event.currentTarget === target, value]);
            if (tag === 'form') {
              event.preventDefault();
            }
            return 'handled';
          };
          target.addEventListener(type, () => trace.push('DOM listener'));

          let disconnect;
          let unregister;
          const install = () => window.observeTestLegacyEvents(jQuery, Drupal.eventBindings);
          if (mode === 'legacy') {
            legacy.on(`${type}.compat`, listener);
          }
          else {
            if (mode === 'early') {
              disconnect = install();
            }
            target.addEventListener(type, listener);
            unregister = Drupal.eventBindings.register({ target, type, namespace: 'compat', listener });
            if (mode === 'late') {
              disconnect = install();
            }
          }
          legacy.on(type, () => { trace.push('after'); });
          if (mode === 'bound-before-observer') {
            disconnect = install();
          }

          const triggered = jQuery.Event(type);
          legacy.trigger(triggered, ['trigger']);
          const result = legacy.triggerHandler(`${type}.compat`, ['handler']);
          const blocked = jQuery.Event(type);
          legacy.trigger(blocked, ['blocked']);
          disconnect?.();
          unregister?.();
          target.remove();

          return [triggered.result, triggered.isDefaultPrevented(), result, blocked.isDefaultPrevented(), trace];
        }

        return ['legacy', 'early', 'late', 'bound-before-observer'].map((mode) => [
          exercise('a', 'click', mode),
          exercise('form', 'submit', mode),
        ]);
      })()
      JS);

    $trace = [
      'before',
      ['owned', TRUE, TRUE, 'trigger'],
      'after',
      ['owned', TRUE, TRUE, 'handler'],
      'before',
    ];
    $expected = [
      ['handled', FALSE, 'handled', TRUE, $trace],
      ['handled', TRUE, 'handled', TRUE, $trace],
    ];
    $this->assertSame($expected, $results[0], 'Pinned jQuery behavior with the owned handler registered in jQuery.');
    $this->assertSame($results[0], $results[1], 'Observer attached before the owned listener.');
    $this->assertSame($results[0], $results[2], 'Observer attached after the owned listener.');
    // A listener bound before the observer takes the earlier jQuery slot.
    // Production registration must run before dependent scripts to avoid it.
    [$trace[1], $trace[2]] = [$trace[2], $trace[1]];
    $this->assertSame([
      ['handled', FALSE, 'handled', TRUE, $trace],
      ['handled', TRUE, 'handled', TRUE, $trace],
    ], $results[3], 'Characterizes an already-bound jQuery listener.');
    $this->assertSame($url, $this->getSession()->getCurrentUrl());
  }

  /**
   * Tests mixed native and synthetic delivery, propagation, and cleanup.
   */
  public function testNamedEvent(): void {
    $this->loadAdapter();
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const type = 'compatibility:probe';
        const name = `${type}.compat`;
        const parent = document.getElementById('event-bridge-fixture');
        const target = document.createElement('button');
        parent.append(target);
        const trace = [];
        const legacy = jQuery(target);
        const native = (value) =>
          target.dispatchEvent(new CustomEvent(type, { bubbles: true, cancelable: true, detail: value }));
        const direct = (event, value, extra) => {
          trace.push(['owned', value, extra ?? null, event.defaultPrevented]);
          if (value === 'cancel') {
            event.preventDefault();
          }
          if (value === 'stop') {
            event.stopPropagation();
          }
          if (value === 'immediate') {
            event.stopImmediatePropagation();
          }
          if (value === 'outer') {
            legacy.trigger(name, ['inner']);
            native('nested DOM');
          }
          return value === 'false' ? false : 'handled';
        };
        const delegated = function (event, value) {
          const root = event.delegateTarget ?? event.currentTarget;
          trace.push(['delegate', value, this === target && event.target === target && root === parent, event.defaultPrevented]);
        };
        const onTarget = (event) => direct(event, event.detail);
        const onParent = (event) => {
          if (event.target.matches('button')) {
            delegated.call(event.target, event, event.detail);
          }
        };
        target.addEventListener(type, onTarget);
        parent.addEventListener(type, onParent);
        parent.addEventListener(type, () => trace.push('DOM bubble'));
        const disconnect = window.observeTestLegacyEvents(jQuery, Drupal.eventBindings);
        const removeDirect = Drupal.eventBindings.register({
          target, type, namespace: 'compat', listener: direct,
        });
        const removeDelegate = Drupal.eventBindings.register({
          target: parent, type, namespace: 'compat', selector: 'button', listener: delegated,
        });

        // With the bridge present, ordinary DOM delivery uses only the native binding.
        const nativeResult = [native('mixed'), native('false'), native('cancel')];
        legacy.on(`${type}.other`, () => { trace.push('other namespace'); });
        legacy.on(name, (event, value) => { trace.push(['later legacy', value]); });
        const handler = legacy.triggerHandler(name, ['handler', 7]);
        legacy.trigger(name, ['outer']);
        const cancelled = jQuery.Event(name);
        legacy.trigger(cancelled, ['false']);
        const prevented = jQuery.Event(name);
        legacy.trigger(prevented, ['cancel']);
        const stopped = jQuery.Event(name);
        legacy.trigger(stopped, ['stop']);
        legacy.trigger(name, ['immediate']);

        // A removed binding must no longer intercept legacy work.
        removeDelegate();
        parent.removeEventListener(type, onParent);
        legacy.trigger(name, ['unregistered delegate']);
        target.remove();
        legacy.trigger(name, ['detached']);
        disconnect();
        legacy.trigger(name, ['disconnected']);
        native('DOM after disconnect');
        removeDirect();
        target.removeEventListener(type, onTarget);

        return [
          nativeResult, handler,
          [cancelled.result, cancelled.isDefaultPrevented(), cancelled.isPropagationStopped()],
          [prevented.result, prevented.isDefaultPrevented(), prevented.isPropagationStopped()],
          [stopped.result, stopped.isDefaultPrevented(), stopped.isPropagationStopped()],
          trace,
        ];
      })()
      JS);

    $this->assertSame([
      [TRUE, TRUE, FALSE],
      'handled',
      [FALSE, TRUE, TRUE],
      ['handled', TRUE, FALSE],
      ['handled', FALSE, TRUE],
      [
        ['owned', 'mixed', NULL, FALSE],
        ['delegate', 'mixed', TRUE, FALSE],
        'DOM bubble',
        ['owned', 'false', NULL, FALSE],
        ['delegate', 'false', TRUE, FALSE],
        'DOM bubble',
        ['owned', 'cancel', NULL, FALSE],
        ['delegate', 'cancel', TRUE, TRUE],
        'DOM bubble',
        ['owned', 'handler', 7, FALSE],
        ['later legacy', 'handler'],
        ['owned', 'outer', NULL, FALSE],
        ['owned', 'inner', NULL, FALSE],
        ['later legacy', 'inner'],
        ['delegate', 'inner', TRUE, FALSE],
        ['owned', 'nested DOM', NULL, FALSE],
        'other namespace',
        ['later legacy', NULL],
        ['delegate', 'nested DOM', TRUE, FALSE],
        'DOM bubble',
        ['later legacy', 'outer'],
        ['delegate', 'outer', TRUE, FALSE],
        ['owned', 'false', NULL, FALSE],
        ['later legacy', 'false'],
        ['owned', 'cancel', NULL, FALSE],
        ['later legacy', 'cancel'],
        ['delegate', 'cancel', TRUE, TRUE],
        ['owned', 'stop', NULL, FALSE],
        ['later legacy', 'stop'],
        ['owned', 'immediate', NULL, FALSE],
        ['owned', 'unregistered delegate', NULL, FALSE],
        ['later legacy', 'unregistered delegate'],
        ['owned', 'detached', NULL, FALSE],
        ['later legacy', 'detached'],
        ['later legacy', 'disconnected'],
        ['owned', 'DOM after disconnect', NULL, FALSE],
        'other namespace',
        ['later legacy', NULL],
      ],
    ], $result);
  }

  /**
   * Records the pinned States and tabledrag jQuery payloads.
   */
  public function testPinnedPayloads(): void {
    $this->drupalGet('jquery-bc-test/bridge-events');
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const fixture = document.getElementById('event-bridge-fixture');
        const field = fixture.appendChild(document.createElement('input'));
        const table = fixture.appendChild(document.createElement('table'));
        const trace = [];
        fixture.addEventListener('state:visible', () => trace.push('DOM state'));
        fixture.addEventListener('columnschange', () => trace.push('DOM columns'));
        jQuery(document).on('state:visible', (event, ...args) => {
          trace.push([event.type, event.target === field, event.value, event.trigger, args]);
        });
        jQuery(table).on('columnschange', (event, ...args) => {
          trace.push([event.type, event.target === table, args]);
        });

        jQuery(field).trigger({ type: 'state:visible', value: false, trigger: true });
        jQuery(table).trigger('columnschange', false);
        return trace;
      })()
      JS);

    $this->assertSame([
      ['state:visible', TRUE, FALSE, TRUE, []],
      ['columnschange', TRUE, [FALSE]],
    ], $result);
  }

  /**
   * Tests explicit native payload projection through an existing jQuery hook.
   */
  public function testNativePayloadProjection(): void {
    $this->loadAdapter();
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const fixture = document.getElementById('event-bridge-fixture');
        const field = fixture.appendChild(document.createElement('input'));
        const table = fixture.appendChild(document.createElement('table'));
        const visible = new CustomEvent('state:visible', {
          bubbles: true, detail: { value: false, trigger: true },
        });
        const columns = new CustomEvent('columnschange', {
          bubbles: true, cancelable: true, detail: { displayWeight: false },
        });
        const payloads = new WeakMap([
          [visible, { properties: visible.detail }],
          [columns, { args: [columns.detail.displayWeight] }],
        ]);
        const trace = [];
        const lifecycle = [];
        const data = {};
        const root = jQuery(fixture);
        const specials = jQuery.event.special;
        const columnHook = {
          setup() {
            lifecycle.push('setup');
            return false;
          },
          handle(event, ...args) {
            trace.push(['columns hook', args]);
            return event.handleObj.handler.call(this, event, ...args);
          },
          teardown() {
            lifecycle.push('teardown');
            return false;
          },
        };
        const existingHandle = columnHook.handle;
        specials.columnschange = columnHook;
        const before = {
          dispatch: jQuery.event.dispatch,
          change: specials.change,
          ordinary: specials['fixture:ordinary'],
        };

        fixture.addEventListener('state:visible', (event) => {
          trace.push(['DOM state', event === visible, event.detail.value, typeof event.value]);
        });
        fixture.addEventListener('columnschange', (event) => {
          trace.push(['DOM columns', event === columns, event.detail.displayWeight]);
        });
        jQuery(document).on('state:visible.fixture', function (event, ...args) {
          trace.push(['jQuery state', event.originalEvent === visible, this === document &&
            event.currentTarget === document && event.target === field, event.value,
            event.oldValue, event.trigger, args]);
        });
        root.on('columnschange.fixture', 'table', data, function (event, ...args) {
          const context = this === table && event.currentTarget === table &&
            event.target === table && event.delegateTarget === fixture &&
            event.data === data && event.handleObj.namespace === 'fixture';
          trace.push(['jQuery columns', event.originalEvent === columns, context, args]);
          return args[0] === false ? false : 'columns handled';
        });
        const disconnectState = window.observeTestNativePayloads(jQuery, 'state:visible', payloads);
        const disconnectColumns = window.observeTestNativePayloads(jQuery, 'columnschange', payloads);

        const dispatched = [field.dispatchEvent(visible), table.dispatchEvent(columns)];
        const projected = trace.splice(0);

        // Identical detail alone does not opt in. A synthetic trigger keeps its own args.
        table.dispatchEvent(new CustomEvent('columnschange', { bubbles: true, detail: columns.detail }));
        const synthetic = jQuery.Event('columnschange.fixture');
        jQuery(table).trigger(synthetic, [true]);
        const ordinary = new CustomEvent('fixture:ordinary', { detail: visible.detail });
        payloads.set(ordinary, { properties: visible.detail, args: [false] });
        for (const type of [ordinary.type, 'change']) {
          field.addEventListener(type, (event) => trace.push(['DOM ordinary', event.type]));
          jQuery(field).on(`${type}.fixture`, (event, ...args) => {
            trace.push(['jQuery ordinary', event.type, Boolean(event.originalEvent), typeof event.value, args]);
          });
        }
        field.dispatchEvent(ordinary);
        field.dispatchEvent(new Event('change', { bubbles: true }));
        jQuery(field).triggerHandler('change.fixture', ['synthetic']);
        const control = trace.splice(0);

        disconnectState();
        disconnectColumns();
        field.dispatchEvent(visible);
        table.dispatchEvent(columns);
        const after = trace.splice(0);
        root.off('.fixture');
        jQuery(document).off('.fixture');
        jQuery(field).off('.fixture');
        return {
          dispatched, projected, control, after, lifecycle,
          cancelled: columns.defaultPrevented,
          syntheticResult: synthetic.result,
          restored: specials.columnschange === columnHook && columnHook.handle === existingHandle &&
            !Object.hasOwn(specials, 'state:visible'),
          isolated: jQuery.event.dispatch === before.dispatch && specials.change === before.change &&
            specials[ordinary.type] === before.ordinary,
        };
      })()
      JS);

    $this->assertSame([TRUE, FALSE], $result['dispatched']);
    $this->assertTrue($result['cancelled']);
    $this->assertSame([
      ['DOM state', TRUE, FALSE, 'undefined'],
      ['jQuery state', TRUE, TRUE, FALSE, NULL, TRUE, []],
      ['DOM columns', TRUE, FALSE],
      ['columns hook', [FALSE]],
      ['jQuery columns', TRUE, TRUE, [FALSE]],
    ], $result['projected']);
    $this->assertSame('columns handled', $result['syntheticResult']);
    $this->assertSame([
      ['DOM columns', FALSE, FALSE],
      ['columns hook', []],
      ['jQuery columns', FALSE, TRUE, []],
      ['columns hook', [TRUE]],
      ['jQuery columns', FALSE, TRUE, [TRUE]],
      ['DOM ordinary', 'fixture:ordinary'],
      ['jQuery ordinary', 'fixture:ordinary', TRUE, 'undefined', []],
      ['DOM ordinary', 'change'],
      ['jQuery ordinary', 'change', TRUE, 'undefined', []],
      ['jQuery ordinary', 'change', FALSE, 'undefined', ['synthetic']],
    ], $result['control']);
    $this->assertSame([
      ['DOM state', TRUE, FALSE, 'undefined'],
      ['jQuery state', TRUE, TRUE, NULL, NULL, NULL, []],
      ['DOM columns', TRUE, FALSE],
      ['columns hook', []],
      ['jQuery columns', TRUE, TRUE, []],
    ], $result['after']);
    $this->assertSame(['setup', 'teardown'], $result['lifecycle']);
    $this->assertTrue($result['restored']);
    $this->assertTrue($result['isolated']);
  }

  /**
   * Records excluded leveraged click, focus and blur dispatch shapes.
   */
  public function testLeveragedNativeEvents(): void {
    $this->loadAdapter();
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const parent = document.getElementById('event-bridge-fixture');
        const run = (observe) => {
          const target = document.createElement('input');
          target.type = 'checkbox';
          parent.append(target);
          const trace = [];
          const record = (source, event) => {
            trace.push([source, event.type, target.checked, document.activeElement === target]);
          };
          const listener = (event) => record(event instanceof Event ? 'DOM' : 'projected', event);
          const legacy = jQuery(target);
          legacy.on('click.compat focus.compat blur.compat', (event) => {
            record(event.originalEvent ? 'jQuery native' : 'jQuery synthetic', event);
          });
          const disconnect = observe ? window.observeTestLegacyEvents(jQuery, Drupal.eventBindings) : null;
          const remove = ['click', 'focus', 'blur'].map((type) => {
            target.addEventListener(type, listener);
            return Drupal.eventBindings.register({ target, type, namespace: 'compat', listener });
          });
          for (const type of ['click', 'focus', 'blur']) {
            legacy.trigger(`${type}.compat`);
          }
          for (const type of ['click', 'focus', 'blur']) {
            legacy.triggerHandler(`${type}.compat`);
          }
          disconnect?.();
          remove.forEach((unregister) => unregister());
          target.remove();
          return trace;
        };
        return [run(false), run(true)];
      })()
      JS);

    $this->assertSame([
      ['jQuery synthetic', 'click', TRUE, FALSE],
      ['DOM', 'click', TRUE, FALSE],
      ['jQuery synthetic', 'focus', TRUE, TRUE],
      ['DOM', 'focus', TRUE, TRUE],
      ['jQuery synthetic', 'blur', TRUE, FALSE],
      ['DOM', 'blur', TRUE, FALSE],
      ['jQuery synthetic', 'click', TRUE, FALSE],
      ['jQuery synthetic', 'focus', TRUE, FALSE],
      ['jQuery synthetic', 'blur', TRUE, FALSE],
    ], $result[0]);
    $this->assertSame($result[0], $result[1]);
  }

  /**
   * Exposes the fixture module's observers to WebDriver scripts.
   */
  private function loadAdapter(): void {
    $this->drupalGet('jquery-bc-test/bridge-events');
    $this->getSession()->executeScript(<<<'JS'
      const script = document.querySelector('script[src*="/core/misc/jquery.drupal-bc.js"]');
      import(script.src).then(({ observeLegacyEvents, observeNativePayloads }) => {
        window.observeTestLegacyEvents = observeLegacyEvents;
        window.observeTestNativePayloads = observeNativePayloads;
      });
      JS);
    $this->assertJsCondition('typeof window.observeTestLegacyEvents === "function"');
  }

}
