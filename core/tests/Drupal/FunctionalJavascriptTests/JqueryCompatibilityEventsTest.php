<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests jQuery synthetic events on Drupal's Ajax and States behaviors.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
final class JqueryCompatibilityEventsTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['jquery_bc_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Records native and jQuery delivery, including the handler return value.
   */
  public function testDispatchSemantics(): void {
    $this->drupalGet('jquery-bc-test/events');
    $result = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const type = 'compatibility:probe';
        const parent = document.createElement('div');
        const button = document.createElement('button');
        parent.append(button);
        document.body.append(parent);
        const trace = [];
        const target = jQuery(button);
        const ancestor = jQuery(parent);
        target.on(`${type}.compat`, (event, value) => {
          trace.push(['direct', value ?? event.originalEvent.detail]);
          return value === 'cancel' ? false : 'handled';
        });
        target.on(`${type}.other`, () => trace.push(['other']));
        ancestor.on(`${type}.compat`, 'button', (event, value) => {
          trace.push(['delegated', value ?? event.originalEvent.detail]);
        });
        button.addEventListener(type, (event) => trace.push(['native', event.detail]));

        const handlerResult = target.triggerHandler(`${type}.compat`, ['handler']);
        const bubbling = jQuery.Event(`${type}.compat`);
        target.trigger(bubbling, ['bubbling']);
        const cancelled = jQuery.Event(`${type}.compat`);
        target.trigger(cancelled, ['cancel']);
        button.dispatchEvent(new CustomEvent(type, { bubbles: true, detail: 'dom' }));
        target.off('.compat');
        ancestor.off('.compat');
        target.trigger(`${type}.compat`, ['after detach']);
        parent.remove();

        return [handlerResult, bubbling.result, cancelled.isDefaultPrevented(), cancelled.isPropagationStopped(), trace];
      })()
      JS);
    $this->assertSame([
      'handled',
      'handled',
      TRUE,
      TRUE,
      [
        ['direct', 'handler'],
        ['direct', 'bubbling'],
        ['delegated', 'bubbling'],
        ['direct', 'cancel'],
        ['direct', 'dom'],
        ['other'],
        ['native', 'dom'],
        ['delegated', 'dom'],
      ],
    ], $result);
  }

  /**
   * Records where jQuery forwards special events to native DOM listeners.
   */
  public function testSpecialEvents(): void {
    $this->drupalGet('jquery-bc-test/events');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      (() => {
        const form = document.createElement('form');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = 'compatibility-checkbox';
        form.append(checkbox);
        document.body.append(form);
        const trace = [];
        window.compatibilitySpecialEvents = trace;
        jQuery(checkbox).on('click.compat', (event) => {
          trace.push(['jquery click', checkbox.checked, Boolean(event.originalEvent)]);
        });
        checkbox.addEventListener('click', () => trace.push(['native click', checkbox.checked]));
        jQuery(checkbox).on('focus.compat blur.compat', (event) => {
          trace.push([event.type, document.activeElement === checkbox]);
        });
        jQuery(form).on('submit.compat', (event) => {
          event.preventDefault();
          trace.push(['jquery submit']);
        });
        form.addEventListener('submit', () => trace.push(['native submit']));

        jQuery(checkbox).trigger('click');
        jQuery(checkbox).trigger('focus');
        jQuery(checkbox).trigger('blur');
        jQuery(form).trigger('submit');
      })()
      JS);
    $this->assertSession()->elementExists('css', '#compatibility-checkbox')->click();
    $this->assertSame([
      ['jquery click', TRUE, FALSE],
      ['native click', TRUE],
      ['focus', TRUE],
      ['blur', FALSE],
      ['jquery submit'],
      ['focus', TRUE],
      ['jquery click', FALSE, TRUE],
      ['native click', FALSE],
    ], $session->evaluateScript('window.compatibilitySpecialEvents'));
  }

  /**
   * Tests that a synthetic link click sends one Ajax request.
   */
  public function testSyntheticAjaxLink(): void {
    $this->drupalGet('jquery-bc-test/events');
    $session = $this->getSession();
    $url = $session->getCurrentUrl();
    $session->executeScript("jQuery('a.use-ajax').trigger('click');");

    $this->assertSession()->assertExpectedAjaxRequest(1);
    $this->assertSession()->elementTextEquals('css', '#jquery-bc-test-result', 'Link response');
    $this->assertSame($url, $session->getCurrentUrl());
  }

  /**
   * Tests synthetic select events and the legacy States event value.
   */
  public function testSyntheticSelectChange(): void {
    $this->drupalGet('jquery-bc-test/events');
    $session = $this->getSession();
    $field = $this->assertSession()->fieldExists('Dependent field');
    $this->assertFalse($field->isVisible());
    $session->executeScript(<<<'JS'
      window.compatibilityStateValues = [];
      jQuery(document).on('state:visible', (event) => {
        if (event.target.name === 'dependent') {
          window.compatibilityStateValues.push(event.value);
        }
      });
      jQuery('select[name="choice"]').val('show').trigger('change');
      JS);

    $this->assertSession()->assertExpectedAjaxRequest(1);
    $this->assertSession()->elementTextEquals('css', '#jquery-bc-test-result', 'show');
    $this->assertTrue($field->isVisible());
    $this->assertSame([TRUE], $session->evaluateScript('window.compatibilityStateValues'));
  }

}
