<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core\Form;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WebDriver\Key;

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
          event.trigger ?? null, event.originalEvent === undefined, event.isTrigger,
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
        ['state:visible', 'dependent', TRUE, NULL, TRUE, TRUE, 3],
        ['state:value', 'choice', 'show', 'hide', NULL, TRUE, 3],
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

  /**
   * Tests Nightwatch textfield and number-spinner parity with zero deprecations.
   */
  public function testNativeNightwatchParityNoDeprecations(): void {
    $this->drupalGet('states-test/native-form');
    $session = $this->getSession();
    $assert = $this->assertSession();
    $page = $session->getPage();

    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $this->assertSame([], $session->evaluateScript('window.statesDeprecationLog'), 'Page-load deprecation log captured before attach is empty.');

    $textfield = $assert->elementExists('css', 'input[name="textfield"]');
    $this->assertFalse($textfield->isVisible());
    $page->findField('select')->setValue('1');
    $this->assertTrue($textfield->isVisible());
    $page->findField('select')->setValue('0');
    $this->assertFalse($textfield->isVisible());
    $page->findField('number')->setValue('1');
    $this->assertTrue($textfield->isVisible());

    $dependent = $assert->elementExists('css', '#edit-item-visible-when-number-trigger-filled-by-spinner');
    $this->assertFalse($dependent->isVisible());

    $session->executeScript(<<<'JS'
      const number = document.querySelector('input[name="number_trigger"]');
      number.addEventListener('keyup', (event) => event.stopImmediatePropagation(), { capture: true });
      number.addEventListener('change', (event) => {
        window.statesSpinnerChange = event.isTrusted;
      });
      JS);
    $session->getDriver()->getWebDriverSession()
      ->element('css selector', 'input[name="number_trigger"]')
      ->postValue(['text' => Key::UP_ARROW . Key::TAB]);
    $this->assertTrue($session->evaluateScript('window.statesSpinnerChange'));
    $this->assertTrue($dependent->isVisible());
    $this->assertSame([], $session->evaluateScript('window.statesDeprecationLog'), 'No deprecations fired during native States interactions.');
  }

  /**
   * Tests round-2 bridge ordering, namespaced triggers, checked, return false and foreign triggers.
   */
  #[IgnoreDeprecations]
  public function testRound2BridgeOrderingAndSemantics(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();
    $page = $session->getPage();

    // 1a. Early bridge load: delegated document state:visible override cancels
    // and edits e.value on a real #states transition.
    $this->drupalGet('jquery-bc-test/events');
    $dependent = $assert->fieldExists('Dependent field');
    $this->assertFalse($dependent->isVisible());

    $session->executeScript(<<<'JS'
      jQuery(document).on('state:visible.r2cancel', '[name="dependent"]', (event) => {
        event.stopImmediatePropagation();
      });
      JS);
    $page->findField('choice')->setValue('show');
    $assert->assertExpectedAjaxRequest(1);
    $this->assertFalse($dependent->isVisible(), 'Early bridge load: delegated stopImmediatePropagation cancels real state:visible transition.');

    $session->executeScript(<<<'JS'
      jQuery(document).off('.r2cancel');
      jQuery(document).on('state:visible.r2edit', '[name="dependent"]', (event) => {
        event.value = false;
      });
      JS);
    $page->findField('choice')->setValue('hide');
    $assert->assertExpectedAjaxRequest(2);
    $page->findField('choice')->setValue('show');
    $assert->assertExpectedAjaxRequest(3);
    $this->assertFalse($dependent->isVisible(), 'Early bridge load: delegated e.value edit overrides real state:visible transition.');

    $session->executeScript("jQuery(document).off('.r2edit');");
    $page->findField('choice')->setValue('hide');
    $assert->assertExpectedAjaxRequest(4);
    $page->findField('choice')->setValue('show');
    $assert->assertExpectedAjaxRequest(5);
    $this->assertTrue($dependent->isVisible(), 'Early bridge load: real state:visible resumes after removing delegated override.');

    // 1b. Late bridge load: native delivery preserved when no external handler
    // exists, and delegated document overrides cancel / edit e.value when added.
    $this->drupalGet('states-test');
    $nativeDep = $assert->elementExists('css', '#states-test-native');
    $firstChoice = $assert->elementExists('css', 'input[name="choice[1]"]');
    $this->assertFalse($nativeDep->isVisible());

    $session->executeScript(<<<'JS'
      window.r2LateNativeTrace = [];
      const target = document.getElementById('states-test-native');
      document.addEventListener('state:visible', (event) => {
        if (event.target === target) {
          window.r2LateNativeTrace.push(target.style.display !== 'none');
        }
      });
      JS);
    $this->clickLink('Load legacy States');
    $assert->assertExpectedAjaxRequest(1);

    $firstChoice->check();
    $this->assertTrue($nativeDep->isVisible());
    $this->assertSame([TRUE], $session->evaluateScript('window.r2LateNativeTrace'), 'Late bridge load keeps native delivery when no external document jQuery handler exists.');
    $firstChoice->uncheck();
    $this->assertFalse($nativeDep->isVisible());

    $session->executeScript(<<<'JS'
      jQuery(document).on('state:visible.r2lateCancel', '#states-test-native', (event) => {
        event.stopImmediatePropagation();
      });
      JS);
    $firstChoice->check();
    $this->assertFalse($nativeDep->isVisible(), 'Late bridge load: delegated stopImmediatePropagation cancels real state:visible transition.');

    $session->executeScript(<<<'JS'
      jQuery(document).off('.r2lateCancel');
      jQuery(document).on('state:visible.r2lateEdit', '#states-test-native', (event) => {
        event.value = false;
      });
      JS);
    $firstChoice->uncheck();
    $firstChoice->check();
    $this->assertFalse($nativeDep->isVisible(), 'Late bridge load: delegated e.value edit overrides real state:visible transition.');

    $session->executeScript("jQuery(document).off('.r2lateEdit');");
    $firstChoice->uncheck();
    $firstChoice->check();
    $this->assertTrue($nativeDep->isVisible(), 'Late bridge load: real state:visible resumes after removing delegated override.');

    // 2. Namespaced custom trigger (change.myModule) reacts to real user input.
    $session->executeScript(<<<'JS'
      Drupal.states.Trigger.states.nsCustom = {
        'change.myModule'() {
          return this.val() === 'go';
        },
      };
      const wrap = document.createElement('div');
      wrap.id = 'r2-ns-fixture';
      wrap.innerHTML = `
        <input id="r2-ns-input" name="r2_ns_input" type="text" value="wait">
        <div id="r2-ns-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r2-ns-input":{"nsCustom":true}}}'>NS dep</div>
      `;
      document.body.append(wrap);
      Drupal.attachBehaviors(wrap);
      JS);
    $nsDep = $assert->elementExists('css', '#r2-ns-dep');
    $this->assertFalse($nsDep->isVisible());
    $page->findField('r2_ns_input')->setValue('go');
    $this->assertTrue($nsDep->isVisible(), 'Namespaced legacy trigger change.myModule reacts to real user input.');
    $session->executeScript("jQuery('#r2-ns-input').off('.myModule');");
    $page->findField('r2_ns_input')->setValue('wait');
    $this->assertTrue($nsDep->isVisible(), '.off(".myModule") unbinds namespaced custom trigger.');

    // Load a second independent jQuery instance for items 3 and 5.
    $session->executeScript(<<<'JS'
      const vendor = Array.from(document.scripts).find(({ src }) => src.includes('/assets/vendor/jquery/jquery.min.js'));
      const script = document.createElement('script');
      script.src = vendor.src;
      script.onload = () => {
        window.r2SecondJquery = window.jQuery.noConflict(true);
      };
      document.head.append(script);
      JS);
    $this->assertJsCondition('typeof window.r2SecondJquery === "function"');

    // 3. state:checked on mixed pages matches pinned .trigger('change') on both
    // window.jQuery and a second connected jQuery instance.
    $session->executeScript(<<<'JS'
      const jquery = window.jQuery;
      const second = window.r2SecondJquery;
      window.r2StopSecond = Drupal.eventBindings.connectLegacy(second);

      const chkForm = document.createElement('form');
      chkForm.id = 'r2-chk-form';
      chkForm.innerHTML = `
        <input type="checkbox" id="r2-chk-src" name="r2_chk_src">
        <div class="js-form-item">
          <input type="checkbox" id="r2-chk-dst" name="r2_chk_dst" data-drupal-states='{"checked":{"#r2-chk-src":{"checked":true}}}'>
        </div>
        <div id="r2-chk-chain" class="js-form-wrapper" data-drupal-states='{"visible":{"#r2-chk-dst":{"checked":true}}}'>Chained</div>
      `;
      document.body.append(chkForm);
      Drupal.attachBehaviors(chkForm);

      const dst = document.getElementById('r2-chk-dst');
      const chain = document.getElementById('r2-chk-chain');
      window.r2CheckedTrace = { primary: [], second: [], native: [], chainEvents: 0 };
      chain.addEventListener('state:visible', () => {
        window.r2CheckedTrace.chainEvents += 1;
      });
      dst.addEventListener('change', (event) => {
        window.r2CheckedTrace.native.push([event instanceof Event, !('isTrigger' in event)]);
      });

      for (const [instance, key] of [[jquery, 'primary'], [second, 'second']]) {
        let checkboxEvent = null;
        instance(dst).on('change.r2chk', (event) => {
          checkboxEvent = event;
          event.r2Annotation = `note-${key}`;
          window.r2CheckedTrace[key].push([
            'checkbox',
            event.originalEvent === undefined,
            Boolean(event.isTrigger),
          ]);
        });
        instance(chkForm).on('change.r2chk', (event) => {
          if (event.target === dst) {
            window.r2CheckedTrace[key].push([
              'form',
              event === checkboxEvent,
              event.r2Annotation ?? null,
              event.originalEvent === undefined,
              Boolean(event.isTrigger),
            ]);
          }
        });
      }
      JS);

    $assert->elementExists('css', '#r2-chk-src')->check();
    $this->assertTrue($assert->elementExists('css', '#r2-chk-chain')->isVisible());
    $checkedTrace = $session->evaluateScript('window.r2CheckedTrace');
    $this->assertSame([
      ['checkbox', TRUE, TRUE],
      ['form', TRUE, 'note-primary', TRUE, TRUE],
    ], $checkedTrace['primary'], 'Primary jQuery sees one synthetic change event with no originalEvent and shared annotation across bubbling targets.');
    $this->assertSame([
      ['checkbox', TRUE, TRUE],
      ['form', TRUE, 'note-second', TRUE, TRUE],
    ], $checkedTrace['second'], 'Second connected jQuery sees one synthetic change event with no originalEvent and shared annotation across bubbling targets.');
    $this->assertSame([[TRUE, TRUE]], $checkedTrace['native'], 'Native listener still receives native Event without isTrigger.');
    $this->assertSame(1, $checkedTrace['chainEvents'], 'Chained dependent on state:checked target fires once without double-firing.');

    // 4. Shared state:* event: returning false sets isDefaultPrevented() and
    // isPropagationStopped() on the shared event for later handlers.
    $session->executeScript(<<<'JS'
      const jquery = window.jQuery;
      const retForm = document.createElement('form');
      retForm.id = 'r2-ret-form';
      retForm.innerHTML = `
        <input type="checkbox" id="r2-ret-src" name="r2_ret_src">
        <div id="r2-ret-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r2-ret-src":{"checked":true}}}'>Ret dep</div>
      `;
      document.body.append(retForm);
      Drupal.attachBehaviors(retForm);

      const retDep = document.getElementById('r2-ret-dep');
      window.r2RetResult = { later: null, docReached: false };
      jquery(retDep).on('state:visible.r2ret', (event) => {
        event.r2SharedTag = 'from-dep';
      });
      jquery(retForm).on('state:visible.r2ret', () => false);
      jquery(retForm).on('state:visible.r2ret', (event) => {
        window.r2RetResult.later = [
          event.r2SharedTag ?? null,
          event.isDefaultPrevented(),
          event.isPropagationStopped(),
          event.result,
        ];
      });
      jquery(document).on('state:visible.r2ret', (event) => {
        if (event.target === retDep) {
          window.r2RetResult.docReached = true;
        }
      });
      JS);

    $assert->elementExists('css', '#r2-ret-src')->check();
    $retResult = $session->evaluateScript('window.r2RetResult');
    $this->assertSame(['from-dep', TRUE, TRUE, FALSE], $retResult['later'], 'Returning false sets isDefaultPrevented() and isPropagationStopped() on the shared state:* event.');
    $this->assertFalse($retResult['docReached'], 'Returning false stops bubbling to document.');
    $session->executeScript("jQuery(document).off('.r2ret');");

    // 5. states.js listen() honors isForeignDefaultAction so click/focus/blur
    // custom triggers do not double-fire from a second connected jQuery, and
    // second-instance synthetic state:* triggers emit the same deprecation.
    $item5 = $session->evaluateScript(<<<'JS'
      (() => {
        const jquery = window.jQuery;
        const second = window.r2SecondJquery;
        let clickCalls = 0;
        let focusCalls = 0;
        Drupal.states.registerTrigger('r2Click', {
          click(event) {
            if (event) {
              clickCalls += 1;
            }
            return clickCalls;
          },
        });
        Drupal.states.registerTrigger('r2Focus', {
          focus(event) {
            if (event) {
              focusCalls += 1;
            }
            return focusCalls;
          },
        });
        const box = document.createElement('div');
        box.innerHTML = `
          <button type="button" id="r2-click-btn">Click trigger</button>
          <input type="text" id="r2-focus-in">
          <div id="r2-click-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r2-click-btn":{"r2Click":1}}}'>Click dep</div>
          <div id="r2-focus-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r2-focus-in":{"r2Focus":1}}}'>Focus dep</div>
        `;
        document.body.append(box);
        Drupal.attachBehaviors(box);

        second('#r2-click-btn').trigger('click');
        const clicksAfterSecond = clickCalls;
        jquery('#r2-click-btn').trigger('click');
        const clicksAfterPrimary = clickCalls;

        second('#r2-focus-in').trigger('focus');
        const focusAfterSecond = focusCalls;

        const notices = [];
        const origWarn = Drupal.deprecationError;
        Drupal.deprecationError = ({ message }) => notices.push(message);
        try {
          second('#r2-click-dep').trigger({ type: 'state:readonly', value: true, trigger: true });
        } finally {
          Drupal.deprecationError = origWarn;
        }
        window.r2StopSecond();
        return {
          clicksAfterSecond,
          clicksAfterPrimary,
          focusAfterSecond,
          notices,
        };
      })()
      JS);
    $this->assertSame(1, $item5['clicksAfterSecond'], 'Second connected jQuery click trigger fires States custom trigger once, not twice.');
    $this->assertSame(2, $item5['clicksAfterPrimary'], 'Primary jQuery click trigger fires States custom trigger once, not twice.');
    $this->assertSame(1, $item5['focusAfterSecond'], 'Second connected jQuery focus trigger fires States custom trigger once, not twice.');
    $this->assertSame([
      'The jQuery synthetic trigger for state:readonly is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the same native event name and its documented payload. See https://www.drupal.org/node/CHANGE_RECORD.',
    ], $item5['notices'], 'Second connected jQuery synthetic state:* trigger emits the same deprecation as the primary path.');
  }

  /**
   * Tests Ajax unload teardown, dependee retention, marker handoff and nits.
   */
  public function testTeardownRetentionMarkerHandoffAndNits(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    // 6. Ajax RemoveCommand targeting the dependent itself tears down its
    // bindings so surviving dependee changes do not reach the old dependent.
    $this->drupalGet('states-test');
    $session->executeScript(<<<'JS'
      window.r2RemovedDependent = document.getElementById('states-test-native');
      window.r2RemovedEvents = 0;
      window.r2RemovedDependent.addEventListener('state:visible', () => {
        window.r2RemovedEvents += 1;
      });
      JS);
    $this->clickLink('Remove native dependent');
    $assert->assertExpectedAjaxRequest(1);
    $assert->elementNotExists('css', '#states-test-native');
    $this->assertFalse($session->evaluateScript('window.r2RemovedDependent.hasAttribute("data-once")'), 'Unload with dependent itself as context removes data-once="states".');
    $assert->elementExists('css', 'input[name="choice[1]"]')->check();
    $this->assertSame(0, $session->evaluateScript('window.r2RemovedEvents'), 'Surviving dependee change does not reach Ajax-removed dependent.');

    // 7. Replacing a dependee via Ajax while its dependent stays does not
    // retain bindings to the detached dependee in ownedEventBindings.
    $this->drupalGet('states-test');
    $session->executeScript(<<<'JS'
      window.r2SnapshotBindings = (target) => {
        const list = [];
        const stop = Drupal.eventBindings.observe({
          add(binding) {
            list.push(binding);
          },
          remove() {},
        });
        stop();
        return {
          forTarget: list.filter((b) => b.target === target).length,
          disconnected: list.filter((b) => b.target instanceof Element && !b.target.isConnected).length,
        };
      };
      window.r2OldDependee = document.querySelector('input[name="choice[1]"]');
      window.r2BeforeReplace = window.r2SnapshotBindings(window.r2OldDependee);
      JS);
    $beforeReplace = $session->evaluateScript('window.r2BeforeReplace');
    $this->assertGreaterThan(0, $beforeReplace['forTarget'], 'Active dependee has registered ownedEventBindings before replace.');

    $this->clickLink('Replace first dependee');
    $assert->assertExpectedAjaxRequest(1);
    $afterReplace = $session->evaluateScript('window.r2SnapshotBindings(window.r2OldDependee)');
    $this->assertSame(0, $afterReplace['forTarget'], 'Ajax-replaced dependee has zero retained bindings in ownedEventBindings.');
    $this->assertSame(0, $afterReplace['disconnected'], 'No disconnected element bindings remain in ownedEventBindings after Ajax replace.');

    // Also test non-Ajax raw removal pruning on next attachBehaviors, plus
    // items 8 (marker handoff), 13a (undefined disabled), and 13b (bare #id).
    $extra = $session->evaluateScript(<<<'JS'
      (() => {
        // 7b. A dependee temporarily removed around attachBehaviors keeps its
        // triggers when re-inserted. An unrelated unload does not prune it.
        const rawWrap = document.createElement('div');
        rawWrap.innerHTML = `
          <input type="checkbox" id="raw-dependee">
          <div id="raw-dependent" class="js-form-wrapper" data-drupal-states='{"visible":{"#raw-dependee":{"checked":true}}}'>Raw dep</div>
        `;
        document.body.append(rawWrap);
        Drupal.attachBehaviors(rawWrap);
        const rawDependee = document.getElementById('raw-dependee');
        const rawDependent = document.getElementById('raw-dependent');
        const rawBefore = window.r2SnapshotBindings(rawDependee).forTarget;
        rawDependee.remove();
        Drupal.attachBehaviors(document);
        const rawAfterAttach = window.r2SnapshotBindings(rawDependee).forTarget;
        rawWrap.prepend(rawDependee);
        rawDependee.checked = true;
        rawDependee.dispatchEvent(new Event('change', { bubbles: true }));
        const rawWorksAfterReinsert = rawDependent.style.display !== 'none';
        rawDependee.remove();
        const unrelatedUnload = document.createElement('div');
        Drupal.detachBehaviors(unrelatedUnload, drupalSettings, 'unload');
        const rawAfterUnload = window.r2SnapshotBindings(rawDependee).forTarget;
        rawWrap.remove();

        // 8. Marker handoff: broad trigger takes over narrow trigger's marker,
        // disconnects while narrow is active; later narrow dependent reuses it.
        const mhRoot = document.createElement('div');
        mhRoot.innerHTML = `
          <input type="checkbox" id="mh-cb-2" class="mh-cb">
          <input type="checkbox" id="mh-cb-1" class="mh-cb">
          <div id="mh-narrow-a" class="js-form-wrapper" data-drupal-states='{"visible":{"#mh-cb-1":{"checked":true}}}'>Narrow A</div>
        `;
        document.body.append(mhRoot);
        Drupal.attachBehaviors(mhRoot);

        const broadWrap = document.createElement('div');
        broadWrap.innerHTML = `<div id="mh-broad-b" class="js-form-wrapper" data-drupal-states='{"visible":{".mh-cb":{"checked":true}}}'>Broad B</div>`;
        mhRoot.append(broadWrap);
        Drupal.attachBehaviors(broadWrap);

        Drupal.detachBehaviors(broadWrap, drupalSettings, 'unload');
        broadWrap.remove();

        const narrowWrapC = document.createElement('div');
        narrowWrapC.innerHTML = `<div id="mh-narrow-c" class="js-form-wrapper" data-drupal-states='{"visible":{"#mh-cb-1":{"checked":true}}}'>Narrow C</div>`;
        mhRoot.append(narrowWrapC);
        Drupal.attachBehaviors(narrowWrapC);

        const mhCb1 = document.getElementById('mh-cb-1');
        let mhStateEvents = 0;
        mhCb1.addEventListener('state:checked', () => {
          mhStateEvents += 1;
        });
        mhCb1.checked = true;
        mhCb1.dispatchEvent(new Event('change', { bubbles: true }));
        const mhBothVisible =
          document.getElementById('mh-narrow-a').style.display !== 'none' &&
          document.getElementById('mh-narrow-c').style.display !== 'none';

        // 13a. state:disabled with undefined value toggles form-disabled class
        // and leaves element.disabled untouched (pinned jQuery behavior).
        Drupal.states.registerTrigger('undefState', {
          change() {
            const v = this[0].value;
            return v === 'undef' ? undefined : v === 'true';
          },
        });
        const undefRoot = document.createElement('div');
        undefRoot.innerHTML = `
          <input type="text" id="undef-trig" value="true">
          <div class="js-form-item" id="undef-wrap">
            <input type="text" id="undef-in">
          </div>
        `;
        document.body.append(undefRoot);
        const undefTrig = document.getElementById('undef-trig');
        const undefWrap = document.getElementById('undef-wrap');
        const undefIn = document.getElementById('undef-in');
        new Drupal.states.Dependent({
          elements: [undefIn],
          state: Drupal.states.State.sanitize('disabled'),
          constraints: { '#undef-trig': { undefState: (v) => v } },
        });
        while (Drupal.states.postponed.length) {
          Drupal.states.postponed.shift()();
        }
        const undefStep1 = [undefWrap.classList.contains('form-disabled'), undefIn.disabled];
        undefTrig.value = 'undef';
        undefTrig.dispatchEvent(new Event('change', { bubbles: true }));
        const undefStep2 = [undefWrap.classList.contains('form-disabled'), undefIn.disabled];
        undefTrig.value = 'false';
        undefTrig.dispatchEvent(new Event('change', { bubbles: true }));
        const undefStep3 = [undefWrap.classList.contains('form-disabled'), undefIn.disabled];
        undefTrig.value = 'undef';
        undefTrig.dispatchEvent(new Event('change', { bubbles: true }));
        const undefStep4 = [undefWrap.classList.contains('form-disabled'), undefIn.disabled];

        // 13b. Bare #id selector matches at most one element like getElementById.
        const dupRoot = document.createElement('div');
        dupRoot.innerHTML = `
          <input type="checkbox" id="dup-id-cb" data-which="first">
          <input type="checkbox" id="dup-id-cb" data-which="second">
          <div id="dup-id-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#dup-id-cb":{"checked":true}}}'>Dup dep</div>
        `;
        document.body.append(dupRoot);
        Drupal.attachBehaviors(dupRoot);
        const [dupFirst, dupSecond] = dupRoot.querySelectorAll('input[id="dup-id-cb"]');
        const dupDep = document.getElementById('dup-id-dep');
        dupSecond.checked = true;
        dupSecond.dispatchEvent(new Event('change', { bubbles: true }));
        const visibleAfterSecond = dupDep.style.display !== 'none';
        dupFirst.checked = true;
        dupFirst.dispatchEvent(new Event('change', { bubbles: true }));
        const visibleAfterFirst = dupDep.style.display !== 'none';

        return {
          rawBefore,
          rawAfterAttach,
          rawWorksAfterReinsert,
          rawAfterUnload,
          mhStateEvents,
          mhBothVisible,
          undefSteps: [undefStep1, undefStep2, undefStep3, undefStep4],
          visibleAfterSecond,
          visibleAfterFirst,
        };
      })()
      JS);

    $this->assertGreaterThan(0, $extra['rawBefore']);
    $this->assertSame($extra['rawBefore'], $extra['rawAfterAttach'], 'Temporarily removed dependee keeps its bindings across attachBehaviors.');
    $this->assertTrue($extra['rawWorksAfterReinsert'], 'Dependee removed and re-inserted around attachBehaviors still drives its dependent.');
    $this->assertSame($extra['rawBefore'], $extra['rawAfterUnload'], 'An unrelated unload detach does not prune a dependee outside its context.');
    $this->assertSame(1, $extra['mhStateEvents'], 'Marker handoff preserves active narrow trigger so later narrow dependent reuses it without duplicating state:checked.');
    $this->assertTrue($extra['mhBothVisible']);
    $this->assertSame([
      [TRUE, TRUE],
      [FALSE, TRUE],
      [FALSE, FALSE],
      [TRUE, FALSE],
    ], $extra['undefSteps'], 'Undefined state:disabled toggles form-disabled class and leaves element.disabled untouched.');
    $this->assertFalse($extra['visibleAfterSecond'], 'Bare #id selector ignores duplicate second element.');
    $this->assertTrue($extra['visibleAfterFirst'], 'Bare #id selector matches only the first element via getElementById.');
  }

  /**
   * Tests real state:* transitions deliver shared synthetic jQuery events.
   */
  #[IgnoreDeprecations]
  public function testStateEventSyntheticProjection(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/events');
    $session->executeScript(<<<'JS'
      const vendor = Array.from(document.scripts).find(({ src }) => src.includes('/assets/vendor/jquery/jquery.min.js'));
      const script = document.createElement('script');
      script.src = vendor.src;
      script.onload = () => {
        window.r3SecondJquery = window.jQuery.noConflict(true);
      };
      document.head.append(script);
      JS);
    $this->assertJsCondition('typeof window.r3SecondJquery === "function"');

    $session->executeScript(<<<'JS'
      const primary = window.jQuery;
      const second = window.r3SecondJquery;
      window.r3StopSecond = Drupal.eventBindings.connectLegacy(second);

      const form = document.createElement('form');
      form.id = 'r3-state-form';
      form.innerHTML = `
        <input type="checkbox" id="r3-src" name="r3_src">
        <div id="r3-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r3-src":{"checked":true}}}'>R3 dep</div>
      `;
      document.body.append(form);
      Drupal.attachBehaviors(form);

      const dep = document.getElementById('r3-dep');
      window.r3StateTrace = { primary: [], second: [], native: [] };
      dep.addEventListener('state:visible', (event) => {
        window.r3StateTrace.native.push([event instanceof CustomEvent, event.detail.value, !('isTrigger' in event)]);
      });
      for (const [instance, key] of [[primary, 'primary'], [second, 'second']]) {
        let fieldEvent = null;
        instance(dep).on('state:visible.r3proj', (event) => {
          fieldEvent = event;
          event.r3Note = `note-${key}`;
          window.r3StateTrace[key].push([
            'field',
            event.isTrigger,
            event.originalEvent === undefined,
            event.value,
            event.trigger,
          ]);
        });
        instance(form).on('state:visible.r3proj', (event) => {
          if (event.target === dep) {
            window.r3StateTrace[key].push([
              'form',
              event === fieldEvent,
              event.r3Note ?? null,
              event.isTrigger,
              event.originalEvent === undefined,
              event.value,
            ]);
          }
        });
      }
      JS);
    $assert->elementExists('css', '#r3-src')->check();
    $this->assertTrue($assert->elementExists('css', '#r3-dep')->isVisible());
    $stateTrace = $session->evaluateScript('window.r3StateTrace');
    $session->executeScript('window.r3StopSecond();');
    $this->assertSame([
      ['field', 3, TRUE, TRUE, TRUE],
      ['form', TRUE, 'note-primary', 3, TRUE, TRUE],
    ], $stateTrace['primary'], 'Primary jQuery receives one shared state:* event with isTrigger === 3, no originalEvent, and shared annotation across bubbling targets.');
    $this->assertSame([
      ['field', 3, TRUE, TRUE, TRUE],
      ['form', TRUE, 'note-second', 3, TRUE, TRUE],
    ], $stateTrace['second'], 'Second connected jQuery receives its own shared state:* event with isTrigger === 3, no originalEvent, and shared annotation across bubbling targets.');
    $this->assertSame([[TRUE, TRUE, TRUE]], $stateTrace['native'], 'Native listener still receives native CustomEvent.');
  }

  /**
   * Tests wasConnected updates, function trigger cleanup, multi-event listen(), and nits.
   */
  #[IgnoreDeprecations]
  public function testFunctionTriggerCleanupListenAndNits(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/events');
    $r3Result = $session->evaluateScript(<<<'JS'
      (() => {
        const jquery = window.jQuery;
        const snapshotBindings = (target) => {
          const list = [];
          const stop = Drupal.eventBindings.observe({
            add(binding) {
              list.push(binding);
            },
            remove() {},
          });
          stop();
          return list.filter((b) => b.target === target).length;
        };

        // Item 2: a dependee attached while disconnected, then inserted and
        // moved into a detached holder, is pruned by an unload of the holder.
        // An unrelated unload leaves it alone.
        const offDomSrc = document.createElement('input');
        offDomSrc.type = 'checkbox';
        offDomSrc.id = 'r3-detached-src';
        const offDomTrigger = new Drupal.states.Trigger({
          selector: '#r3-detached-src',
          state: Drupal.states.State.sanitize('checked'),
          elements: [offDomSrc],
        });
        while (Drupal.states.postponed.length) {
          Drupal.states.postponed.shift()();
        }
        const offDomBeforeInsert = snapshotBindings(offDomSrc);
        document.body.append(offDomSrc);
        Drupal.attachBehaviors(document);
        const offDomHolder = document.createElement('div');
        offDomHolder.append(offDomSrc);
        Drupal.detachBehaviors(document.createElement('div'), drupalSettings, 'unload');
        const offDomAfterUnrelated = snapshotBindings(offDomSrc);
        Drupal.detachBehaviors(offDomHolder, drupalSettings, 'unload');
        const offDomAfterUnload = snapshotBindings(offDomSrc);
        const offDomTriggerElements = offDomTrigger.elements.length;

        // Item 3: A function-style legacy initializer trigger registers
        // trackTargetCleanup. A replaced dependee (unloaded, then removed) is
        // released from trigger.elements once it leaves the document. While
        // it stays connected it keeps the trigger so the initializer runs once.
        const capturedFnTriggers = [];
        const origInit = Drupal.states.Trigger.prototype.initialize;
        Drupal.states.Trigger.prototype.initialize = function (...args) {
          if ((this.state?.name ?? String(this.state)) === 'r3FnState') {
            capturedFnTriggers.push(this);
          }
          return origInit.apply(this, args);
        };
        Drupal.states.Trigger.states.r3FnState = function ($elements) {
          $elements.on('change.r3fn', () => {
            $elements.trigger({ type: 'state:r3FnState', value: $elements[0].checked });
          });
        };
        const fnWrap = document.createElement('div');
        fnWrap.innerHTML = `
          <div id="r3-fn-host"><input type="checkbox" id="r3-fn-src"></div>
          <div id="r3-fn-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r3-fn-src":{"r3FnState":true}}}'>Fn dep</div>
        `;
        document.body.append(fnWrap);
        try {
          Drupal.attachBehaviors(fnWrap);
        } finally {
          Drupal.states.Trigger.prototype.initialize = origInit;
        }
        const fnHost = document.getElementById('r3-fn-host');
        const fnSrc = document.getElementById('r3-fn-src');
        const fnTrigger = capturedFnTriggers[0];
        const fnElementsBefore = fnTrigger?.elements?.includes(fnSrc) ?? false;
        const fnBindingsBefore = snapshotBindings(fnSrc);
        Drupal.detachBehaviors(fnHost, drupalSettings, 'unload');
        const fnElementsWhileConnected = fnTrigger?.elements?.includes(fnSrc) ?? false;
        fnHost.remove();
        window.r3FnAfter = () => ({
          fnElementsAfter: fnTrigger?.elements?.length ?? -1,
          fnBindingsAfter: snapshotBindings(fnSrc),
        });

        // Item 8a: checked handler leaves input.checked untouched when value is undefined.
        Drupal.states.registerTrigger('r3UndefChecked', {
          change() {
            const v = this[0].value;
            return v === 'undef' ? undefined : v === 'true';
          },
        });
        const chkUndefWrap = document.createElement('div');
        chkUndefWrap.innerHTML = `
          <input type="text" id="r3-chk-undef-src" value="true">
          <div class="js-form-item"><input type="checkbox" id="r3-chk-undef-dst"></div>
        `;
        document.body.append(chkUndefWrap);
        const chkUndefSrc = document.getElementById('r3-chk-undef-src');
        const chkUndefDst = document.getElementById('r3-chk-undef-dst');
        new Drupal.states.Dependent({
          elements: [chkUndefDst],
          state: Drupal.states.State.sanitize('checked'),
          constraints: { '#r3-chk-undef-src': { r3UndefChecked: (v) => v } },
        });
        while (Drupal.states.postponed.length) {
          Drupal.states.postponed.shift()();
        }
        const checkedWhenTrue = chkUndefDst.checked;
        chkUndefSrc.value = 'undef';
        chkUndefSrc.dispatchEvent(new Event('change', { bubbles: true }));
        const checkedWhenUndef = chkUndefDst.checked;
        chkUndefWrap.remove();

        // Item 8b: collapsed() on an empty collection returns true without throwing.
        let collapsedEmptyNative;
        let collapsedEmptyJquery;
        try {
          collapsedEmptyNative = Drupal.states.Trigger.states.collapsed.collapsed.call([]);
        } catch (e) {
          collapsedEmptyNative = `threw:${e.name}`;
        }
        try {
          collapsedEmptyJquery = Drupal.states.Trigger.states.collapsed.collapsed.call(jquery([]));
        } catch (e) {
          collapsedEmptyJquery = `threw:${e.name}`;
        }

        return {
          offDomBeforeInsert: offDomBeforeInsert > 0,
          offDomKeptByUnrelated: offDomAfterUnrelated === offDomBeforeInsert,
          offDomAfterUnload,
          offDomTriggerElements,
          fnElementsBefore,
          fnBindingsBefore: fnBindingsBefore > 0,
          fnElementsWhileConnected,
          checkedWhenTrue,
          checkedWhenUndef,
          collapsedEmptyNative,
          collapsedEmptyJquery,
        };
      })()
      JS);
    $r3Result += $session->evaluateScript('window.r3FnAfter()');

    // Item 6: listen() splits whitespace-separated event names ('keyup change')
    // and per-name namespaces, tested with real user input.
    $session->executeScript(<<<'JS'
      Drupal.states.Trigger.states.r3MultiEvent = {
        'keyup.r3multi change.r3multi'() {
          return this.val() === 'ready';
        },
      };
      const multiWrap = document.createElement('div');
      multiWrap.id = 'r3-multi-wrap';
      multiWrap.innerHTML = `
        <input id="r3-multi-input" name="r3_multi_input" type="text" value="">
        <div id="r3-multi-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r3-multi-input":{"r3MultiEvent":true}}}'>Multi dep</div>
      `;
      document.body.append(multiWrap);
      Drupal.attachBehaviors(multiWrap);
      JS);
    $multiDep = $assert->elementExists('css', '#r3-multi-dep');
    $multiInitialVisible = $multiDep->isVisible();
    $session->getDriver()->getWebDriverSession()
      ->element('css selector', '#r3-multi-input')
      ->postValue(['text' => 'ready']);
    $multiAfterKeyupVisible = $multiDep->isVisible();
    // Prove the change half alone: a new value without an event does not flip
    // the state, and a lone change event does, while keyup is still bound.
    $session->executeScript("document.getElementById('r3-multi-input').value = 'reset';");
    $multiBeforeChangeVisible = $multiDep->isVisible();
    $session->executeScript("document.getElementById('r3-multi-input').dispatchEvent(new Event('change', { bubbles: true }));");
    $multiAfterChangeVisible = $multiDep->isVisible();

    $r3Result['multiInitialVisible'] = $multiInitialVisible;
    $r3Result['multiAfterKeyupVisible'] = $multiAfterKeyupVisible;
    $r3Result['multiBeforeChangeVisible'] = $multiBeforeChangeVisible;
    $r3Result['multiAfterChangeVisible'] = $multiAfterChangeVisible;

    $this->assertEquals([
      'offDomBeforeInsert' => TRUE,
      'offDomKeptByUnrelated' => TRUE,
      'offDomAfterUnload' => 0,
      'offDomTriggerElements' => 0,
      'fnElementsBefore' => TRUE,
      'fnBindingsBefore' => TRUE,
      'fnElementsWhileConnected' => TRUE,
      'fnElementsAfter' => 0,
      'fnBindingsAfter' => 0,
      'checkedWhenTrue' => TRUE,
      'checkedWhenUndef' => TRUE,
      'collapsedEmptyNative' => TRUE,
      'collapsedEmptyJquery' => TRUE,
      'multiInitialVisible' => FALSE,
      'multiAfterKeyupVisible' => TRUE,
      'multiBeforeChangeVisible' => TRUE,
      'multiAfterChangeVisible' => FALSE,
    ], $r3Result);
  }

  /**
   * Tests a pre-states.js jQuery(document).one() handler cancels built-in States.
   */
  #[IgnoreDeprecations]
  public function testPreStatesOneShotCancelsBuiltIn(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/bridge-events');
    $session->executeScript(<<<'JS'
      window.r3OneReady = false;
      window.r3OneCalls = 0;
      jQuery(document).one('state:visible', '#r3-one-dep.r3-armed', () => {
        window.r3OneCalls += 1;
        return false;
      });
      (async () => {
        const load = (path) => new Promise((resolve, reject) => {
          const script = document.createElement('script');
          script.src = drupalSettings.path.baseUrl + path;
          script.onload = resolve;
          script.onerror = reject;
          document.head.append(script);
        });
        if (typeof window.once !== 'function') {
          await load('core/assets/vendor/once/once.min.js');
        }
        await load('core/misc/states.js');
        const oneForm = document.createElement('form');
        oneForm.id = 'r3-one-form';
        oneForm.innerHTML = `
          <input type="checkbox" id="r3-one-src" name="r3_one_src">
          <div id="r3-one-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r3-one-src":{"checked":true}}}'>One dep</div>
        `;
        document.body.append(oneForm);
        Drupal.attachBehaviors(oneForm);
        window.r3OneReady = true;
      })();
      JS);
    $this->assertJsCondition('window.r3OneReady === true');
    $oneDep = $assert->elementExists('css', '#r3-one-dep');
    $oneSrc = $assert->elementExists('css', '#r3-one-src');
    $this->assertFalse($oneDep->isVisible());
    $this->assertSame(0, $session->evaluateScript('window.r3OneCalls'));

    $session->executeScript("document.getElementById('r3-one-dep').classList.add('r3-armed');");
    $oneSrc->check();
    $this->assertSame(1, $session->evaluateScript('window.r3OneCalls'));
    $this->assertFalse($oneDep->isVisible(), 'Pre-states.js jQuery(document).one("state:visible", sel, () => false) cancels the built-in States handler.');

    $oneSrc->uncheck();
    $oneSrc->check();
    $this->assertTrue($oneDep->isVisible(), 'Built-in state:visible handler resumes after one-shot handler is consumed.');
    $this->assertSame(1, $session->evaluateScript('window.r3OneCalls'));
  }

  /**
   * Tests payload edits across jQuery instances and the collapsed click.
   */
  #[IgnoreDeprecations]
  public function testRound4ProjectionPayloadAndCollapsedClick(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/events');
    $session->executeScript(<<<'JS'
      const vendor = Array.from(document.scripts).find(({ src }) => src.includes('/assets/vendor/jquery/jquery.min.js'));
      const script = document.createElement('script');
      script.src = vendor.src;
      script.onload = () => {
        window.r4SecondJquery = window.jQuery.noConflict(true);
      };
      document.head.append(script);
      JS);
    $this->assertJsCondition('typeof window.r4SecondJquery === "function"');

    $session->executeScript(<<<'JS'
      const primary = window.jQuery;
      const second = window.r4SecondJquery;
      window.r4StopSecond = Drupal.eventBindings.connectLegacy(second);
      window.r4Trace = { a: [], b: [] };

      // The editing instance binds first, so its handler runs first. The
      // passive instance only observes and must not restore the old value.
      const build = (key, editor, passive) => {
        const form = document.createElement('form');
        form.innerHTML = `
          <input type="checkbox" id="r4-src-${key}" name="r4_src_${key}">
          <div id="r4-dep-${key}" class="js-form-wrapper" data-drupal-states='{"visible":{"#r4-src-${key}":{"checked":true}}}'>R4 dep ${key}</div>
        `;
        document.body.append(form);
        Drupal.attachBehaviors(form);
        const dep = form.querySelector(`#r4-dep-${key}`);
        editor(dep).on('state:visible.r4edit', (event) => {
          if (event.trigger && event.value) {
            event.value = false;
          }
        });
        passive(dep).on('state:visible', (event) => {
          window.r4Trace[key].push([event.value, event.namespace]);
        });
      };
      build('a', primary, second);
      build('b', second, primary);

      // Legacy summary click handlers on a mixed page.
      const details = document.createElement('div');
      details.innerHTML = `
        <input type="checkbox" id="r4-col-src" name="r4_col_src">
        <details id="r4-details" data-drupal-states='{"collapsed":{"#r4-col-src":{"checked":false}}}'><summary>R4 summary</summary><p>R4 body</p></details>
      `;
      document.body.append(details);
      Drupal.attachBehaviors(details);
      const summary = details.querySelector('summary');
      window.r4Click = { direct: [], delegated: [], native: [], cancel: false };
      primary(summary).on('click', (event) => {
        window.r4Click.direct.push([event.isTrigger ?? null, event.originalEvent === undefined]);
        return window.r4Click.cancel ? false : undefined;
      });
      primary(document).on('click', 'summary', (event) => {
        if (event.target === summary) {
          window.r4Click.delegated.push([event.isTrigger ?? null, event.originalEvent === undefined]);
        }
      });
      summary.addEventListener('click', (event) => {
        window.r4Click.native.push(event instanceof MouseEvent);
      });
      window.r4Trace.a.length = 0;
      window.r4Trace.b.length = 0;
      JS);

    $assert->elementExists('css', '#r4-src-a')->check();
    $assert->elementExists('css', '#r4-src-b')->check();
    $this->assertFalse($assert->elementExists('css', '#r4-dep-a')->isVisible(), 'A passive second instance keeps the primary instance edit.');
    $this->assertFalse($assert->elementExists('css', '#r4-dep-b')->isVisible(), 'A passive primary instance keeps the second instance edit.');
    $trace = $session->evaluateScript('window.r4Trace');
    $this->assertSame([[FALSE, '']], $trace['a'], 'The passive instance sees the edited value and an empty namespace.');
    $this->assertSame([[FALSE, '']], $trace['b'], 'The passive instance sees the edited value and an empty namespace.');

    $details = $assert->elementExists('css', '#r4-details');
    $this->assertFalse($details->hasAttribute('open'));
    $assert->elementExists('css', '#r4-col-src')->check();
    $this->assertTrue($details->hasAttribute('open'), 'state:collapsed still opens the details element.');
    $click = $session->evaluateScript('window.r4Click');
    $session->executeScript('window.r4StopSecond();');
    $this->assertSame([[3, TRUE]], $click['direct'], 'A legacy summary click handler receives a synthetic click.');
    $this->assertSame([[3, TRUE]], $click['delegated'], 'A delegated legacy click handler receives the same synthetic click.');
    $this->assertSame([TRUE], $click['native'], 'A native summary listener still receives a click.');

    $session->executeScript('window.r4Click.cancel = true;');
    $assert->elementExists('css', '#r4-col-src')->uncheck();
    $this->assertTrue($details->hasAttribute('open'), 'A legacy summary handler returning false cancels the toggle.');
  }

  /**
   * Tests unload-scoped release of replaced and unrelated dependees.
   */
  #[IgnoreDeprecations]
  public function testRound4ReplacementReleasesPermanentTriggers(): void {
    $session = $this->getSession();

    $this->drupalGet('jquery-bc-test/events');
    $session->executeScript(<<<'JS'
      window.r4Bindings = (target) => {
        const list = [];
        const stop = Drupal.eventBindings.observe({
          add(binding) {
            list.push(binding);
          },
          remove() {},
        });
        stop();
        return list.filter((b) => b.target === target).length;
      };
      const captured = [];
      const initialize = Drupal.states.Trigger.prototype.initialize;
      Drupal.states.Trigger.prototype.initialize = function (...args) {
        if ((this.state?.name ?? String(this.state)) === 'r4FnState') {
          captured.push(this);
        }
        return initialize.apply(this, args);
      };
      Drupal.states.Trigger.states.r4FnState = function ($elements) {
        $elements.on('change.r4fn', () => {
          $elements.trigger({ type: 'state:r4FnState', value: $elements[0].checked });
        });
      };
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <form id="r4-replace-form"><input type="checkbox" id="r4-fn-src"></form>
        <div id="r4-fn-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r4-fn-src":{"r4FnState":true}}}'>Fn dep</div>
        <div id="r4-raw-host"><input type="checkbox" id="r4-raw-src"></div>
        <div id="r4-raw-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r4-raw-src":{"checked":true}}}'>Raw dep</div>
      `;
      document.body.append(wrap);
      try {
        Drupal.attachBehaviors(wrap);
      } finally {
        Drupal.states.Trigger.prototype.initialize = initialize;
      }
      window.r4FnTrigger = captured[0];
      window.r4FnSrc = document.getElementById('r4-fn-src');
      window.r4RawSrc = document.getElementById('r4-raw-src');
      window.r4Before = {
        fn: window.r4FnTrigger.elements.includes(window.r4FnSrc),
        fnBindings: window.r4Bindings(window.r4FnSrc) > 0,
        raw: window.r4Bindings(window.r4RawSrc),
      };

      // One normal form replacement: detach while connected, then replace.
      const form = document.getElementById('r4-replace-form');
      Drupal.detachBehaviors(form, drupalSettings, 'unload');
      const replacement = document.createElement('form');
      replacement.id = 'r4-replace-form';
      form.replaceWith(replacement);
      JS);
    $before = $session->evaluateScript('window.r4Before');
    $replaced = $session->evaluateScript(<<<'JS'
      ({
        fn: window.r4FnTrigger.elements.includes(window.r4FnSrc),
        fnBindings: window.r4Bindings(window.r4FnSrc),
      })
      JS);
    // A dependee removed without a detach, then an unrelated unload.
    $rawAfter = $session->evaluateScript(<<<'JS'
      (() => {
        document.getElementById('r4-raw-host').remove();
        Drupal.detachBehaviors(document.createElement('div'), drupalSettings, 'unload');
        return window.r4Bindings(window.r4RawSrc);
      })()
      JS);
    $this->assertTrue($before['fn']);
    $this->assertTrue($before['fnBindings']);
    $this->assertGreaterThan(0, $before['raw']);
    $this->assertFalse($replaced['fn'], 'A single replacement releases the permanent function trigger element.');
    $this->assertSame(0, $replaced['fnBindings'], 'A single replacement releases the replaced dependee bindings.');
    $this->assertSame($before['raw'], $rawAfter, 'An unrelated unload does not prune a dependee outside its context.');
  }

  /**
   * Tests that States reads payload edits a special.handle hook makes.
   */
  #[IgnoreDeprecations]
  public function testSpecialHandleHookPayloadEdits(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/events');
    $hadSpecial = $session->evaluateScript(<<<'JS'
      (() => {
        const had = Object.hasOwn(jQuery.event.special, 'state:checked');
        window.r5Hook = { seen: [], after: [] };
        // The hook edits the payload, calls the handler, then restores it.
        jQuery.event.special['state:checked'] = {
          handle(event, ...args) {
            if (!event.value) {
              return event.handleObj.handler.call(this, event, ...args);
            }
            event.value = false;
            const result = event.handleObj.handler.call(this, event, ...args);
            window.r5Hook.seen.push(event.value);
            event.value = true;
            return result;
          },
        };
        const form = document.createElement('form');
        form.innerHTML = `
          <input type="checkbox" id="r5-hook-src" name="r5_hook_src">
          <div id="r5-hook-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r5-hook-src":{"checked":true}}}'>R5 hook dep</div>
        `;
        document.body.append(form);
        Drupal.attachBehaviors(form);
        // An external handler routes the States slot through jQuery.
        jQuery('#r5-hook-src').on('state:checked', (event) => {
          window.r5Hook.after.push(event.value);
        });
        return had;
      })()
      JS);
    $this->assertFalse($hadSpecial, 'No state:checked special hook existed before the test installed one.');

    $assert->elementExists('css', '#r5-hook-src')->check();
    $this->assertFalse($assert->elementExists('css', '#r5-hook-dep')->isVisible(), 'States reads the value the hook set before calling the handler.');
    $hook = $session->evaluateScript('window.r5Hook');
    $this->assertNotEmpty($hook['seen']);
    $this->assertSame([], array_filter($hook['seen']), 'No handler changed the edited value.');
    $this->assertSame([FALSE], $hook['after'], 'Every jQuery handler runs inside the hook.');
  }

  /**
   * Tests native summary listeners on cancelled and uncancelled clicks.
   */
  #[IgnoreDeprecations]
  public function testCancelledCollapsedClickSkipsNativeListeners(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/events');
    $session->executeScript(<<<'JS'
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <input type="checkbox" id="r5-col-src" name="r5_col_src">
        <details id="r5-details" data-drupal-states='{"collapsed":{"#r5-col-src":{"checked":false}}}'><summary>R5 summary</summary><p>R5 body</p></details>
      `;
      document.body.append(wrap);
      Drupal.attachBehaviors(wrap);
      const summary = wrap.querySelector('summary');
      window.r5Click = { legacy: [], native: 0, delegated: 0, mode: null };
      // A native listener bound before the legacy handler.
      summary.addEventListener('click', () => {
        window.r5Click.native += 1;
      });
      jQuery(summary).on('click', (event) => {
        window.r5Click.legacy.push([event.isTrigger ?? null, event.originalEvent === undefined]);
        if (window.r5Click.mode === 'false') {
          return false;
        }
        if (window.r5Click.mode === 'prevent') {
          event.preventDefault();
        }
        return undefined;
      });
      document.addEventListener('click', (event) => {
        if (event.target === summary) {
          window.r5Click.delegated += 1;
        }
      });
      JS);

    $details = $assert->elementExists('css', '#r5-details');
    $source = $assert->elementExists('css', '#r5-col-src');
    $source->check();
    $this->assertTrue($details->hasAttribute('open'), 'An uncancelled click opens the details element.');
    $click = $session->evaluateScript('window.r5Click');
    $this->assertSame([[3, TRUE]], $click['legacy']);
    $this->assertSame(1, $click['native'], 'An uncancelled click reaches the native summary listener.');
    $this->assertSame(1, $click['delegated'], 'An uncancelled click reaches a native document listener.');

    $session->executeScript('window.r5Click.mode = "false";');
    $source->uncheck();
    $this->assertTrue($details->hasAttribute('open'), 'Returning false cancels the toggle.');
    $click = $session->evaluateScript('window.r5Click');
    $this->assertCount(2, $click['legacy']);
    $this->assertSame(1, $click['native'], 'A click cancelled by returning false skips the native summary listener.');
    $this->assertSame(1, $click['delegated'], 'A click cancelled by returning false skips a native document listener.');

    $session->executeScript('window.r5Click.mode = "prevent";');
    $source->check();
    $source->uncheck();
    $this->assertTrue($details->hasAttribute('open'), 'preventDefault() cancels the toggle.');
    $click = $session->evaluateScript('window.r5Click');
    $this->assertCount(3, $click['legacy']);
    $this->assertSame(1, $click['native'], 'A click cancelled by preventDefault() skips the native summary listener.');
    $this->assertSame(1, $click['delegated'], 'A click cancelled by preventDefault() skips a native document listener.');
  }

  /**
   * Tests a reattached permanent trigger after a later move of its element.
   */
  #[IgnoreDeprecations]
  public function testReattachedPermanentTriggerSurvivesLaterMove(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/events');
    $session->executeScript(<<<'JS'
      const captured = [];
      const initialize = Drupal.states.Trigger.prototype.initialize;
      Drupal.states.Trigger.prototype.initialize = function (...args) {
        if ((this.state?.name ?? String(this.state)) === 'r5FnState') {
          captured.push(this);
        }
        return initialize.apply(this, args);
      };
      window.r5Initialized = 0;
      Drupal.states.Trigger.states.r5FnState = function ($elements) {
        window.r5Initialized += 1;
        $elements.on('change.r5fn', () => {
          $elements.trigger({ type: 'state:r5FnState', value: $elements[0].checked });
        });
      };
      const wrap = document.createElement('div');
      wrap.id = 'r5-move-wrap';
      wrap.innerHTML = `
        <div id="r5-move-a"><input type="checkbox" id="r5-move-src"></div>
        <div id="r5-move-b"></div>
        <div id="r5-move-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r5-move-src":{"r5FnState":true}}}'>Move dep</div>
      `;
      document.body.append(wrap);
      try {
        Drupal.attachBehaviors(wrap);
        // Unload, then reattach in place while the dependee stays connected.
        Drupal.detachBehaviors(wrap, drupalSettings, 'unload');
        Drupal.attachBehaviors(wrap);
      } finally {
        Drupal.states.Trigger.prototype.initialize = initialize;
      }
      window.r5MoveTrigger = captured[0];
      window.r5MoveSrc = document.getElementById('r5-move-src');
      // Move the dependee in a later task: out of the document, then back.
      window.r5MoveDone = false;
      setTimeout(() => {
        window.r5MoveSrc.remove();
        setTimeout(() => {
          document.getElementById('r5-move-b').append(window.r5MoveSrc);
          window.r5MoveDone = true;
        }, 50);
      }, 0);
      JS);
    $this->assertJsCondition('window.r5MoveDone === true');
    $this->assertSame(1, $session->evaluateScript('window.r5MoveTrigger.elements.length'), 'The reattached trigger keeps its moved element.');
    // A later reattach reuses the live trigger instead of initializing again.
    $session->executeScript(<<<'JS'
      const wrap = document.getElementById('r5-move-wrap');
      Drupal.detachBehaviors(wrap, drupalSettings, 'unload');
      Drupal.attachBehaviors(wrap);
      window.r5MoveEvents = 0;
      jQuery(window.r5MoveSrc).on('state:r5FnState', () => {
        window.r5MoveEvents += 1;
      });
      JS);
    $this->assertSame(1, $session->evaluateScript('window.r5Initialized'), 'The permanent initializer runs once.');

    $assert->elementExists('css', '#r5-move-src')->check();
    $this->assertTrue($assert->elementExists('css', '#r5-move-dep')->isVisible(), 'The dependent keeps updating after the move.');
    $assert->elementExists('css', '#r5-move-src')->uncheck();
    $this->assertFalse($assert->elementExists('css', '#r5-move-dep')->isVisible());
    $this->assertSame(2, $session->evaluateScript('window.r5MoveEvents'), 'Each change publishes one state event.');
  }

  /**
   * Tests jQuery namespace filtering on a namespaced States trigger key.
   */
  #[IgnoreDeprecations]
  public function testNamespacedTriggerKeyFilterDelivery(): void {
    $session = $this->getSession();
    $dependent = '#r5-ns-dep';

    $this->drupalGet('jquery-bc-test/events');
    $session->executeScript(<<<'JS'
      Drupal.states.Trigger.states.r5Namespaced = {
        'change.r5ns'() {
          return this.val() === 'go';
        },
      };
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <input id="r5-ns-input" name="r5_ns_input" type="text" value="wait">
        <div id="r5-ns-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r5-ns-input":{"r5Namespaced":true}}}'>R5 namespaced dep</div>
      `;
      document.body.append(wrap);
      Drupal.attachBehaviors(wrap);
      window.r5Ns = (value, call) => {
        const input = jQuery('#r5-ns-input');
        input[0].value = value;
        call(input);
        return document.getElementById('r5-ns-dep').style.display !== 'none';
      };
      JS);
    $this->assertFalse($this->assertSession()->elementExists('css', $dependent)->isVisible());
    $this->assertFalse($session->evaluateScript('window.r5Ns("go", (input) => input.trigger("change.other"))'), 'A trigger with another namespace skips the States listener.');
    $this->assertFalse($session->evaluateScript('window.r5Ns("go", (input) => input.triggerHandler("change.other"))'), 'A triggerHandler() call with another namespace skips the States listener.');
    $this->assertTrue($session->evaluateScript('window.r5Ns("go", (input) => input.triggerHandler("change.r5ns"))'), 'A triggerHandler() call with the key namespace reaches the States listener.');
    $this->assertFalse($session->evaluateScript('window.r5Ns("wait", (input) => input.trigger("change.r5ns"))'), 'A trigger with the key namespace reaches the States listener.');
    $this->assertTrue($session->evaluateScript('window.r5Ns("go", (input) => input.trigger("change"))'), 'A trigger without a namespace reaches the States listener.');
  }

  /**
   * Tests that a special.handle hook alone wraps the States handler.
   */
  #[IgnoreDeprecations]
  public function testSpecialHandleHookAloneWrapsStates(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/events');
    $hadSpecial = $session->evaluateScript(<<<'JS'
      (() => {
        const had = Object.hasOwn(jQuery.event.special, 'state:checked');
        window.r6Alone = { calls: 0 };
        // The hook is the only legacy code for this type: no jQuery handler.
        jQuery.event.special['state:checked'] = {
          handle(event, ...args) {
            window.r6Alone.calls++;
            if (event.value && event.target.id === 'r6-skip-src') {
              return undefined;
            }
            if (event.value && event.target.id === 'r6-edit-src') {
              event.value = false;
            }
            return event.handleObj.handler.call(this, event, ...args);
          },
        };
        const form = document.createElement('form');
        form.innerHTML = `
          <input type="checkbox" id="r6-edit-src" name="r6_edit_src">
          <div id="r6-edit-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r6-edit-src":{"checked":true}}}'>R6 edit dep</div>
          <input type="checkbox" id="r6-skip-src" name="r6_skip_src">
          <div id="r6-skip-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r6-skip-src":{"checked":true}}}'>R6 skip dep</div>
        `;
        document.body.append(form);
        Drupal.attachBehaviors(form);
        return had;
      })()
      JS);
    $this->assertFalse($hadSpecial, 'No state:checked special hook existed before the test installed one.');

    $assert->elementExists('css', '#r6-edit-src')->check();
    $assert->elementExists('css', '#r6-skip-src')->check();
    $this->assertFalse($assert->elementExists('css', '#r6-edit-dep')->isVisible(), 'A hook that sets value to false prevents the visibility change.');
    $this->assertFalse($assert->elementExists('css', '#r6-skip-dep')->isVisible(), 'A hook that never calls the handler prevents the visibility change.');
    $this->assertGreaterThan(0, $session->evaluateScript('window.r6Alone.calls'), 'The hook wraps the States handler.');
  }

  /**
   * Tests that a hook installed after the first publication gets the event.
   */
  #[IgnoreDeprecations]
  public function testLateSpecialHandleHookGetsPinnedEvent(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/events');
    $session->executeScript(<<<'JS'
      const form = document.createElement('form');
      form.innerHTML = `
        <input type="checkbox" id="r6-late-src" name="r6_late_src">
        <div id="r6-late-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r6-late-src":{"checked":true}}}'>R6 late dep</div>
      `;
      document.body.append(form);
      Drupal.attachBehaviors(form);
      JS);
    $source = $assert->elementExists('css', '#r6-late-src');
    $source->check();
    $source->uncheck();
    $this->assertFalse($assert->elementExists('css', '#r6-late-dep')->isVisible());

    // A library loaded later, for example through Ajax, installs the hook.
    $session->executeScript(<<<'JS'
      window.r6Late = [];
      jQuery.event.special['state:checked'] = {
        handle(event, ...args) {
          if (event.target.id === 'r6-late-src') {
            window.r6Late.push([
              event.isTrigger ?? null,
              event.value ?? null,
              event.oldValue ?? null,
              event.originalEvent === undefined,
            ]);
          }
          return event.handleObj.handler.call(this, event, ...args);
        },
      };
      JS);
    $source->check();
    $this->assertTrue($assert->elementExists('css', '#r6-late-dep')->isVisible());
    $late = $session->evaluateScript('window.r6Late');
    $this->assertNotEmpty($late, 'The late hook wraps the States handler.');
    foreach ($late as $entry) {
      $this->assertSame([3, TRUE, FALSE, TRUE], $entry, 'The late hook receives the pinned synthetic event.');
    }
  }

  /**
   * Tests that States sees a payload field a special.handle hook deletes.
   */
  #[IgnoreDeprecations]
  public function testSpecialHandleHookPayloadDeletion(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('jquery-bc-test/events');
    $session->executeScript(<<<'JS'
      window.r6Delete = { seen: [], after: [] };
      // The hook deletes the field, calls the handler, then restores it.
      jQuery.event.special['state:visible'] = {
        handle(event, ...args) {
          if (!event.value || event.target.id !== 'r6-del-dep') {
            return event.handleObj.handler.call(this, event, ...args);
          }
          delete event.trigger;
          const result = event.handleObj.handler.call(this, event, ...args);
          window.r6Delete.seen.push(Object.hasOwn(event, 'trigger'));
          event.trigger = true;
          return result;
        },
      };
      const form = document.createElement('form');
      form.innerHTML = `
        <input type="checkbox" id="r6-del-src" name="r6_del_src">
        <div id="r6-del-dep" class="js-form-wrapper" data-drupal-states='{"visible":{"#r6-del-src":{"checked":true}}}'>R6 delete dep</div>
      `;
      document.body.append(form);
      Drupal.attachBehaviors(form);
      document.addEventListener('state:visible', (event) => {
        if (event.target.id === 'r6-del-dep' && event.detail.value) {
          window.r6Delete.after.push(event.detail.trigger ?? null);
        }
      });
      JS);

    $assert->elementExists('css', '#r6-del-src')->check();
    $this->assertFalse($assert->elementExists('css', '#r6-del-dep')->isVisible(), 'States sees the trigger field the hook deleted.');
    $hook = $session->evaluateScript('window.r6Delete');
    $this->assertNotEmpty($hook['seen']);
    $this->assertSame([], array_filter($hook['seen']), 'No handler restored the deleted field.');
    $this->assertSame([TRUE], $hook['after'], 'A later native listener sees the value the hook restored.');
  }

}
