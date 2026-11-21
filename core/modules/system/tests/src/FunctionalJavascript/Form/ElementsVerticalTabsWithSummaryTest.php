<?php

declare(strict_types=1);

namespace Drupal\Tests\system\FunctionalJavascript\Form;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that titles and summaries in vertical-tabs form elements are set correctly.
 */
#[Group('Form')]
#[RunTestsInSeparateProcesses]
class ElementsVerticalTabsWithSummaryTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['form_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Check that vertical tabs title and summaries are set correctly.
   */
  public function testDynamicSummary(): void {
    $this->drupalGet('form_test/vertical-tabs-with-summary');
    $this->assertSame('undefined', $this->getSession()->evaluateScript('typeof window.jQuery'));
    $this->assertSession()->elementTextEquals('css', '.vertical-tabs__menu-item.first .vertical-tabs__menu-item-title', 'Tab 1');
    $this->assertSession()->elementTextEquals('css', '.vertical-tabs__menu-item.first .vertical-tabs__menu-item-summary', 'Summary 1');
    $this->assertSession()->elementTextEquals('css', '.vertical-tabs__menu-item.last .vertical-tabs__menu-item-title', 'Tab 2');
    $this->assertSession()->elementTextEquals('css', '.vertical-tabs__menu-item.last .vertical-tabs__menu-item-summary', 'Summary 2');
  }

  /**
   * Tests constructor overrides, detached tab data, and legacy constructor calls.
   */
  public function testVerticalTabConstructorAndDetachedData(): void {
    $this->drupalGet('form_test/vertical-tabs-with-summary', ['query' => ['legacy' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const warn = Drupal.deprecationError;
        const warnings = [];
        Drupal.deprecationError = ({ message }) => warnings.push(message);
        const OriginalTab = Drupal.verticalTab;
        const seen = [];
        function CustomTab(settings) {
          seen.push(settings.details instanceof jQuery);
          const instance = OriginalTab.call(this, settings) || this;
          instance.customMarker = 'override-applied';
          return instance;
        }
        CustomTab.prototype = OriginalTab.prototype;
        Drupal.verticalTab = CustomTab;
        try {
          // Attach to a detached container (not in document).
          const detached = document.createElement('div');
          detached.innerHTML = '<div class="js-form-type-vertical-tabs"><div data-vertical-tabs-panes><input type="hidden" class="vertical-tabs__active-tab" value=""><details id="det-first"><summary>Detached</summary></details></div></div>';
          Drupal.behaviors.verticalTabs.attach(detached);
          const pane = detached.querySelector('#det-first');
          const tab = jQuery(pane).data('verticalTab');

          // Direct legacy constructor call with jQuery details.
          const directPane = document.createElement('details');
          directPane.id = 'direct-legacy';
          const direct = new OriginalTab({ title: 'Direct', details: jQuery(directPane) });
          return [
            seen,
            tab?.customMarker ?? null,
            tab === Drupal.verticalTabs.get(pane),
            direct.detailsElement === directPane,
            warnings.some((msg) => msg.includes('Drupal.verticalTab')),
          ];
        } finally {
          Drupal.verticalTab = OriginalTab;
          Drupal.deprecationError = warn;
        }
      })()
      JS);
    $this->assertSame([[TRUE], 'override-applied', TRUE, TRUE, TRUE], $actual);

    // Verify detached tabs created before late bridge load also expose data().
    $this->drupalGet('form_test/vertical-tabs-with-summary');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $scripts = json_encode(array_column(
      \Drupal::service('library.discovery')->getLibraryByName('core', 'jquery')['js'],
      'data',
    ));
    $session->executeScript(<<<JS
      (async () => {
        const detached = document.createElement('div');
        detached.innerHTML = '<div class="js-form-type-vertical-tabs"><div data-vertical-tabs-panes><input type="hidden" class="vertical-tabs__active-tab" value=""><details id="late-det"><summary>Late Detached</summary></details></div></div>';
        Drupal.behaviors.verticalTabs.attach(detached);
        const pane = detached.querySelector('#late-det');
        for (const path of $scripts) {
          await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = drupalSettings.path.baseUrl + path;
            script.onload = resolve;
            script.onerror = reject;
            document.head.append(script);
          });
        }
        window.lateDetachedTabResult = jQuery(pane).data('verticalTab') === Drupal.verticalTabs.get(pane);
      })();
      JS);
    $this->assertJsCondition('window.lateDetachedTabResult !== undefined');
    $this->assertTrue($session->evaluateScript('window.lateDetachedTabResult'));
  }

  /**
   * Tests a legacy theme override and tab management after dynamic attachment.
   */
  public function testLegacyVerticalTabTheme(): void {
    $this->drupalGet('form_test/vertical-tabs-with-summary', ['query' => ['legacy' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const warn = Drupal.deprecationError;
        Drupal.deprecationError = () => {};
        const oldTheme = Drupal.theme.verticalTab;
        const seen = [];
        Drupal.theme.verticalTab = (settings) => {
          const markup = oldTheme(settings);
          seen.push(settings.details instanceof jQuery && markup.item instanceof jQuery);
          markup.item.addClass('legacy-override');
          return markup;
        };
        try {
          const context = document.body.appendChild(document.createElement('div'));
          context.innerHTML = '<div class="js-form-type-vertical-tabs"><div data-vertical-tabs-panes><input type="hidden" class="vertical-tabs__active-tab" value="temp-second"><details id="temp-first"><summary>First</summary></details><details id="temp-second"><summary>Second</summary></details></div></div>';
          Drupal.behaviors.verticalTabs.attach(context);
          const selected = context.querySelector('.is-selected').textContent;
          const first = jQuery('#temp-first').data('verticalTab');
          const second = jQuery('#temp-second').data('verticalTab');
          const initial = first.details[0].id === 'temp-first' && second.item.hasClass('legacy-override');
          const chain = second.tabHide() === second;
          const switched = context.querySelector('.is-selected') === first.item[0];
          second.tabShow();
          return [seen, selected.includes('Second'), initial, chain, switched, second.details[0].getAttribute("open")];
        } finally {
          Drupal.theme.verticalTab = oldTheme;
          Drupal.deprecationError = warn;
        }
      })()
      JS);
    $this->assertSame([[TRUE, TRUE], TRUE, TRUE, TRUE, TRUE, 'true'], $actual);
  }

  /**
   * Tests that the Default Admin theme provides its vertical tab markup.
   */
  public function testDefaultAdminVerticalTabMarkup(): void {
    \Drupal::service('theme_installer')->install(['default_admin']);
    $this->config('system.theme')->set('default', 'default_admin')->save();
    $this->drupalGet('form_test/vertical-tabs-with-summary');
    $this->assertSession()->elementTextEquals('css', '.vertical-tabs__menu-item.first .vertical-tabs__menu-link-content .vertical-tabs__menu-item-title', 'Tab 1');
    $this->assertSession()->elementTextEquals('css', '.vertical-tabs__menu-item.last .vertical-tabs__menu-link-summary', 'Summary 2');
  }

}
