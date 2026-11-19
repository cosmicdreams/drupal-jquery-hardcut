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
