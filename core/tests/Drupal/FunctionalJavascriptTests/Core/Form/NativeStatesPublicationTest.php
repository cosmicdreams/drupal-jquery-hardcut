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
  #[IgnoreDeprecations]
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
      dependent.removeAttribute('data-once');
      dependent.dataset.drupalStates = JSON.stringify({
        visible: {
          [String.raw`:is(input.colon\:choice, input[data-unused=":eq(0)"]):not(.unused)`]: {
            checked: true,
          },
        },
      });
      const form = document.querySelector('#states-test-form');
      form.appendChild(dependent);
      Drupal.attachBehaviors(form);
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
      const form = document.querySelector('#states-test-form');
      form.appendChild(dependent);
      Drupal.attachBehaviors(form);
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
      const trigger = new states.Trigger({ selector: '#native-value', state: 'empty' });
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
  #[IgnoreDeprecations]
  public function testLegacyStateEventOrder(): void {
    $this->drupalGet('jquery-bc-test/events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const field = document.querySelector('input[name="dependent"]');
      const visible = () => field.closest('.js-form-item').style.display !== 'none';
      const jquery = window.jQuery;
      const trace = [];
      const notices = [];
      Drupal.deprecationError = ({ message }) => notices.push(message);
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
      send('state:visible', true);
      window.stateOrder = trace;
      window.stateOrderNotices = notices;
      JS);

    $this->assertSame([FALSE, FALSE, FALSE, FALSE, TRUE, TRUE, TRUE], $session->evaluateScript('window.stateOrder'));
    $this->assertSame([
      'The jQuery synthetic trigger for state:visible is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the same native event name and its documented payload. See https://www.drupal.org/node/CHANGE_RECORD.',
    ], $session->evaluateScript('window.stateOrderNotices'));
  }

  /**
   * Tests overlapping triggers, direct Trigger calls, required labels and P4.
   */
  public function testOverlappingSelectorsAndDirectTrigger(): void {
    $this->drupalGet('states-test');
    $session = $this->getSession();
    $result = $session->evaluateScript(<<<'JS'
      (() => {
        const root = document.createElement('form');
        root.id = 'states-overlap-fixture';
        root.innerHTML = `
          <div class="js-form-item">
            <input type="checkbox" class="grp-cb" name="grp_cb_1" id="grp-cb-1">
            <input type="checkbox" class="grp-cb" name="grp_cb_2" id="grp-cb-2">
          </div>
          <div id="grp-cb-dep" class="js-form-wrapper" data-drupal-states='{"visible":{".grp-cb":{"checked":true}}}'>Group dep</div>
          <div id="narrow-cb-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#grp-cb-1":{"checked":true}}}'>Narrow dep</div>

          <div class="js-form-item">
            <input type="radio" name="grp_rad" value="a" id="grp-rad-a">
            <input type="radio" name="grp_rad" value="b" id="grp-rad-b">
          </div>
          <div id="grp-rad-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"input[name=\\"grp_rad\\"]":{"value":"b"}}}'>Radio group dep</div>
          <div id="narrow-rad-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#grp-rad-a":{"value":"a"}}}'>Radio narrow dep</div>

          <div class="js-form-wrapper" id="req-wrapper">
            <label for="static-req" class="form-required" id="static-req-label">Static required</label>
            <input type="text" id="static-req" required>
            <label for="dyn-req" id="dyn-req-label">Dynamic required</label>
            <input type="text" id="dyn-req" data-drupal-states='{"required":{"#grp-cb-2":{"checked":true}}}'>
          </div>

          <input type="checkbox" id="nest-a">
          <input type="text" id="nest-b" value="no">
          <input type="checkbox" id="nest-c">
          <div id="nested-or-dep" class="js-form-wrapper" data-drupal-states='{"visible":[{"#nest-a":{"checked":true},"#nest-b":{"value":"yes"}},"or",{"#nest-a":{"checked":true},"#nest-c":{"checked":true}}]}'>Nested OR</div>
          <div id="nested-xor-dep" class="js-form-wrapper" data-drupal-states='{"visible":[{"#nest-a":{"checked":true}},"xor",{"#nest-c":{"checked":true}}]}'>Nested XOR</div>

          <select id="multi-mixed" multiple>
            <option value="opt1">Opt 1</option>
            <option value="opt_disabled" disabled selected>Disabled</option>
            <optgroup label="Enabled group">
              <option value="opt2">Opt 2</option>
            </optgroup>
            <optgroup label="Disabled group" disabled>
              <option value="opt_group_disabled" selected>Disabled in group</option>
            </optgroup>
          </select>
          <div id="multi-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#multi-mixed":{"value":["opt2","opt1"]}}}'>Multi dep</div>

          <input type="text" id="direct-trigger-input" value="initial">
        `;
        document.body.append(root);
        Drupal.attachBehaviors(root);

        const visible = (id) => document.getElementById(id).style.display !== 'none';
        const cb1 = document.getElementById('grp-cb-1');
        const cb2 = document.getElementById('grp-cb-2');
        const radA = document.getElementById('grp-rad-a');
        const radB = document.getElementById('grp-rad-b');

        // 1a. Checkbox group vs narrower selector.
        cb1.checked = true;
        cb1.dispatchEvent(new Event('change', { bubbles: true }));
        const cbAfter1 = visible('grp-cb-dep');
        cb2.checked = true;
        cb2.dispatchEvent(new Event('change', { bubbles: true }));
        cb1.checked = false;
        cb1.dispatchEvent(new Event('change', { bubbles: true }));
        const cbStillVisible = visible('grp-cb-dep');

        // 1b. Radio group vs narrower radio member selector.
        const radInitial = visible('grp-rad-dep');
        radB.checked = true;
        radB.dispatchEvent(new Event('change', { bubbles: true }));
        const radAfterB = visible('grp-rad-dep');
        radA.dispatchEvent(new Event('change', { bubbles: true }));
        const radStillAfterB = visible('grp-rad-dep');

        // 3. Direct new Drupal.states.Trigger registration check & per-element deduplication.
        const directInput = document.getElementById('direct-trigger-input');
        let unregisteredFired = false;
        directInput.addEventListener('state:unregistered_custom', () => {
          unregisteredFired = true;
        });
        const unregistered = new Drupal.states.Trigger({
          selector: '#direct-trigger-input',
          state: 'unregistered_custom',
        });
        let valueEvents = 0;
        directInput.addEventListener('state:value', () => {
          valueEvents += 1;
        });
        new Drupal.states.Trigger({ selector: '#direct-trigger-input', state: 'value' });
        new Drupal.states.Trigger({ selector: '#direct-trigger-input', state: 'value' });
        while (Drupal.states.postponed.length) {
          Drupal.states.postponed.shift()();
        }
        directInput.value = 'changed';
        directInput.dispatchEvent(new Event('change', { bubbles: true }));

        // 4. state:required falsy branch preserves static form-required label.
        const staticLabel = document.getElementById('static-req-label');
        const dynLabel = document.getElementById('dyn-req-label');
        // cb2 is currently checked so dyn-req is required; now uncheck cb2.
        const dynReqWhileChecked = dynLabel.classList.contains('js-form-required') && dynLabel.classList.contains('form-required');
        cb2.checked = false;
        cb2.dispatchEvent(new Event('change', { bubbles: true }));
        const staticLabelPreserved = staticLabel.classList.contains('form-required');
        const dynLabelCleared = !dynLabel.classList.contains('js-form-required') && !dynLabel.classList.contains('form-required');

        // 16. Nested cached conditions (AND/OR/XOR).
        const nestA = document.getElementById('nest-a');
        const nestB = document.getElementById('nest-b');
        const nestC = document.getElementById('nest-c');
        const nestTrace = [visible('nested-or-dep'), visible('nested-xor-dep')];
        nestA.checked = true;
        nestA.dispatchEvent(new Event('change', { bubbles: true }));
        nestTrace.push(visible('nested-or-dep'), visible('nested-xor-dep'));
        nestC.checked = true;
        nestC.dispatchEvent(new Event('change', { bubbles: true }));
        nestTrace.push(visible('nested-or-dep'), visible('nested-xor-dep'));
        nestC.checked = false;
        nestC.dispatchEvent(new Event('change', { bubbles: true }));
        nestB.value = 'yes';
        nestB.dispatchEvent(new Event('change', { bubbles: true }));
        nestTrace.push(visible('nested-or-dep'), visible('nested-xor-dep'));

        // 16. Mixed multi-select values (disabled options, disabled optgroup).
        const multi = document.getElementById('multi-mixed');
        const multiInitial = visible('multi-dep');
        multi.querySelector('option[value="opt1"]').selected = true;
        multi.querySelector('option[value="opt2"]').selected = true;
        multi.dispatchEvent(new Event('change', { bubbles: true }));
        const multiSelected = visible('multi-dep');
        multi.querySelector('option[value="opt2"]').selected = false;
        multi.dispatchEvent(new Event('change', { bubbles: true }));
        const multiDeselected = visible('multi-dep');

        return {
          cbAfter1,
          cbStillVisible,
          radInitial,
          radAfterB,
          radStillAfterB,
          unregisteredHasElements: Boolean(unregistered.elements?.length),
          unregisteredFired,
          valueEvents,
          dynReqWhileChecked,
          staticLabelPreserved,
          dynLabelCleared,
          nestTrace,
          multiInitial,
          multiSelected,
          multiDeselected,
        };
      })()
      JS);

    $this->assertTrue($result['cbAfter1'], 'Checkbox group dependent visible when first checked.');
    $this->assertTrue($result['cbStillVisible'], 'Checkbox group dependent stays visible while second member remains checked.');
    $this->assertFalse($result['radInitial'], 'Radio group dependent starts hidden.');
    $this->assertTrue($result['radAfterB'], 'Radio group dependent visible when value b is selected.');
    $this->assertTrue($result['radStillAfterB'], 'Narrower radio member selector does not overwrite group value.');
    $this->assertFalse($result['unregisteredHasElements'], 'Direct Trigger skips unregistered states.');
    $this->assertFalse($result['unregisteredFired'], 'Unregistered Trigger fires no state events.');
    $this->assertSame(3, $result['valueEvents'], 'Duplicate direct Trigger calls emit one trigger set of initial (keyup+change) and one change event.');
    $this->assertTrue($result['dynReqWhileChecked'], 'Dynamic required adds js-form-required and form-required.');
    $this->assertTrue($result['staticLabelPreserved'], 'Falsy state:required preserves unrelated form-required label.');
    $this->assertTrue($result['dynLabelCleared'], 'Falsy state:required clears js-form-required and form-required.');
    $this->assertSame([FALSE, FALSE, FALSE, TRUE, TRUE, FALSE, TRUE, TRUE], $result['nestTrace']);
    $this->assertFalse($result['multiInitial']);
    $this->assertTrue($result['multiSelected']);
    $this->assertFalse($result['multiDeselected']);
  }

  /**
   * Tests bind-once dynamic insertion, core/once and detached subtree cleanup.
   */
  public function testDynamicInsertionAndOnceParity(): void {
    $this->drupalGet('states-test');
    $session = $this->getSession();
    $result = $session->evaluateScript(<<<'JS'
      (() => {
        const hasOnce = typeof window.once === 'function';
        const nativeEl = document.getElementById('states-test-native');
        const hasDataOnce = nativeEl.getAttribute('data-once')?.split(' ').includes('states') ?? false;

        const root = document.createElement('div');
        root.innerHTML = `
          <input type="checkbox" class="dyn-cb" id="dyn-cb-1">
          <div id="dyn-dep" class="js-form-wrapper" data-drupal-states='{"visible":{".dyn-cb":{"checked":true}}}'>Dyn dep</div>
        `;
        document.body.append(root);
        Drupal.attachBehaviors(root);

        const cb1 = document.getElementById('dyn-cb-1');
        const dep = document.getElementById('dyn-dep');
        let stateEvents = 0;
        dep.addEventListener('state:visible', () => {
          stateEvents += 1;
        });

        // Insert a second matching checkbox (checked) later and re-attach.
        // Pinned bind-once must NOT rebind or flip the already-connected dependent.
        const lateCb = document.createElement('input');
        lateCb.type = 'checkbox';
        lateCb.className = 'dyn-cb';
        lateCb.checked = true;
        root.append(lateCb);
        Drupal.attachBehaviors(root);
        const flippedAfterInsertion = dep.style.display !== 'none';
        const replayedAfterInsertion = stateEvents;

        // Passing a [data-drupal-states] element directly as context must NOT attach it.
        const directContext = document.createElement('div');
        directContext.className = 'js-form-wrapper';
        directContext.dataset.drupalStates = JSON.stringify({
          visible: { '#dyn-cb-1': { checked: true } },
        });
        root.append(directContext);
        Drupal.attachBehaviors(directContext);
        const contextAttached = directContext.style.display === 'none' || directContext.hasAttribute('data-once');

        // Cloning an already-attached element carrying data-once="states" must NOT attach the clone.
        const clone = dep.cloneNode(true);
        clone.id = 'dyn-dep-clone';
        clone.style.display = '';
        root.append(clone);
        Drupal.attachBehaviors(root);
        cb1.checked = true;
        cb1.dispatchEvent(new Event('change', { bubbles: true }));
        cb1.checked = false;
        cb1.dispatchEvent(new Event('change', { bubbles: true }));
        const cloneAttached = clone.style.display === 'none';

        // Unloaded/removed dead subtree without detach must not reconnect when attach runs.
        const deadHost = document.createElement('div');
        const deadDep = document.createElement('div');
        deadDep.className = 'js-form-wrapper';
        deadDep.dataset.drupalStates = JSON.stringify({
          visible: { '.states-test-choice:eq(0)': { checked: true } },
        });
        deadHost.append(deadDep);
        document.body.append(deadHost);
        Drupal.attachBehaviors(deadHost);
        let deadStateEvents = 0;
        deadDep.addEventListener('state:visible', () => {
          deadStateEvents += 1;
        });
        deadHost.remove();
        // Simulate legacy selector becoming available later.
        Drupal.states.compatibility.select = () => [document.querySelector('.states-test-choice')];
        Drupal.attachBehaviors(document);

        return {
          hasOnce,
          hasDataOnce,
          flippedAfterInsertion,
          replayedAfterInsertion,
          contextAttached,
          cloneAttached,
          deadStateEvents,
        };
      })()
      JS);

    $this->assertTrue($result['hasOnce'], 'core/once is loaded with core/drupal.states.');
    $this->assertTrue($result['hasDataOnce'], 'Attached States elements receive data-once="states".');
    $this->assertFalse($result['flippedAfterInsertion'], 'Later insertion does not rebind/flip an already-connected dependent.');
    $this->assertSame(0, $result['replayedAfterInsertion'], 'No replay event fires on later attach.');
    $this->assertFalse($result['contextAttached'], 'Passing a state-bearing element directly as context does not attach it.');
    $this->assertFalse($result['cloneAttached'], 'Cloned element carrying data-once="states" is not re-attached.');
    $this->assertSame(0, $result['deadStateEvents'], 'Disconnected dead subtree is not reconnected.');
  }

  /**
   * Tests selector grammar eligibility, :text parity and form pseudo deprecations.
   */
  #[IgnoreDeprecations]
  public function testSelectorGrammarAndDeprecations(): void {
    $this->drupalGet('states-test');
    $session = $this->getSession();
    $nativeResult = $session->evaluateScript(<<<'JS'
      (() => {
        const notices = [];
        const origWarn = Drupal.deprecationError;
        Drupal.deprecationError = ({ message }) => notices.push(message);

        const root = document.createElement('form');
        root.innerHTML = `
          <div class="has-parent"><span class="marker"></span><input type="checkbox" id="has-cb"></div>
          <div id="has-dep" class="js-form-wrapper" data-drupal-states='{"visible":{".has-parent:has(.marker) input":{"checked":true}}}'>Has dep</div>

          <input id="text-default" name="text_default">
          <input id="text-empty-type" type="" name="text_empty">
          <input id="text-explicit" type="text" name="text_explicit">
          <input id="text-upper" type="TEXT" name="text_upper">
          <input id="text-unknown" type="bogus" name="text_unknown">
          <input id="text-password" type="password" name="text_password">
          <input id="text-email" type="email" name="text_email">
          <div id="text-dep-unknown" class="js-form-wrapper" data-drupal-states='{"visible":{":text[name=\\"text_unknown\\"]":{"filled":true}}}'>Unknown type</div>
          <div id="text-dep-empty" class="js-form-wrapper" data-drupal-states='{"visible":{":text[name=\\"text_empty\\"]":{"filled":true}}}'>Empty type</div>
          <div id="text-dep-upper" class="js-form-wrapper" data-drupal-states='{"visible":{":text[name=\\"text_upper\\"]":{"filled":true}}}'>Upper type</div>
          <div id="text-dep-password" class="js-form-wrapper" data-drupal-states='{"visible":{":text[name=\\"text_password\\"]":{"filled":true}}}'>Password type</div>

          <input id="css-req" type="text" required placeholder="hint">
          <div id="css-pseudo-dep" class="js-form-wrapper" data-drupal-states='{"visible":{":root input:required:placeholder-shown":{"filled":true}}}'>CSS pseudo dep</div>

          <input id="dep-once-cb" type="checkbox" name="dep_once">
          <div id="dep-once-1" class="js-form-wrapper" data-drupal-states='{"visible":{":checkbox[name=\\"dep_once\\"]":{"checked":true}}}'>Once 1</div>
          <div id="dep-once-2" class="js-form-wrapper" data-drupal-states='{"visible":{":checkbox[name=\\"dep_once\\"]":{"checked":true}}}'>Once 2</div>
        `;
        document.body.append(root);
        Drupal.attachBehaviors(root);

        const visible = (id) => document.getElementById(id).style.display !== 'none';
        // :has() must NOT be admitted natively (stays unresolved/visible without legacy bridge).
        const hasHiddenNatively = !visible('has-dep');

        // :text matches unknown/empty/TEXT types, excludes password.
        const unknownInput = document.getElementById('text-unknown');
        const emptyInput = document.getElementById('text-empty-type');
        const upperInput = document.getElementById('text-upper');
        const passwordInput = document.getElementById('text-password');
        const textInitial = [
          visible('text-dep-unknown'),
          visible('text-dep-empty'),
          visible('text-dep-upper'),
          visible('text-dep-password'),
        ];
        for (const el of [unknownInput, emptyInput, upperInput, passwordInput]) {
          el.value = 'abc';
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const textAfter = [
          visible('text-dep-unknown'),
          visible('text-dep-empty'),
          visible('text-dep-upper'),
          visible('text-dep-password'),
        ];

        // Standard CSS pseudos (:root, :required, :placeholder-shown) stay native without deprecation.
        const cssReq = document.getElementById('css-req');
        const cssPseudoInitial = visible('css-pseudo-dep');
        cssReq.value = 'filled';
        cssReq.dispatchEvent(new Event('change', { bubbles: true }));
        const cssPseudoAfter = visible('css-pseudo-dep');

        Drupal.deprecationError = origWarn;
        return {
          hasHiddenNatively,
          textInitial,
          textAfter,
          cssPseudoInitial,
          cssPseudoAfter,
          notices,
        };
      })()
      JS);

    $this->assertFalse($nativeResult['hasHiddenNatively'], ':has() is not admitted natively without legacy bridge.');
    $this->assertSame([FALSE, FALSE, FALSE, TRUE], $nativeResult['textInitial'], ':text matches empty/upper/unknown input types and skips password.');
    $this->assertSame([TRUE, TRUE, TRUE, TRUE], $nativeResult['textAfter'], ':text triggers fire for empty/upper/unknown input types.');
    $this->assertFalse($nativeResult['cssPseudoInitial'], 'Standard CSS pseudos (:root, :required, :placeholder-shown) evaluate natively.');
    $this->assertTrue($nativeResult['cssPseudoAfter'], 'Standard CSS pseudos update natively.');

    // Deprecations emitted once per translated form pseudo selector, none for standard CSS pseudos.
    $expectedCheckboxNotice = 'The jQuery selector ":checkbox[name="dep_once"]" in States is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a CSS selector and the native States callback arguments. See https://www.drupal.org/node/CHANGE_RECORD.';
    $this->assertSame(1, count(array_keys($nativeResult['notices'], $expectedCheckboxNotice, TRUE)), 'Translated form pseudo deprecation is emitted once per selector.');
    foreach ($nativeResult['notices'] as $notice) {
      $this->assertStringNotContainsString(':placeholder-shown', $notice, 'Standard CSS pseudos emit no deprecation.');
      $this->assertStringContainsString('Use a CSS selector and the native States callback arguments.', $notice);
    }

    // Load legacy bridge and verify :has() now resolves through legacy jQuery.
    $this->clickLink('Load legacy States');
    $this->assertSession()->assertExpectedAjaxRequest(1);
    $this->assertFalse($this->assertSession()->elementExists('css', '#has-dep')->isVisible(), ':has() resolves after legacy States bridge loads.');
    $this->assertSession()->elementExists('css', '#has-cb')->check();
    $this->assertTrue($this->assertSession()->elementExists('css', '#has-dep')->isVisible(), ':has() reveals dependent when legacy bridge is active.');
  }

  /**
   * Tests late bridge listener order and zero jQuery calls from native #states.
   */
  #[IgnoreDeprecations]
  public function testLateBridgeOrderAndZeroJqueryCalls(): void {
    $this->drupalGet('states-test');
    $session = $this->getSession();
    // 1. Third-party jQuery present without bridge: native #states makes zero jQuery calls.
    $thirdPartyCalls = $session->evaluateScript(<<<'JS'
      (() => {
        let calls = 0;
        const previous = window.jQuery;
        window.jQuery = new Proxy(function () {
          calls += 1;
          throw new Error('Core States must not call third-party jQuery.');
        }, {
          get(target, prop) {
            calls += 1;
            throw new Error(`Core States must not read third-party jQuery.${String(prop)}.`);
          },
        });
        try {
          const cb = document.querySelector('input[name="choice[1]"]');
          const dep = document.getElementById('states-test-native');
          cb.checked = true;
          cb.dispatchEvent(new Event('change', { bubbles: true }));
          const shown = dep.style.display !== 'none';
          cb.checked = false;
          cb.dispatchEvent(new Event('change', { bubbles: true }));
          const hidden = dep.style.display === 'none';
          return [calls, shown, hidden];
        } finally {
          window.jQuery = previous;
        }
      })()
      JS);
    $this->assertSame([0, TRUE, TRUE], $thirdPartyCalls);

    // 14. Loading jquery.states.bc.js without jQuery must safely no-op.
    $guardResult = $session->evaluateScript(<<<'JS'
      (async () => {
        const errors = [];
        const onError = (event) => {
          errors.push(event.message);
          event.preventDefault();
        };
        window.addEventListener('error', onError);
        try {
          await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = Drupal.url('core/misc/jquery.states.bc.js');
            script.onload = resolve;
            script.onerror = reject;
            document.head.append(script);
          });
          return {
            errors,
            initialized: typeof Drupal.states.compatibility.enableLegacySelectors === 'function',
          };
        } finally {
          window.removeEventListener('error', onError);
        }
      })()
      JS);
    $this->assertSame(['errors' => [], 'initialized' => FALSE], $guardResult, 'States bridge no-ops cleanly when window.jQuery is absent.');

    // 2. Native document state:visible listener added BEFORE late jQuery load must stay AFTER States.
    $session->executeScript(<<<'JS'
      window.lateBridgeTrace = [];
      const target = document.getElementById('states-test-native');
      document.addEventListener('state:visible', (event) => {
        if (event.target === target) {
          window.lateBridgeTrace.push(['native-after-states', target.style.display !== 'none', event.detail.value]);
        }
      });
      // Also save a built-in Trigger callback before late bridge load (Item 9).
      window.savedCheckedChange = Drupal.states.Trigger.states.checked.change;
      window.savedValueChange = Drupal.states.Trigger.states.value.change;
      JS);

    $this->clickLink('Load legacy States');
    $this->assertSession()->assertExpectedAjaxRequest(1);

    $afterLateLoad = $session->evaluateScript(<<<'JS'
      (() => {
        const jquery = window.jQuery;
        let initCalls = 0;
        let triggerCalls = 0;
        const origInit = jquery.fn.init;
        const origTrigger = jquery.event.trigger;
        jquery.fn.init = function (...args) {
          initCalls += 1;
          return new origInit(...args);
        };
        jquery.fn.init.prototype = jquery.fn;
        jquery.event.trigger = function (...args) {
          triggerCalls += 1;
          return origTrigger.apply(this, args);
        };
        try {
          const cb = document.querySelector('input[name="choice[1]"]');
          cb.checked = true;
          cb.dispatchEvent(new Event('change', { bubbles: true }));
          cb.checked = false;
          cb.dispatchEvent(new Event('change', { bubbles: true }));
        } finally {
          jquery.fn.init = origInit;
          jquery.event.trigger = origTrigger;
        }
        return {
          trace: window.lateBridgeTrace,
          initCalls,
          triggerCalls,
        };
      })()
      JS);

    // Native document listener saw the UPDATED visibility both times (States ran BEFORE it).
    $this->assertSame([
      ['native-after-states', TRUE, TRUE],
      ['native-after-states', FALSE, FALSE],
    ], $afterLateLoad['trace']);
    $this->assertSame(0, $afterLateLoad['initCalls'], 'Native #states interaction makes zero jQuery init calls on mixed page with no legacy listeners.');
    $this->assertSame(0, $afterLateLoad['triggerCalls'], 'Native #states interaction makes zero jQuery trigger calls on mixed page with no legacy listeners.');
  }

  /**
   * Tests bridge callback parity, initializers, second jQuery, bubbling, checked change and .off().
   */
  #[IgnoreDeprecations]
  public function testBridgeEdgeCasesParity(): void {
    $this->drupalGet('states-test');
    $session = $this->getSession();
    // Save built-in callbacks BEFORE late bridge load.
    $session->executeScript(<<<'JS'
      window.preBridgeSavedChecked = Drupal.states.Trigger.states.checked.change;
      window.preBridgeSavedValue = Drupal.states.Trigger.states.value.change;
      window.preBridgeSavedCollapsed = Drupal.states.Trigger.states.collapsed.collapsed;
      JS);
    $this->clickLink('Load legacy States');
    $this->assertSession()->assertExpectedAjaxRequest(1);

    // Load a second independent jQuery instance.
    $session->executeScript(<<<'JS'
      const vendor = Array.from(document.scripts).find(({ src }) => src.includes('/assets/vendor/jquery/jquery.min.js'));
      const script = document.createElement('script');
      script.src = vendor.src;
      script.onload = () => {
        window.secondJquery = window.jQuery.noConflict(true);
      };
      document.head.append(script);
      JS);
    $this->assertJsCondition('typeof window.secondJquery === "function"');

    $result = $session->evaluateScript(<<<'JS'
      (() => {
        const jquery = window.jQuery;
        const second = window.secondJquery;
        const { states } = Drupal;

        const root = document.createElement('form');
        root.id = 'bridge-edge-fixture';
        root.innerHTML = `
          <div class="js-form-item"><input type="checkbox" id="edge-cb"></div>
          <div class="js-form-item"><input type="checkbox" id="edge-target-cb" data-drupal-states='{"checked":{"#edge-cb":{"checked":true}}}'></div>
          <div class="js-form-item"><input type="text" id="edge-text" value="a"></div>
          <details id="edge-details"></details>
          <div id="edge-cb-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#edge-cb":{"checked":true}}}'>CB dep</div>
          <div id="edge-init-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#edge-cb":{"onceInit":true}}}'>Init dep</div>
          <div id="edge-off-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#edge-cb":{"checked":true}}}'>Off dep</div>
        `;
        document.body.append(root);

        const edgeCb = document.getElementById('edge-cb');
        const edgeTargetCb = document.getElementById('edge-target-cb');
        const edgeText = document.getElementById('edge-text');
        const edgeDetails = document.getElementById('edge-details');

        // 9a. Pre-bridge saved built-in callbacks work when called with jQuery receiver/event after bridge loads.
        edgeCb.checked = true;
        const savedCheckedWithJquery = window.preBridgeSavedChecked.call(jquery(edgeCb));
        const savedValueWithJquery = window.preBridgeSavedValue.call(jquery([edgeText, edgeCb]));
        const savedCollapsedWithJquery = window.preBridgeSavedCollapsed.call(
          jquery(edgeDetails),
          jquery.Event('collapsed', { value: true }),
        );
        edgeCb.checked = false;

        // 9b. Native callers of built-in callbacks on mixed pages keep working (no this.toArray).
        const nativeValueCall = states.Trigger.states.value.change.call([edgeText], new Event('change'));
        const nativeCheckedCall = states.Trigger.states.checked.change.call([edgeCb], new Event('change'));

        // 10. Legacy function-trigger initializer runs once per element across detach/reattach.
        let initCalls = 0;
        states.Trigger.states.onceInit = function (elements) {
          initCalls += 1;
          elements.on('change.onceInit', () => {
            elements.trigger({ type: 'state:onceInit', value: elements[0].checked });
          });
        };

        Drupal.attachBehaviors(root);
        Drupal.detachBehaviors(root, drupalSettings, 'unload');
        Drupal.attachBehaviors(root);

        // 11. Second jQuery instance connected via connectLegacy delivers synthetic checkbox change to States.
        const stopSecond = Drupal.eventBindings.connectLegacy(second);
        edgeCb.checked = true;
        second(edgeCb).trigger('change');
        const visibleAfterSecondJquery = document.getElementById('edge-cb-dep').style.display !== 'none';
        stopSecond();

        // 12. Legacy payload edit on event.value by jQuery handler on dependee is seen by ancestor jQuery handlers (one event object).
        const bubbleTrace = [];
        let firstEventObj = null;
        jquery(edgeCb).on('state:checked.bubbleTest', (event) => {
          firstEventObj = event;
          bubbleTrace.push(['dependee', event.value]);
          event.value = 'mutated-by-dependee';
        });
        jquery(root).on('state:checked.bubbleTest', (event) => {
          if (event.target === edgeCb) {
            bubbleTrace.push(['form', event.value, event === firstEventObj]);
          }
        });
        jquery(document).on('state:checked.bubbleTest', (event) => {
          if (event.target === edgeCb) {
            bubbleTrace.push(['document', event.value, event === firstEventObj]);
          }
        });
        edgeCb.checked = false;
        edgeCb.dispatchEvent(new Event('change', { bubbles: true }));
        jquery(edgeCb).off('.bubbleTest');
        jquery(root).off('.bubbleTest');
        jquery(document).off('.bubbleTest');

        // 13. state:checked delivers jQuery synthetic change (isTrigger set) to jQuery listeners while native gets native Event.
        const checkedChangeTrace = [];
        const onNativeCheckedChange = (event) => {
          checkedChangeTrace.push(['native', event instanceof Event, !('isTrigger' in event)]);
        };
        edgeTargetCb.addEventListener('change', onNativeCheckedChange);
        jquery(edgeTargetCb).on('change.checkedTest', (event) => {
          checkedChangeTrace.push(['jquery', Boolean(event.isTrigger), edgeTargetCb.checked]);
        });
        edgeCb.checked = true;
        edgeCb.dispatchEvent(new Event('change', { bubbles: true }));
        edgeTargetCb.removeEventListener('change', onNativeCheckedChange);
        jquery(edgeTargetCb).off('.checkedTest');

        // 16. .off('state:visible') on mixed page disables States's handler, while .off('.unrelated') leaves it working.
        const offDep = document.getElementById('edge-off-dep');
        jquery(document).off('.unrelatedNamespace');
        edgeCb.checked = false;
        edgeCb.dispatchEvent(new Event('change', { bubbles: true }));
        const hiddenAfterUnrelatedOff = offDep.style.display === 'none';

        jquery(document).off('state:visible');
        edgeCb.checked = true;
        edgeCb.dispatchEvent(new Event('change', { bubbles: true }));
        const stillHiddenAfterOff = offDep.style.display === 'none';

        return {
          savedCheckedWithJquery,
          savedValueWithJquery,
          savedCollapsedWithJquery,
          nativeValueCall,
          nativeCheckedCall,
          initCalls,
          visibleAfterSecondJquery,
          bubbleTrace,
          checkedChangeTrace,
          hiddenAfterUnrelatedOff,
          stillHiddenAfterOff,
        };
      })()
      JS);

    $this->assertTrue($result['savedCheckedWithJquery']);
    $this->assertSame('on', $result['savedValueWithJquery']);
    $this->assertTrue($result['savedCollapsedWithJquery']);
    $this->assertSame('a', $result['nativeValueCall']);
    $this->assertFalse($result['nativeCheckedCall']);
    $this->assertSame(1, $result['initCalls'], 'Legacy function-trigger initializer ran only once across unload/reattach.');
    $this->assertTrue($result['visibleAfterSecondJquery'], 'Second jQuery instance delivered synthetic checkbox change to States.');
    $this->assertSame([
      ['dependee', FALSE],
      ['form', 'mutated-by-dependee', TRUE],
      ['document', 'mutated-by-dependee', TRUE],
    ], $result['bubbleTrace'], 'Payload edits on event.value propagate on the same jQuery event object.');
    $this->assertSame([
      ['native', TRUE, TRUE],
      ['jquery', TRUE, TRUE],
    ], $result['checkedChangeTrace'], 'state:checked delivers native Event to native listeners and isTrigger change to jQuery listeners.');
    $this->assertTrue($result['hiddenAfterUnrelatedOff'], 'Unrelated namespace .off() keeps States state:visible active.');
    $this->assertTrue($result['stillHiddenAfterOff'], '.off("state:visible") disables States built-in state:visible handler on mixed pages.');
  }

}
