<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests delivery between native and jQuery listeners in the event fixture.
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
   * Tests that classic dependents can use the bridge in either load order.
   */
  public function testClassicScriptRegistration(): void {
    $session = $this->getSession();
    foreach (['bridge-events' => FALSE, 'bridge-events-early' => TRUE] as $path => $early) {
      $this->drupalGet("jquery-bc-test/$path");
      $this->assertSame(['ready', 1], $session->evaluateScript(<<<'JS'
        JSON.parse(document.getElementById('event-bridge-fixture').dataset.bridgeTrace)
        JS));
      $this->assertSame([TRUE, $early], $session->evaluateScript(<<<'JS'
        (() => {
          const scripts = Array.from(document.scripts);
          const library = (path) => scripts.findIndex(({ src }) => src && new URL(src).pathname.endsWith(path));
          const vendor = library('/core/assets/vendor/jquery/jquery.min.js');
          const alias = library('/core/misc/jquery.no-conflict.js');
          const adapter = library('/core/misc/jquery.drupal-bc.js');
          const drupal = library('/core/misc/drupal.js');
          return [vendor >= 0 && alias > vendor && adapter > alias, drupal >= 0 && adapter < drupal];
        })()
        JS));
    }
  }

  /**
   * Tests explicit opt-in and teardown with two independent instances.
   */
  public function testIndependentInstances(): void {
    $this->drupalGet('jquery-bc-test/bridge-events', ['query' => ['ajax' => 1]]);
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
    $trace = $session->evaluateScript(<<<'JS'
      (() => {
        const first = window.jQuery;
        const second = window.foreignInstance;
        const { connectLegacy: connect } = Drupal.eventBindings;
        connect()();
        connect({})();
        const target = document.getElementById('event-bridge-fixture');
        const type = 'compatibility:probe';
        const columns = new CustomEvent('columnschange', { detail: { displayWeight: true } });
        const publications = [];
        for (const [instance, label] of [[first, 'first'], [second, 'second']]) {
          instance(target).on(columns.type, (event, ...args) => {
            publications.push([label, event.originalEvent === columns, args]);
          });
        }
        const publish = () => {
          Drupal.eventBindings.publish(target, columns);
          return publications.splice(0);
        };
        let calls = 0;
        const listener = () => ++calls;
        target.addEventListener(type, listener);
        const unregister = Drupal.eventBindings.register({ target, type, listener });
        const firstBeforeOptIn = first(target).triggerHandler(type);
        const secondBeforeOptIn = second(target).triggerHandler(type);
        const ajaxTrace = [];
        const checkableTrace = [];
        const checkbox = target.appendChild(document.createElement('input'));
        checkbox.type = 'checkbox';
        const checkable = Drupal.ajax({ element: checkbox, event: 'click', url: location.href });
        checkable.eventResponse = (element, event) => checkableTrace.push([element.checked, event.isTrigger]);
        const ajax = Drupal.ajax({ element: target, event: 'RefreshView.view', url: location.href });
        ajax.eventResponse = (element, event) => ajaxTrace.push([element === target, event.namespace, event.isTrigger]);
        second(target).trigger('RefreshView.view');
        const before = publish();
        const stop = connect(second);
        const sameStop = connect(second) === stop;
        const secondAfterOptIn = second(target).triggerHandler(type);
        second(target).trigger('RefreshView.other');
        second(target).trigger('RefreshView.view');
        second(checkbox).trigger('click');
        const connected = publish();
        stop();
        const secondAfterStop = second(target).triggerHandler(type);
        second(target).trigger('RefreshView.view');
        const disconnected = publish();
        const resume = connect(second);
        stop();
        const secondAfterResume = second(target).triggerHandler(type);
        second(target).trigger('RefreshView.view');
        const reconnected = publish();
        resume();
        const firstAfterStop = first(target).triggerHandler(type);
        target.dispatchEvent(new Event(type));
        unregister();
        ajax.removeEventBindings();
        checkable.removeEventBindings();
        checkbox.remove();
        target.removeEventListener(type, listener);
        return [
          first !== second,
          sameStop,
          firstBeforeOptIn,
          secondBeforeOptIn ?? null,
          secondAfterOptIn,
          secondAfterStop ?? null,
          secondAfterResume,
          firstAfterStop,
          calls,
          [before, connected, disconnected, reconnected],
          ajaxTrace,
          checkableTrace,
        ];
      })()
      JS);
    $this->assertSame([
      TRUE, TRUE, 1, NULL, 2, NULL, 3, 4, 5,
      [
        [['first', TRUE, [TRUE]], ['second', TRUE, []]],
        [['first', TRUE, [TRUE]], ['second', TRUE, [TRUE]]],
        [['first', TRUE, [TRUE]], ['second', TRUE, []]],
        [['first', TRUE, [TRUE]], ['second', TRUE, [TRUE]]],
      ],
      [[TRUE, 'view', 3], [TRUE, 'view', 3]],
      [[TRUE, 3]],
    ], $trace);
  }

  /**
   * Tests that a synthetic select change creates no DOM event.
   */
  public function testSelectChange(): void {
    $this->drupalGet('jquery-bc-test/bridge-events');
    $trace = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const parent = document.getElementById('event-bridge-fixture');
        const select = parent.appendChild(document.createElement('select'));
        select.add(new Option('Show', 'show'));
        const trace = [];
        const listener = function (event, argument) {
          trace.push([event instanceof Event, this === select, event.target.value, argument ?? null]);
          return 'handled';
        };
        select.addEventListener('change', listener);
        const unregister = Drupal.eventBindings.register({
          target: select, type: 'change', namespace: 'compat', listener,
        });
        parent.addEventListener('change', () => trace.push('DOM bubble'));

        select.dispatchEvent(new Event('change', { bubbles: true }));
        const legacy = jQuery(select);
        legacy.trigger('change', ['synthetic']);
        legacy.trigger('change.other');
        const result = legacy.triggerHandler('change.compat', ['handler']);
        unregister();
        legacy.trigger('change');

        return [result, trace];
      })()
      JS);
    $this->assertSame([
      'handled',
      [
        [TRUE, TRUE, 'show', NULL],
        'DOM bubble',
        [FALSE, TRUE, 'show', 'synthetic'],
        [FALSE, TRUE, 'show', 'handler'],
      ],
    ], $trace);
  }

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
   * Tests native publication without legacy assets.
   */
  public function testNativeWithoutBridge(): void {
    $this->drupalGet('jquery-bc-test/native-events');
    $this->assertSame([
      'undefined', FALSE, FALSE, TRUE, [['state:visible', TRUE, TRUE, FALSE, 'undefined']],
    ], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const target = document.getElementById('event-bridge-fixture');
        const type = 'state:visible';
        const visible = new CustomEvent(type, { cancelable: true, detail: { value: false } });
        const trace = [];
        const listener = (event) => {
          trace.push([event.type, event === visible, event.target === target, event.detail.value, typeof event.value]);
          event.preventDefault();
        };
        target.addEventListener(type, listener);
        const unregister = Drupal.eventBindings.register({ target, type, listener });
        const delivered = Drupal.eventBindings.publish(target, visible);
        unregister();
        target.removeEventListener(type, listener);
        const removed = Drupal.eventBindings.publish(target, new CustomEvent(type, { detail: visible.detail }));
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
            trace.push(['owned', event.target === target, event.currentTarget === target, value, event.namespace, event.isTrigger, event.fixture]);
            if (tag === 'form') {
              event.preventDefault();
            }
            return 'handled';
          };
          target.addEventListener(type, () => trace.push('DOM listener'));

          let disconnect;
          let unregister;
          const install = () => Drupal.eventBindings.connectLegacy(jQuery);
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

          const triggered = jQuery.Event(type, { fixture: 42 });
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
      ['owned', TRUE, TRUE, 'trigger', '', 3, 42],
      'after',
      ['owned', TRUE, TRUE, 'handler', 'compat', 2, NULL],
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
        const disconnect = Drupal.eventBindings.connectLegacy(jQuery);
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
   * Tests owned publications through an existing jQuery hook.
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
        const custom = new CustomEvent('state:fixture', {
          bubbles: true, detail: { value: true, oldValue: false, trigger: undefined, extra: 'native only' },
        });
        const columns = new CustomEvent('columnschange', {
          bubbles: true, cancelable: true, detail: { displayWeight: false },
        });
        const { publish, connectLegacy: connect } = Drupal.eventBindings;
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
        jQuery(field).on('state:fixture.fixture', (event, ...args) => {
          trace.push(['jQuery custom state', event.originalEvent === custom,
            event.value, event.oldValue, 'trigger' in event, 'extra' in event, args]);
        });
        root.on('columnschange.fixture', 'table', data, function (event, ...args) {
          const context = this === table && event.currentTarget === table &&
            event.target === table && event.delegateTarget === fixture &&
            event.data === data && event.handleObj.namespace === 'fixture';
          trace.push(['jQuery columns', event.originalEvent === columns, context, args]);
          return args[0] === false ? false : 'columns handled';
        });
        // Connect after the listeners and the column special hook are in place.
        const disconnect = connect(jQuery);

        const dispatched = [publish(field, visible), publish(field, custom), publish(table, columns)];
        const projected = trace.splice(0);

        // Neither reusing an event nor copying its detail opts in.
        field.dispatchEvent(visible);
        table.dispatchEvent(new CustomEvent('columnschange', { bubbles: true, detail: columns.detail }));
        // A synthetic trigger keeps its own args.
        const synthetic = jQuery.Event('columnschange.fixture');
        jQuery(table).trigger(synthetic, [true]);
        const ordinary = new CustomEvent('fixture:ordinary', { detail: visible.detail });
        for (const type of [ordinary.type, 'change']) {
          field.addEventListener(type, (event) => trace.push(['DOM ordinary', event.type]));
          jQuery(field).on(`${type}.fixture`, (event, ...args) => {
            trace.push(['jQuery ordinary', event.type, Boolean(event.originalEvent), typeof event.value, args]);
          });
        }
        publish(field, ordinary);
        field.dispatchEvent(new Event('change', { bubbles: true }));
        jQuery(field).triggerHandler('change.fixture', ['synthetic']);
        const control = trace.splice(0);

        disconnect();
        publish(field, visible);
        publish(table, columns);
        const after = trace.splice(0);
        root.off('.fixture');
        jQuery(document).off('.fixture');
        jQuery(field).off('.fixture');
        return {
          dispatched, projected, control, after, lifecycle,
          cancelled: columns.defaultPrevented,
          syntheticResult: synthetic.result,
          restored: specials.columnschange === columnHook && columnHook.handle === existingHandle &&
            !Object.hasOwn(specials, 'state:visible') && !Object.hasOwn(specials, 'state:fixture'),
          isolated: jQuery.event.dispatch === before.dispatch && specials.change === before.change &&
            specials[ordinary.type] === before.ordinary,
        };
      })()
      JS);

    $this->assertSame([TRUE, TRUE, FALSE], $result['dispatched']);
    $this->assertTrue($result['cancelled']);
    $this->assertSame([
      ['DOM state', TRUE, FALSE, 'undefined'],
      ['jQuery state', TRUE, TRUE, FALSE, NULL, TRUE, []],
      ['jQuery custom state', TRUE, TRUE, FALSE, FALSE, FALSE, []],
      ['DOM columns', TRUE, FALSE],
      ['columns hook', [FALSE]],
      ['jQuery columns', TRUE, TRUE, [FALSE]],
    ], $result['projected']);
    $this->assertSame('columns handled', $result['syntheticResult']);
    $this->assertSame([
      ['DOM state', TRUE, FALSE, 'undefined'],
      ['jQuery state', TRUE, TRUE, NULL, NULL, NULL, []],
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
          const disconnect = observe ? Drupal.eventBindings.connectLegacy(jQuery) : null;
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
   * Tests publication despite observer errors and nested listener exceptions.
   */
  public function testPublicationErrors(): void {
    $this->loadAdapter();
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const { publish, observePublications, connectLegacy } = Drupal.eventBindings;
        const root = document.getElementById('event-bridge-fixture');
        const field = root.appendChild(document.createElement('input'));
        const outer = new CustomEvent('state:outer', {
          bubbles: true, cancelable: true, detail: { value: 'outer' },
        });
        const inner = new CustomEvent('state:inner', {
          bubbles: true, detail: { value: 'inner' },
        });
        const phases = [];
        const errors = [];
        const listenerError = new Error('listener');
        const report = Drupal.throwError;
        const reportListenerError = (event) => {
          if (event.error === listenerError) {
            event.preventDefault();
          }
        };
        Drupal.throwError = (error) => { errors.push(error.message); };
        window.addEventListener('error', reportListenerError);
        const stopPrepare = observePublications((event) => {
          if (event === outer) {
            throw new Error('prepare');
          }
        });
        const stopCleanup = observePublications((event) => {
          if (event === outer) {
            return () => { throw new Error('cleanup'); };
          }
        });
        const stopHealthy = observePublications((event) => {
          phases.push(['prepare', event.type]);
          return () => phases.push(['cleanup', event.type]);
        });
        const disconnect = connectLegacy(jQuery);
        const delivery = [];
        const legacy = jQuery(root).on('state:outer state:inner', (event) => {
          const original = event.type === outer.type ? outer : inner;
          delivery.push([event.type, event.value ?? null, event.originalEvent === original]);
          if (event.value === 'outer') {
            event.preventDefault();
          }
        });
        field.addEventListener(inner.type, () => {
          phases.push(['listener', inner.type]);
          throw listenerError;
        }, { once: true });
        field.addEventListener(outer.type, () => {
          publish(field, inner);
          field.dispatchEvent(inner);
        }, { once: true });

        try {
          const result = publish(field, outer);
          field.dispatchEvent(outer);
          return [result, errors, phases, delivery];
        } finally {
          disconnect();
          stopHealthy();
          stopCleanup();
          stopPrepare();
          legacy.off('state:outer state:inner');
          Drupal.throwError = report;
          window.removeEventListener('error', reportListenerError);
        }
      })()
      JS);
    $this->assertSame([
      FALSE,
      ['prepare', 'cleanup'],
      [
        ['prepare', 'state:outer'],
        ['prepare', 'state:inner'],
        ['listener', 'state:inner'],
        ['cleanup', 'state:inner'],
        ['cleanup', 'state:outer'],
      ],
      [
        ['state:inner', 'inner', TRUE],
        ['state:inner', NULL, TRUE],
        ['state:outer', 'outer', TRUE],
        ['state:outer', NULL, TRUE],
      ],
    ], $result);
  }

  /**
   * Tests an explicit adapter load on a page with an independent vendor.
   */
  public function testStandaloneOptIn(): void {
    $this->drupalGet('jquery-bc-test/native-events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      window.foreignAdapterTrace = null;
      (async () => {
        const load = (path) => new Promise((resolve, reject) => {
          const script = document.createElement('script');
          script.src = drupalSettings.path.baseUrl + path;
          script.onload = resolve;
          script.onerror = reject;
          document.head.append(script);
        });
        const target = document.getElementById('event-bridge-fixture');
        const type = 'compatibility:probe';
        const trace = [];
        const listener = (event) => {
          trace.push(event instanceof Event ? 'native' : 'owned');
          return 'handled';
        };
        target.addEventListener(type, listener);
        const unregister = Drupal.eventBindings.register({ target, type, listener });
        const beforeVendor = typeof window.jQuery;
        await load('core/assets/vendor/jquery/jquery.min.js');
        const instance = window.jQuery;
        target.dispatchEvent(new Event(type));
        const beforeOptIn = instance(target).triggerHandler(type);

        await load('core/misc/jquery.drupal-bc.js');
        const collection = instance(target);
        collection.on(type, () => { trace.push('dependent'); });
        const afterOptIn = collection.triggerHandler(type);
        unregister();
        collection.triggerHandler(type);

        window.foreignAdapterTrace = [
          beforeVendor,
          beforeOptIn ?? null,
          window.$ === instance,
          afterOptIn,
          trace,
        ];
      })().catch((error) => {
        window.foreignAdapterTrace = String(error);
      });
      JS);
    $this->assertJsCondition('window.foreignAdapterTrace !== null');
    $this->assertSame([
      'undefined', NULL, TRUE, 'handled', ['native', 'owned', 'dependent', 'dependent'],
    ], $session->evaluateScript('window.foreignAdapterTrace'));
  }

  /**
   * Resets the fixture so delivery tests can control observer setup.
   */
  private function loadAdapter(): void {
    $this->drupalGet('jquery-bc-test/bridge-events');
    $this->getSession()->executeScript(<<<'JS'
      Drupal.eventBindings.connectLegacy(window.jQuery)();
      JS);
  }

}
