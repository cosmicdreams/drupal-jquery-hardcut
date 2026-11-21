<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core\Form;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for form grouping elements.
 */
#[Group('form')]
#[RunTestsInSeparateProcesses]
class FormGroupingElementsTest extends WebDriverTestBase {

  /**
   * Required modules.
   *
   * @var array
   */
  protected static $modules = ['form_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);
  }

  /**
   * Tests that vertical tab children become visible.
   *
   * Makes sure that a child element of a vertical tab that is not visible,
   * becomes visible when the tab is clicked, a fragment link to the child is
   * clicked or when the URI fragment pointing to that child changes.
   */
  public function testVerticalTabChildVisibility(): void {
    $session = $this->getSession();
    $web_assert = $this->assertSession();

    // Request the group vertical tabs testing page with a fragment identifier
    // to the second element.
    $this->drupalGet('form-test/group-vertical-tabs', ['fragment' => 'edit-element-2']);
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));

    $page = $session->getPage();

    $tab_link_1 = $page->find('css', '.vertical-tabs__menu-item > a');

    $child_1_selector = '#edit-element';
    $child_1 = $page->find('css', $child_1_selector);

    $child_2_selector = '#edit-element-2';
    $child_2 = $page->find('css', $child_2_selector);

    // Assert that the child in the second vertical tab becomes visible.
    // It should be visible after initial load due to the fragment in the URI.
    $this->assertTrue($child_2->isVisible(), 'Child 2 is visible due to a URI fragment');

    // Click on a fragment link pointing to an invisible child inside an
    // inactive vertical tab.
    $session->executeScript("const link = document.createElement('a'); link.href = '$child_1_selector'; document.querySelector('h1').after(link); link.click();");

    // Assert that the child in the first vertical tab becomes visible.
    $web_assert->waitForElementVisible('css', $child_1_selector, 50);

    // Trigger a URI fragment change (hashchange) to show the second vertical
    // tab again.
    $session->executeScript("location.replace('$child_2_selector')");

    // Assert that the child in the second vertical tab becomes visible again.
    $web_assert->waitForElementVisible('css', $child_2_selector, 50);

    $tab_link_1->click();

    // Assert that the child in the first vertical tab is visible again after
    // a click on the first tab.
    $this->assertTrue($child_1->isVisible(), 'Child 1 is visible after clicking the parent tab');
  }

  /**
   * Tests that details element children become visible.
   *
   * Makes sure that a child element of a details element that is not visible,
   * becomes visible when a fragment link to the child is clicked or when the
   * URI fragment pointing to that child changes.
   */
  public function testDetailsChildVisibility(): void {
    $session = $this->getSession();
    $web_assert = $this->assertSession();

    // Store reusable JavaScript code to remove the current URI fragment and
    // close all details.
    $reset_js = "location.replace('#'); document.querySelectorAll('details').forEach((el) => el.removeAttribute('open'))";

    // Request the group details testing page.
    $this->drupalGet('form-test/group-details');
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));

    $page = $session->getPage();

    // Verify native details-aria.js toggles aria-expanded without jQuery on
    // server-rendered open="open" details.
    $summary = $page->find('css', '#edit-meta > summary');
    $summary->click();
    $this->assertEquals('false', $summary->getAttribute('aria-expanded'));
    $summary->click();
    $this->assertEquals('true', $summary->getAttribute('aria-expanded'));

    $session->executeScript($reset_js);

    $child_selector = '#edit-element';
    $child = $page->find('css', $child_selector);

    // Assert that the child is not visible.
    $this->assertFalse($child->isVisible(), 'Child is not visible');

    // Trigger a URI fragment change (hashchange) to open all parent details
    // elements of the child.
    $session->executeScript("location.replace('$child_selector')");

    // Assert that the child becomes visible again after a hash change.
    $web_assert->waitForElementVisible('css', $child_selector, 50);

    $session->executeScript($reset_js);

    // Click on a fragment link pointing to an invisible child inside a closed
    // details element.
    $session->executeScript("const link = document.createElement('a'); link.href = '$child_selector'; document.querySelector('h1').after(link); link.click();");

    // Assert that the child is visible again after a fragment link click.
    $web_assert->waitForElementVisible('css', $child_selector, 50);

    // Assert that aria-expanded is true.
    $this->assertEquals('true', $summary->getAttribute('aria-expanded'));
  }

  /**
   * Tests the details summary update on a canceled legacy click.
   */
  public function testCanceledSummaryClick(): void {
    $this->drupalGet('form-test/group-details', ['query' => ['legacy-details' => 1]]);

    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const details = document.createElement('details');
        const summary = details.appendChild(document.createElement('summary'));
        const label = summary.appendChild(document.createElement('span'));
        label.textContent = 'Details';
        document.body.append(details);
        Drupal.attachBehaviors(details);
        Drupal.attachBehaviors(details);

        let nativeClicks = 0;
        summary.addEventListener('click', () => { nativeClicks++; });
        jQuery(summary).on('click.test', (event) => event.preventDefault());

        return [null, 'open', ''].map((attribute) => {
          if (attribute === null) {
            details.removeAttribute('open');
          } else {
            details.setAttribute('open', attribute);
          }
          summary.removeAttribute('aria-expanded');
          jQuery(label).trigger('click');
          return [summary.getAttribute('aria-expanded'), details.open, nativeClicks];
        });
      })()
      JS);

    $this->assertSame([
      ['true', FALSE, 0],
      ['false', TRUE, 0],
      ['true', TRUE, 0],
    ], $result);
  }

  /**
   * Tests native details clicks, legacy click ordering and namespace removal.
   */
  public function testSummaryClickOrdering(): void {
    $this->drupalGet('form-test/group-details', ['query' => ['legacy-details' => 1]]);
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const details = document.createElement('details');
        const summary = details.appendChild(document.createElement('summary'));
        const label = summary.appendChild(document.createElement('span'));
        document.body.append(details);
        const seen = [];
        const body = jQuery(document.body);
        body.on('click.test', 'summary', () => {
          seen.push(summary.getAttribute('aria-expanded'));
        });

        label.click();
        const native = [details.open, ...seen];
        details.open = false;
        summary.removeAttribute('aria-expanded');

        body.on('click.test', 'span', (event) => {
          event.stopImmediatePropagation();
          event.preventDefault();
        });
        jQuery(label).trigger('click');
        const cancelled = [details.open, summary.getAttribute('aria-expanded')];

        body.off('.test');
        body.off('click.detailsAria', 'summary');
        label.click();
        return [native, cancelled, [details.open, summary.getAttribute('aria-expanded')]];
      })()
      JS);
    $this->assertSame([[TRUE, 'true'], [FALSE, NULL], [TRUE, NULL]], $result);
  }

  /**
   * Tests the mixed fragment event payload and deferred focus.
   */
  public function testMixedFragmentPayloadAndFocus(): void {
    $this->drupalGet('form-test/group-details', ['query' => ['legacy-details' => 1]]);
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const target = document.getElementById('edit-element');
      const form = target.form;
      const type = 'formFragmentLinkClickOrHashChange';
      document.body.addEventListener(type, (event) => {
        form.dataset.nativeFragment = event.detail === target;
      });
      let first;
      jQuery(document.body).on(type, (event, collection) => {
        first = collection;
      });
      jQuery(document.body).on(type, (event, collection) => {
        form.dataset.legacyFragment = collection === first && collection[0] === target;
        form.dataset.fragmentCount = Number(form.dataset.fragmentCount || 0) + 1;
      });
      const link = document.body.appendChild(document.createElement('a'));
      link.href = '#edit-element';
      link.click();
      JS);
    $this->assertJsCondition("document.activeElement.id === 'edit-element'");
    $this->assertSame(['true', 'true', '1'], $session->evaluateScript(<<<'JS'
      Array.from(['nativeFragment', 'legacyFragment', 'fragmentCount'], (key) =>
        document.getElementById('edit-element').form.dataset[key]
      )
      JS));
  }

  /**
   * Tests legacy summary chaining, arguments, and namespaced events.
   */
  public function testLegacySummaryPlugins(): void {
    $this->drupalGet('form-test/group-details', ['query' => ['legacy-details' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const group = document.createElement('div');
        group.innerHTML = '<div id="summary-first"><input></div><div id="summary-second"></div>';
        document.body.append(group);
        const elements = jQuery(group.children);
        const received = [];
        elements.on('summaryUpdated.test', (event) => received.push(event.currentTarget.id));
        const warn = Drupal.deprecationError;
        Drupal.deprecationError = () => {};
        try {
          const empty = elements.drupalGetSummary();
          const result = elements.drupalSetSummary((element) => '  ' + element.id + '  ');
          const updated = [elements.drupalGetSummary(), elements.eq(1).drupalGetSummary()];
          const initial = received.splice(0);
          const input = elements.eq(0).find('input');
          input.trigger('formUpdated.other');
          const otherNamespace = received.splice(0);
          input.trigger('formUpdated.summary');
          const grouped = received.splice(0);
          elements.eq(0).off('formUpdated.summary');
          input.trigger('formUpdated');
          const afterRemoval = received.splice(0);
          elements.eq(1).drupalSetSummary('  literal  ');
          return [empty, result === elements, updated, initial, otherNamespace, grouped, afterRemoval, elements.eq(1).drupalGetSummary()];
        } finally {
          Drupal.deprecationError = warn;
          group.remove();
        }
      })()
      JS);
    $this->assertSame([
      '',
      TRUE,
      ['summary-first', 'summary-second'],
      ['summary-first', 'summary-second'],
      [],
      ['summary-first', 'summary-second'],
      [],
      'literal',
    ], $actual);
  }

  /**
   * Tests native summary updates after form input and reattachment.
   */
  public function testNativeFormSummary(): void {
    $this->drupalGet('form-test/group-details');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $session->executeScript(<<<'JS'
      const form = document.body.appendChild(document.createElement('form'));
      form.id = 'summary-test-form';
      form.innerHTML = '<div><input id="summary-input" name="summary-input"></div>';
      const field = form.querySelector('input');
      const summary = field.parentElement;
      form.addEventListener('summaryUpdated', (event) => {
        event.target.dataset.summary = Drupal.form.getSummary(event.target);
      });
      Drupal.form.setSummary(summary, (element) => ' ' + element.querySelector('input').value + ' ');
      Drupal.behaviors.formUpdated.attach(form);
      Drupal.behaviors.formUpdated.attach(form);
      field.value = 'first';
      field.dispatchEvent(new Event('input', { bubbles: true }));
      JS);
    $this->assertJsCondition("document.querySelector('#summary-test-form [data-summary]').dataset.summary === 'first'");

    $session->executeScript(<<<'JS'
      const form = document.getElementById('summary-test-form');
      Drupal.behaviors.formUpdated.detach(form, {}, 'unload');
      Drupal.behaviors.formUpdated.attach(form);
      const field = form.querySelector('input');
      field.value = 'second';
      field.dispatchEvent(new Event('change', { bubbles: true }));
      JS);
    $this->assertJsCondition("document.querySelector('#summary-test-form [data-summary]').dataset.summary === 'second'");
  }

  /**
   * Tests that unloading a form region removes its owned event bindings.
   */
  public function testFormEventBindingsDetach(): void {
    $this->drupalGet('form-test/group-details');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $session->executeScript(<<<'JS'
      (async () => {
        const region = document.body.appendChild(document.createElement('div'));
        const details = region.appendChild(document.createElement('details'));
        const sibling = document.body.appendChild(document.createElement('div'));
        const destination = document.body.appendChild(document.createElement('div'));
        const targets = [region, details, sibling, window, document.body, document];
        const received = [];
        const stop = targets.map((target, index) =>
          Drupal.formEvents.listen(target, 'testFormDetach', undefined, () => received.push(index))
        );
        const snapshots = [];
        const snapshot = () => {
          const present = [];
          const unobserve = Drupal.eventBindings.observe({
            add(binding) {
              if (binding.type === 'testFormDetach') {
                present.push(targets.indexOf(binding.target));
              }
            },
            remove() {},
          });
          unobserve();
          snapshots.push(present);
        };
        const originalDisconnect = MutationObserver.prototype.disconnect;
        let disconnects = 0;
        MutationObserver.prototype.disconnect = function (...args) {
          disconnects += 1;
          return originalDisconnect.apply(this, args);
        };

        try {
          Drupal.detachBehaviors(region, drupalSettings, 'serialize');
          Drupal.detachBehaviors(region, drupalSettings, 'move');
          snapshot();

          // A missing Ajax wrapper falls back to the document.
          Drupal.detachBehaviors(undefined, drupalSettings, 'unload');
          await Promise.resolve();
          snapshot();

          // Replacing the contents removes the old child, keeps the wrapper,
          // disconnects the observer, and lets a later two-step move keep it.
          Drupal.detachBehaviors(region, drupalSettings, 'unload');
          region.replaceChildren();
          await Promise.resolve();
          const disconnectsAfterReplace = disconnects;
          snapshot();

          region.remove();
          await Promise.resolve();
          destination.append(region);
          await Promise.resolve();
          snapshot();
          region.dispatchEvent(new Event('testFormDetach'));
          const survivedMove = received.splice(0);

          Drupal.detachBehaviors(region, drupalSettings, 'unload');
          // htmx can defer the swap past the current task.
          await new Promise((resolve) => setTimeout(resolve, 0));
          snapshot();
          region.remove();
          await Promise.resolve();
          const disconnectsAfterRemove = disconnects;
          snapshot();
          targets.forEach((target) => target.dispatchEvent(new Event('testFormDetach')));
          window.formEventDetachResult = [
            snapshots,
            survivedMove,
            received,
            disconnectsAfterReplace > 0,
            disconnectsAfterRemove > disconnectsAfterReplace,
          ];
        }
        finally {
          MutationObserver.prototype.disconnect = originalDisconnect;
          stop.forEach((unbind) => unbind());
          region.remove();
          sibling.remove();
          destination.remove();
        }
      })();
      JS);
    $this->assertJsCondition('Boolean(window.formEventDetachResult)');
    $this->assertSame([
      [
        [0, 1, 2, 3, 4, 5],
        [0, 1, 2, 3, 4, 5],
        [0, 2, 3, 4, 5],
        [0, 2, 3, 4, 5],
        [0, 2, 3, 4, 5],
        [2, 3, 4, 5],
      ],
      [0],
      [2, 3, 4, 5],
      TRUE,
      TRUE,
    ], $session->evaluateScript('window.formEventDetachResult'));
  }

  /**
   * Tests that an unrelated insertion keeps an unload batch pending.
   */
  public function testUnloadBatchIgnoresUnrelatedMutations(): void {
    $this->drupalGet('form-test/group-details');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $session->executeScript(<<<'JS'
      (async () => {
        const region = document.body.appendChild(document.createElement('div'));
        const inner = region.appendChild(document.createElement('div'));
        const received = [];
        const stop = Drupal.formEvents.listen(inner, 'testThrobber', undefined, () => received.push('inner'));
        const count = () => {
          let total = 0;
          Drupal.eventBindings.observe({
            add(binding) {
              total += binding.type === 'testThrobber' ? 1 : 0;
            },
            remove() {},
          })();
          return total;
        };
        try {
          Drupal.detachBehaviors(region, drupalSettings, 'unload');
          // A throbber appears inside the region before the delayed swap.
          const throbber = document.createElement('span');
          throbber.className = 'ajax-progress';
          region.append(throbber);
          await Promise.resolve();
          const pending = count();
          await new Promise((resolve) => setTimeout(resolve, 0));
          region.replaceChildren();
          await Promise.resolve();
          const released = count();
          inner.dispatchEvent(new Event('testThrobber'));
          window.unloadThrobberResult = [pending, released, received];
        }
        finally {
          stop();
          region.remove();
        }
      })();
      JS);
    $this->assertJsCondition('Array.isArray(window.unloadThrobberResult)');
    $this->assertSame([1, 0, []], $session->evaluateScript('window.unloadThrobberResult'));
  }

  /**
   * Tests that legacy summary overrides receive jQuery events.
   */
  public function testLegacySummaryUpdatedOverride(): void {
    $this->drupalGet('form-test/group-details', ['query' => ['legacy-details' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const warnings = [];
        const warn = Drupal.deprecationError;
        Drupal.deprecationError = ({ message }) => warnings.push(message);
        const prototype = Drupal.DetailsSummarizedContent.prototype;
        const original = prototype.onSummaryUpdated;
        const calls = [];
        const container = document.body.appendChild(document.createElement('div'));
        try {
          prototype.onSummaryUpdated = function (event) {
            calls.push([event instanceof jQuery.Event, event.isDefaultPrevented(), event.type, this.node.id]);
            return original.call(this, event);
          };
          container.innerHTML = '<details id="override-details"><summary>Toggle</summary><input name="override"></details>';
          // Initial summary rendering runs the override.
          Drupal.attachBehaviors(container);
          const details = container.querySelector('details');
          Drupal.form.setSummary(details, () => 'Override summary');
          const text = details.querySelector('summary .summary').textContent;
          prototype.onSummaryUpdated = original;
          const restored = prototype.onSummaryUpdatedNative.legacyCallback === undefined;
          return [calls, text, restored, warnings];
        }
        finally {
          prototype.onSummaryUpdated = original;
          Drupal.deprecationError = warn;
          container.remove();
        }
      })()
      JS);
    $this->assertSame([
      [
        [TRUE, FALSE, 'summaryUpdated', 'override-details'],
        [TRUE, FALSE, 'summaryUpdated', 'override-details'],
      ],
      ' (Override summary)',
      TRUE,
      [
        'Drupal.DetailsSummarizedContent#onSummaryUpdated is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use onSummaryUpdatedNative() with a native event. See https://www.drupal.org/node/CHANGE_RECORD.',
      ],
    ], $actual);
  }

  /**
   * Tests zero-jQuery native dispatch, return values, deprecations, and details accessors.
   */
  public function testFormBridgeContracts(): void {
    $this->drupalGet('form-test/group-details', ['query' => ['legacy-details' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const warnings = [];
        const originalWarn = Drupal.deprecationError;
        Drupal.deprecationError = ({ message }) => warnings.push(message);
        const originalDispatch = jQuery.event.dispatch;
        let dispatchCalls = 0;
        jQuery.event.dispatch = function (...args) {
          dispatchCalls += 1;
          return originalDispatch.apply(this, args);
        };

        const container = document.body.appendChild(document.createElement('div'));
        container.innerHTML = '<details id="bridge-details"><summary>Toggle</summary><form id="bridge-form" method="post"><input name="field" value="one"></form></details>';
        const details = container.querySelector('details');
        const summary = details.querySelector('summary');
        const form = container.querySelector('form');
        const input = form.querySelector('input');
        Drupal.attachBehaviors(container);

        try {
          // Mixed page with no legacy subscribers makes zero jQuery dispatch calls.
          dispatchCalls = 0;
          summary.click();
          const summaryExpanded = summary.getAttribute('aria-expanded');
          const nativeDispatchCalls = dispatchCalls;

          // Legacy return false prevents default and stops propagation.
          const bubbleContainer = document.body.appendChild(document.createElement('div'));
          const button = bubbleContainer.appendChild(document.createElement('button'));
          let bubbled = false;
          jQuery(bubbleContainer).on('click.returnTest', () => { bubbled = true; });
          const stopReturn = Drupal.formEvents.listen(button, 'click', 'returnTest', () => false);
          const returnEvent = jQuery.Event('click');
          jQuery(button).trigger(returnEvent);
          stopReturn();
          jQuery(bubbleContainer).off('.returnTest');
          bubbleContainer.remove();

          // Synthetic submit against formSingleSubmit and standard event silence.
          warnings.length = 0;
          const submitFirst = jQuery.Event('submit');
          jQuery(form).on('submit.preventNav', (e) => e.preventDefault());
          jQuery(form).trigger(submitFirst);
          jQuery(form).off('submit.preventNav');
          const submitSecond = jQuery.Event('submit');
          jQuery(form).trigger(submitSecond);
          jQuery(input).trigger('change');
          jQuery(input).trigger('input');
          jQuery(summary).trigger('click');
          const standardTriggerWarnings = warnings.splice(0);

          // Drupal custom events emit §3.1 deprecation wording; summary plugins emit §3.7 wording.
          jQuery(details).drupalSetSummary('Initial');
          const gotSummary = jQuery(details).drupalGetSummary();
          jQuery(details).trigger('formUpdated');
          jQuery(details).trigger('summaryUpdated');
          const customAndPluginWarnings = warnings.splice(0);

          // DetailsSummarizedContent $node and $detailsSummarizedContentWrapper accessors.
          const instance = Drupal.DetailsSummarizedContent.instances.find((item) => item.node === details);
          const sameNode = instance.$node === instance.$node && instance.$node[0] === details;
          const sameWrapper = instance.$detailsSummarizedContentWrapper === instance.$detailsSummarizedContentWrapper
            && instance.$detailsSummarizedContentWrapper[0] === instance.summaryWrapper;
          const replacement = document.createElement('span');
          instance.$detailsSummarizedContentWrapper = jQuery(replacement);
          const updatedWrapper = instance.summaryWrapper === replacement;

          return {
            summaryExpanded,
            nativeDispatchCalls,
            returnFalse: [returnEvent.result, returnEvent.isDefaultPrevented(), returnEvent.isPropagationStopped(), bubbled],
            singleSubmit: [submitSecond.isDefaultPrevented()],
            standardTriggerWarnings,
            gotSummary,
            customAndPluginWarnings,
            detailsAccessors: [sameNode, sameWrapper, updatedWrapper],
          };
        } finally {
          jQuery.event.dispatch = originalDispatch;
          Drupal.deprecationError = originalWarn;
          container.remove();
        }
      })()
      JS);

    $this->assertSame('true', $actual['summaryExpanded']);
    $this->assertSame(0, $actual['nativeDispatchCalls']);
    $this->assertSame([FALSE, TRUE, TRUE, FALSE], $actual['returnFalse']);
    $this->assertSame([TRUE], $actual['singleSubmit']);
    $this->assertSame([], $actual['standardTriggerWarnings']);
    $this->assertSame('Initial', $actual['gotSummary']);
    $this->assertSame([
      'jQuery.fn.drupalSetSummary is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the corresponding native Drupal form summary function. See https://www.drupal.org/node/CHANGE_RECORD.',
      'jQuery.fn.drupalGetSummary is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the corresponding native Drupal form summary function. See https://www.drupal.org/node/CHANGE_RECORD.',
      'The jQuery synthetic trigger for formUpdated is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the same native event name and its documented payload. See https://www.drupal.org/node/CHANGE_RECORD.',
      'The jQuery synthetic trigger for summaryUpdated is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the same native event name and its documented payload. See https://www.drupal.org/node/CHANGE_RECORD.',
    ], $actual['customAndPluginWarnings']);
    $this->assertSame([TRUE, TRUE, TRUE], $actual['detailsAccessors']);
  }

  /**
   * Tests that fallback time zone detection completes during attachment.
   */
  public function testSynchronousTimezoneDetection(): void {
    $this->drupalGet('form-test/group-details', ['query' => ['timezone' => 1]]);

    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const wrapper = document.body.appendChild(document.createElement('div'));
        wrapper.innerHTML = '<select class="timezone-detect"><option value="Europe/Paris">Paris</option><option value="America/Chicago">Chicago</option></select>';
        const select = wrapper.querySelector('select');
        const resolvedOptions = Intl.DateTimeFormat.prototype.resolvedOptions;
        const xhr = Drupal.xhr;
        let request;
        Intl.DateTimeFormat.prototype.resolvedOptions = () => ({ timeZone: 'Unsupported' });
        Drupal.xhr = (options) => {
          request = options;
          return { status: 200, responseText: '"America/Chicago"' };
        };
        try {
          Drupal.behaviors.setTimezone.attach(wrapper);
          return [request?.async, request?.url.includes('/system/timezone/'), select.value];
        } finally {
          Intl.DateTimeFormat.prototype.resolvedOptions = resolvedOptions;
          Drupal.xhr = xhr;
          wrapper.remove();
        }
      })()
      JS);

    $this->assertSame([FALSE, TRUE, 'America/Chicago'], $result);
  }

  /**
   * Confirms tabs containing a field with a validation error are open.
   */
  public function testVerticalTabValidationVisibility(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->drupalGet('form-test/group-vertical-tabs');
    $page->clickLink('Second group element');
    $input_field = $assert_session->waitForField('element_2');
    $this->assertNotNull($input_field);

    // Enter a value that will trigger a validation error.
    $input_field->setValue('bad');

    // Switch to a tab that does not have the error-causing field.
    $page->clickLink('First group element');
    $this->assertNotNull($assert_session->waitForElementVisible('css', '#edit-meta'));

    // Submit the form.
    $page->pressButton('Save');

    // Confirm there is an error.
    $assert_session->waitForText('there was an error');

    // Confirm the tab containing the field with error is open.
    $this->assertNotNull($assert_session->waitForElementVisible('css', '[name="element_2"].error'));
  }

  /**
   * Tests form submit with a required field in closed details element.
   */
  public function testDetailsContainsRequiredTextfield(): void {
    $this->drupalGet('form_test/details-contains-required-fields');
    $details = $this->assertSession()->elementExists('css', 'details[data-drupal-selector="edit-meta"]');
    $details2 = $this->assertSession()->elementExists('css', 'details[data-drupal-selector="edit-meta2"]');
    $details3 = $this->assertSession()->elementExists('css', 'details[data-drupal-selector="edit-meta3"]');

    // Make sure details element is not open at the beginning.
    $this->assertFalse($details->hasAttribute('open'));
    $this->assertFalse($details2->hasAttribute('open'));
    $this->assertFalse($details3->hasAttribute('open'));

    $textfield = $this->assertSession()->elementExists('css', 'input[name="required_textfield_in_details"]');
    $textarea = $this->assertSession()->elementExists('css', 'textarea[name="required_textarea_in_details"]');
    $select = $this->assertSession()->elementExists('css', 'select[name="required_select_in_details"]');

    // The fields inside the details elements are not visible too.
    $this->assertFalse($textfield->isVisible(), 'Text field is not visible');
    $this->assertFalse($textarea->isVisible(), 'Text area field is not visible');
    $this->assertFalse($select->isVisible(), 'Select field is not visible');

    // Submit the form with invalid data in the required fields.
    $this->assertSession()
      ->elementExists('css', 'input[data-drupal-selector="edit-submit"]')
      ->click();
    // Confirm the required fields are visible.
    $this->assertTrue($textfield->isVisible(), 'Text field is visible');
    $this->assertTrue($textarea->isVisible(), 'Text area field is visible');
    $this->assertTrue($select->isVisible(), 'Select field is visible');
  }

  /**
   * Tests required field in closed details element with ajax form.
   */
  public function testDetailsContainsRequiredTextfieldAjaxForm(): void {
    $this->drupalGet('form_test/details-contains-required-fields/true');
    $assert_session = $this->assertSession();
    $textfield = $assert_session->elementExists('css', 'input[name="required_textfield_in_details"]');
    $textarea = $this->assertSession()->elementExists('css', 'textarea[name="required_textarea_in_details"]');
    $select = $this->assertSession()->elementExists('css', 'select[name="required_select_in_details"]');

    // Submit the ajax form to open the details element at the first time.
    $assert_session->elementExists('css', 'input[value="Submit Ajax"]')
      ->click();

    $assert_session->waitForElementVisible('css', 'input[name="required_textfield_in_details"]');
    $assert_session->waitForElementVisible('css', 'textarea[name="required_textarea_in_details"]');
    $assert_session->waitForElementVisible('css', 'select[name="required_select_in_details"]');

    // Confirm the required fields are visible.
    $this->assertTrue($textfield->isVisible(), 'Text field is visible');
    $this->assertTrue($textarea->isVisible(), 'Text area field is visible');
    $this->assertTrue($select->isVisible(), 'Select field is visible');

    // Close the details elements.
    $this->assertSession()->elementExists('css', 'details[data-drupal-selector="edit-meta"] summary')->click();
    $this->assertSession()->elementExists('css', 'details[data-drupal-selector="edit-meta2"] summary')->click();
    $this->assertSession()->elementExists('css', 'details[data-drupal-selector="edit-meta3"] summary')->click();

    // The fields inside the details elements are not visible too.
    $this->assertFalse($textfield->isVisible(), 'Text field is not visible');
    $this->assertFalse($textarea->isVisible(), 'Text area field is not visible');
    $this->assertFalse($select->isVisible(), 'Select field is not visible');

    // Submit the form with invalid data in the required fields without ajax.
    $assert_session->elementExists('css', 'input[data-drupal-selector="edit-submit"]')
      ->click();

    // Confirm the required fields are visible.
    $this->assertTrue($textfield->isVisible(), 'Text field is visible');
    $this->assertTrue($textarea->isVisible(), 'Text area field is visible');
    $this->assertTrue($select->isVisible(), 'Select field is visible');
  }

}
