<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core\Form;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the native and legacy views of an owned States event.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
final class NativeStatesPublicationTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['jquery_bc_test', 'states_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests publications from a real select and its dependent field.
   */
  public function testSelectPublication(): void {
    $this->drupalGet('jquery-bc-test/events');
    $session = $this->getSession();
    $field = $this->assertSession()->fieldExists('Dependent field');
    $this->assertFalse($field->isVisible());
    $session->executeScript(<<<'JS'
      const native = [];
      const legacy = [];
      for (const [type, field] of [['state:value', 'choice'], ['state:visible', 'dependent']]) {
        document.addEventListener(type, (event) => {
          if (event.target.name === field) {
            native.push(event);
          }
        });
        jQuery(document).on(type, (event) => {
          if (event.target.name === field) {
            legacy.push(event);
          }
        });
      }
      jQuery('select[name="choice"]').val('show').trigger('change');
      window.statePublications = [
        native.map((event) => [
          event.type, event.target.name, event.detail.value,
          event.detail.oldValue ?? null, event.detail.trigger ?? null,
        ]),
        legacy.map((event) => [
          event.type, event.target.name, event.value, event.oldValue ?? null,
          event.trigger ?? null, native.includes(event.originalEvent),
        ]),
      ];
      JS);

    $this->assertSession()->assertExpectedAjaxRequest(1);
    $this->assertTrue($field->isVisible());
    $this->assertSame([
      [
        ['state:visible', 'dependent', TRUE, NULL, TRUE],
        ['state:value', 'choice', 'show', 'hide', NULL],
      ],
      [
        ['state:visible', 'dependent', TRUE, NULL, TRUE, TRUE],
        ['state:value', 'choice', 'show', 'hide', NULL, TRUE],
      ],
    ], $session->evaluateScript('window.statePublications'));
  }

  /**
   * Tests initial visibility and the native library closure.
   */
  public function testNativeStatesPage(): void {
    $this->drupalGet('states-test');
    $session = $this->getSession();
    $assert = $this->assertSession();
    $native = $assert->elementExists('css', '#states-test-native');
    $this->assertFalse($native->isVisible());
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $this->assertSame([], $session->evaluateScript(<<<'JS'
      performance.getEntriesByType('resource')
        .map(({ name }) => new URL(name).pathname)
        .filter((path) => /\/assets\/vendor\/jquery\/|\/misc\/(jquery\.states\.bc|states\.jquery)\.js$/.test(path))
      JS));
    $session->executeScript(<<<'JS'
      document.querySelector('input[name="choice[1]"]').classList.add('colon:choice');
      const dependent = document.querySelector('#states-test-native').cloneNode(true);
      dependent.id = 'states-test-normalized';
      dependent.dataset.drupalStates = JSON.stringify({
        visible: {
          [String.raw`:is(:input.colon\:choice, input[data-unused=":eq(0)"]):not(.unused)`]: {
            checked: true,
          },
        },
      });
      document.querySelector('#states-test-form').appendChild(dependent);
      Drupal.attachBehaviors(dependent);
      JS);
    $normalized = $assert->elementExists('css', '#states-test-normalized');
    $this->assertFalse($normalized->isVisible());

    $assert->elementExists('css', 'input[name="choice[1]"]')->check();
    $this->assertTrue($native->isVisible());
    $this->assertTrue($normalized->isVisible());
  }

  /**
   * Tests native markup before and after explicit legacy attachment.
   */
  #[IgnoreDeprecations]
  public function testLateLegacySelectors(): void {
    $this->drupalGet('states-test');
    $session = $this->getSession();
    $assert = $this->assertSession();
    $native = $assert->elementExists('css', '#states-test-native');
    $this->assertFalse($native->isVisible());
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));

    $first = $assert->elementExists('css', 'input[name="choice[1]"]');
    $second = $assert->elementExists('css', 'input[name="choice[2]"]');
    $first->check();
    $this->assertTrue($native->isVisible());

    $session->executeScript(<<<'JS'
      Drupal.states.Trigger.states.early = function (elements) {
        window.statesEarlyCollection = elements instanceof window.jQuery;
        elements.trigger({ type: 'state:early', value: true });
      };
      JS);
    $this->clickLink('Load legacy States');
    $assert->assertExpectedAjaxRequest(1);
    $positional = $assert->elementExists('css', '#states-test-positional');
    $custom = $assert->elementExists('css', '#states-test-custom');
    $this->assertFalse($positional->isVisible());
    $this->assertFalse($custom->isVisible());
    $session->executeScript(<<<'JS'
      const dependent = document.createElement('div');
      dependent.className = 'js-form-wrapper';
      dependent.textContent = 'Pre-bridge callback result';
      dependent.dataset.drupalStates = JSON.stringify({
        visible: { 'input[name="choice[2]"]': { early: true } },
      });
      document.querySelector('#states-test-form').appendChild(dependent);
      Drupal.attachBehaviors(dependent);
      JS);
    $this->assertTrue($session->evaluateScript('window.statesEarlyCollection'));
    $first->uncheck();
    $this->assertFalse($native->isVisible());
    $second->check();
    $this->assertTrue($positional->isVisible());
    $this->assertTrue($custom->isVisible());
    $this->assertFalse($native->isVisible());

    $session->executeScript(<<<'JS'
      const fragment = document.querySelector('#states-test-legacy');
      Drupal.detachBehaviors(fragment, drupalSettings, 'unload');
      fragment.remove();
      window.statesDetachedFragment = fragment;
      Drupal.attachBehaviors(document);
      JS);
    $assert->elementNotExists('css', '#states-test-legacy');
    $first->check();
    $this->assertTrue($native->isVisible());

    $second->uncheck();
    $session->executeScript(<<<'JS'
      const fragment = window.statesDetachedFragment;
      document.querySelector('#states-test-content').appendChild(fragment);
      Drupal.attachBehaviors(fragment);
      JS);
    $this->assertFalse($positional->isVisible());
    $second->check();
    $this->assertTrue($positional->isVisible());
  }

  /**
   * Tests old callback chains, native registration and collection accessors.
   */
  #[IgnoreDeprecations]
  public function testTriggerCallbacks(): void {
    $this->drupalGet('jquery-bc-test/events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const { states } = Drupal;
      const jquery = window.jQuery;
      const received = [];
      const original = states.Trigger.states.value.change;
      states.Trigger.states.value.change = function (...args) {
        received.push(['first', this instanceof jquery, args.length]);
        return original.apply(this, args);
      };
      const first = states.Trigger.states.value.change;
      states.Trigger.states.value.change = function (...args) {
        received.push(['second', this instanceof jquery, args[0] instanceof jquery.Event]);
        return first.apply(this, args);
      };

      states.Trigger.states.nativeValue = () => {
        throw new Error('The native registration should take precedence.');
      };
      states.registerTrigger('nativeValue', {
        change(event) {
          received.push(['native', Array.isArray(this), event instanceof Event]);
          return this[0].value;
        },
      });
      states.Trigger.states.legacyInit = function (elements) {
        received.push(['initializer', this === window, elements instanceof jquery]);
        elements.trigger({ type: 'state:legacyInit', value: true });
      };

      const fixture = document.createElement('div');
      fixture.innerHTML = `
        <input id="legacy-value" value="hide">
        <input id="native-value" value="hide">
        <div id="legacy-output" class="js-form-wrapper">Legacy result</div>
        <div id="native-output" class="js-form-wrapper">Native result</div>
        <div id="initialized-output" class="js-form-wrapper">Initial result</div>
      `;
      const [legacy, native, legacyOutput, nativeOutput, initializedOutput] = fixture.children;
      legacyOutput.dataset.drupalStates = JSON.stringify({
        visible: { '#legacy-value': { value: 'show' } },
      });
      nativeOutput.dataset.drupalStates = JSON.stringify({
        visible: { '#native-value': { nativeValue: 'show' } },
      });
      initializedOutput.dataset.drupalStates = JSON.stringify({
        visible: { '#legacy-value': { legacyInit: true } },
      });
      document.body.append(fixture);
      Drupal.attachBehaviors(fixture);
      legacy.value = 'show';
      jquery(legacy).trigger('change');
      native.value = 'show';
      native.dispatchEvent(new Event('change', { bubbles: true }));

      const legacyCollection = jquery(legacyOutput);
      const dependent = new states.Dependent({
        element: legacyCollection,
        state: states.State.sanitize('visible'),
        constraints: {},
      });
      const trigger = new states.Trigger({ selector: '#native-value', state: 'unregistered' });
      const fromSelector = trigger.element[0] === native;
      trigger.element = legacyCollection;

      window.statesCallbacks = {
        received,
        accessors: [
          dependent.element === legacyCollection,
          dependent.elements[0] === legacyOutput,
          fromSelector,
          trigger.element === legacyCollection,
          trigger.elements[0] === legacyOutput,
        ],
      };
      JS);

    foreach (['legacy-output', 'native-output', 'initialized-output'] as $id) {
      $this->assertTrue($this->assertSession()->elementExists('css', "#$id")->isVisible(), "$id is visible.");
    }
    $this->assertSame([
      ['second', TRUE, FALSE],
      ['first', TRUE, 0],
      ['native', TRUE, FALSE],
      ['initializer', TRUE, TRUE],
      ['second', TRUE, TRUE],
      ['first', TRUE, 1],
      ['native', TRUE, TRUE],
    ], $session->evaluateScript('window.statesCallbacks.received'));
    $this->assertSame([TRUE, TRUE, TRUE, TRUE, TRUE], $session->evaluateScript('window.statesCallbacks.accessors'));
  }

  /**
   * Tests cancellation and namespaces on a built-in States listener.
   */
  public function testLegacyStateEventOrder(): void {
    $this->drupalGet('jquery-bc-test/events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const field = document.querySelector('input[name="dependent"]');
      const visible = () => field.closest('.js-form-item').style.display !== 'none';
      const jquery = window.jQuery;
      const trace = [];
      const send = (type, value) => jquery(field).trigger({ type, value, trigger: true });

      jquery(document).on('state:visible.statesFixture', '[name="dependent"]', (event) => {
        trace.push(visible());
        event.stopImmediatePropagation();
      });
      send('state:visible', true);
      trace.push(visible());

      jquery(document).off('.statesFixture');
      jquery(document).on('state:visible.statesObserver', () => trace.push(visible()));
      send('state:visible.statesObserver', true);
      trace.push(visible());
      send('state:visible', true);
      trace.push(visible());
      window.stateOrder = trace;
      JS);

    $this->assertSame([FALSE, FALSE, FALSE, FALSE, TRUE, TRUE], $session->evaluateScript('window.stateOrder'));
  }

}
