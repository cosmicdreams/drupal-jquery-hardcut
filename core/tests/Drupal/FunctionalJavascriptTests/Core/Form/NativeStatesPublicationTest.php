<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core\Form;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
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
  protected static $modules = ['states_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests publications from a real select and its dependent field.
   */
  public function testSelectPublication(): void {
    $this->drupalGet('states-test/native-form');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      window.selectStateValues = [];
      const select = document.querySelector('select[name="select"]');
      select.addEventListener('state:value', (event) => {
        window.selectStateValues.push([event.detail.value, event.detail.oldValue]);
      });
      select.value = '1';
      select.dispatchEvent(new Event('change', { bubbles: true }));
      select.value = '0';
      select.dispatchEvent(new Event('change', { bubbles: true }));
      JS);
    $this->assertSame([['1', '0'], ['0', '1']], $session->evaluateScript('window.selectStateValues'));
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
        .filter((path) => path.includes('/assets/vendor/jquery/'))
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
          visible: { 'input[name="choice[1]"]': { checked: true } },
        });
        deadHost.append(deadDep);
        document.body.append(deadHost);
        Drupal.attachBehaviors(deadHost);
        let deadStateEvents = 0;
        deadDep.addEventListener('state:visible', () => {
          deadStateEvents += 1;
        });
        deadHost.remove();
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
   * Tests a reattached permanent trigger after a later move of its element.
   */
  public function testReattachedPermanentTriggerSurvivesLaterMove(): void {
    $session = $this->getSession();
    $assert = $this->assertSession();

    $this->drupalGet('states-test');
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
      Drupal.states.Trigger.states.r5FnState = function (elements) {
        window.r5Initialized += 1;
        const [input] = elements;
        input.addEventListener('change', () => {
          input.dispatchEvent(new CustomEvent('state:r5FnState', {
            bubbles: true,
            detail: { value: input.checked },
          }));
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
      window.r5MoveSrc.addEventListener('state:r5FnState', () => {
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

}
