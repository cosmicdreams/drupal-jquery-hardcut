<?php

declare(strict_types=1);

namespace Drupal\Tests\system\FunctionalJavascript\Form;

use Drupal\FunctionalJavascriptTests\JqueryCallCounterTrait;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that titles and summaries in vertical-tabs form elements are set correctly.
 */
#[Group('Form')]
#[RunTestsInSeparateProcesses]
class ElementsVerticalTabsWithSummaryTest extends WebDriverTestBase {

  use JqueryCallCounterTrait;

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
   * Tests that tab data removed before the first read stays removed.
   */
  public function testRemovedTabDataStaysRemoved(): void {
    $this->drupalGet('form_test/vertical-tabs-with-summary', ['query' => ['legacy' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const detached = document.createElement('div');
        detached.innerHTML = '<div class="js-form-type-vertical-tabs"><div data-vertical-tabs-panes><input type="hidden" class="vertical-tabs__active-tab" value=""><details id="removed-first"><summary>First</summary></details><details id="removed-second"><summary>Second</summary></details></div></div>';
        Drupal.behaviors.verticalTabs.attach(detached);
        const first = detached.querySelector('#removed-first');
        const second = detached.querySelector('#removed-second');
        jQuery(first).removeData('verticalTab');
        jQuery.removeData(second, 'verticalTab');
        return [
          jQuery(first).data('verticalTab') === undefined,
          jQuery.data(second, 'verticalTab') === undefined,
          Boolean(Drupal.verticalTabs.get(first) && Drupal.verticalTabs.get(second)),
        ];
      })()
      JS);
    $this->assertSame([TRUE, TRUE, TRUE], $actual);
  }

  /**
   * Tests that lazy tab data follows jQuery's key normalization.
   *
   * Before the first canonical read, the dashed key reads the tab, removing
   * it keeps it removed, and jQuery.hasData() reports the tab's data.
   */
  public function testTabDataNormalizesKeys(): void {
    $this->drupalGet('form_test/vertical-tabs-with-summary', ['query' => ['legacy' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const detached = document.createElement('div');
        const panes = ['read', 'static-read', 'remove', 'static-remove', 'has'];
        detached.innerHTML = `<div class="js-form-type-vertical-tabs"><div data-vertical-tabs-panes><input type="hidden" class="vertical-tabs__active-tab" value="">${panes.map((id) => `<details id="alias-${id}"><summary>${id}</summary></details>`).join('')}</div></div>`;
        Drupal.behaviors.verticalTabs.attach(detached);
        const [read, staticRead, remove, staticRemove, has] = panes.map((id) => detached.querySelector(`#alias-${id}`));
        const dashed = jQuery(read).data('vertical-tab');
        const staticDashed = jQuery.data(staticRead, 'vertical-tab');
        jQuery(remove).removeData('vertical-tab');
        jQuery.removeData(staticRemove, ['vertical-tab']);
        return [
          dashed !== undefined && dashed === jQuery(read).data('verticalTab'),
          staticDashed !== undefined && staticDashed === jQuery.data(staticRead, 'verticalTab'),
          jQuery(remove).data('verticalTab') === undefined,
          jQuery.data(staticRemove, 'verticalTab') === undefined,
          jQuery.hasData(has),
          jQuery(has).data('verticalTab') !== undefined,
        ];
      })()
      JS);
    $this->assertSame([TRUE, TRUE, TRUE, TRUE, TRUE, TRUE], $actual);
  }

  /**
   * Tests that constructor overrides keep the legacy settings consistent.
   *
   * A chaining override emits no #details deprecation, and an override that
   * swaps settings.details renders and stores the swapped pane.
   */
  public function testVerticalTabOverrideSettings(): void {
    $this->drupalGet('form_test/vertical-tabs-with-summary', ['query' => ['legacy' => 1]]);
    $actual = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const warn = Drupal.deprecationError;
        const warnings = [];
        Drupal.deprecationError = ({ message }) => warnings.push(message);
        const Original = Drupal.verticalTab;
        const oldTheme = Drupal.theme.verticalTab;
        const attach = (id) => {
          const context = document.body.appendChild(document.createElement('div'));
          context.innerHTML = `<div class="js-form-type-vertical-tabs"><div data-vertical-tabs-panes><input type="hidden" class="vertical-tabs__active-tab" value=""><details id="${id}"><summary>${id}</summary></details></div></div>`;
          Drupal.behaviors.verticalTabs.attach(context);
          return Drupal.verticalTabs.get(context.querySelector(`#${id}`));
        };
        const describe = (tab, pane) => [
          tab.detailsElement === pane,
          tab.linkElement.getAttribute('href'),
        ];
        try {
          const seen = [];
          function Chaining(settings) {
            seen.push(settings.details instanceof jQuery && settings.details[0] === settings.detailsElement);
            Original.call(this, settings);
          }
          Chaining.prototype = Original.prototype;
          Drupal.verticalTab = Chaining;
          const chained = attach('chain-pane');
          const chain = [seen, describe(chained, document.getElementById('chain-pane'))];

          const swapped = [];
          const themed = [];
          Drupal.theme.verticalTab = (settings) => {
            themed.push(settings.details[0].id);
            return oldTheme(settings);
          };
          for (const mode of ['assign', 'spread']) {
            const replacement = document.createElement('details');
            replacement.id = `swap-${mode}`;
            function Swapping(settings) {
              if (mode === 'assign') {
                settings.details = jQuery(replacement);
                Original.call(this, settings);
              }
              else {
                Original.call(this, { ...settings, details: jQuery(replacement) });
              }
            }
            Swapping.prototype = Original.prototype;
            Drupal.verticalTab = Swapping;
            swapped.push(describe(attach(`source-${mode}`), replacement));
          }
          return [
            chain,
            swapped,
            themed,
            warnings.filter((message) => message.includes('#details')),
          ];
        }
        finally {
          Drupal.verticalTab = Original;
          Drupal.theme.verticalTab = oldTheme;
          Drupal.deprecationError = warn;
        }
      })()
      JS);
    $this->assertSame([
      [[TRUE], [TRUE, '#chain-pane']],
      [[TRUE, '#swap-assign'], [TRUE, '#swap-spread']],
      ['swap-assign', 'swap-spread'],
      [],
    ], $actual);
  }

  /**
   * Tests a constructor override set before the form adapter loads.
   */
  public function testPreAdapterVerticalTabOverride(): void {
    $this->drupalGet('form_test/vertical-tabs-with-summary');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $scripts = json_encode(array_column(
      \Drupal::service('library.discovery')->getLibraryByName('core', 'jquery')['js'],
      'data',
    ));
    $session->executeScript(<<<JS
      (async () => {
        const Native = Drupal.verticalTab;
        const seen = [];
        function Early(settings) {
          seen.push(settings.details instanceof window.jQuery);
          Native.call(this, settings);
          this.early = true;
        }
        Early.prototype = Native.prototype;
        Drupal.verticalTab = Early;
        for (const path of $scripts) {
          await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = drupalSettings.path.baseUrl + path;
            script.onload = resolve;
            script.onerror = reject;
            document.head.append(script);
          });
        }
        const warnings = [];
        const warn = Drupal.deprecationError;
        Drupal.deprecationError = ({ message }) => warnings.push(message);
        try {
          const context = document.createElement('div');
          context.innerHTML = '<div class="js-form-type-vertical-tabs"><div data-vertical-tabs-panes><input type="hidden" class="vertical-tabs__active-tab" value=""><details id="early-pane"><summary>Early</summary></details></div></div>';
          Drupal.behaviors.verticalTabs.attach(context);
          const pane = context.querySelector('#early-pane');
          const tab = Drupal.verticalTabs.get(pane);
          window.preAdapterOverrideResult = [
            seen,
            tab.early === true,
            tab.detailsElement === pane,
            jQuery(pane).data('verticalTab') === tab,
            warnings.filter((message) => message.includes('#details')),
          ];
        }
        finally {
          Drupal.deprecationError = warn;
        }
      })();
      JS);
    $this->assertJsCondition('Array.isArray(window.preAdapterOverrideResult)');
    $this->assertSame([[TRUE], TRUE, TRUE, TRUE, []], $session->evaluateScript('window.preAdapterOverrideResult'));
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

  /**
   * Tests the two core summaryUpdated bindings before a later contrib one.
   *
   * The details summary and the vertical tab summary both update before a
   * contrib handler bound later, whether jQuery loads before or after.
   */
  public function testSummaryBindingsPrecedeLaterContrib(): void {
    foreach (['early' => ['legacy' => 1], 'late' => []] as $mode => $query) {
      $this->drupalGet('form_test/vertical-tabs-with-summary', ['query' => $query]);
      $session = $this->getSession();
      $late = json_encode($query ? [] : array_column(
        \Drupal::service('library.discovery')->getLibraryByName('core', 'jquery')['js'],
        'data',
      ));
      $session->executeScript(<<<JS
        (async () => {
          const context = document.body.appendChild(document.createElement('div'));
          context.innerHTML = '<div class="js-form-type-vertical-tabs"><div data-vertical-tabs-panes><input type="hidden" class="vertical-tabs__active-tab" value="pair-first"><details id="pair-first"><summary>First</summary><input name="pair"></details><details id="pair-second"><summary>Second</summary></details></div></div>';
          Drupal.attachBehaviors(context);
          for (const path of $late) {
            await new Promise((resolve, reject) => {
              const script = document.createElement('script');
              script.src = drupalSettings.path.baseUrl + path;
              script.onload = resolve;
              script.onerror = reject;
              document.head.append(script);
            });
          }
          const details = document.getElementById('pair-first');
          const order = [];
          jQuery(details).on('summaryUpdated.pair', () => {
            order.push([
              details.querySelector('summary .summary')?.textContent ?? null,
              context.querySelector('.vertical-tabs__menu-item-summary').textContent,
            ]);
          });
          Drupal.form.setSummary(details, () => 'Pair summary');
          jQuery(details).off('.pair');
          window.summaryPairResult = order;
        })();
        JS);
      $this->assertJsCondition('Array.isArray(window.summaryPairResult)');
      $this->assertSame([[' (Pair summary)', 'Pair summary']], $session->evaluateScript('window.summaryPairResult'), "Loading mode: $mode.");
    }
  }

  /**
   * Tests that a late jQuery load on a vertical tabs page makes no calls.
   *
   * Plan section 6.4: loading the bridges after core attached the tabs,
   * switching tabs and updating a summary make no jQuery calls when nothing
   * uses jQuery. Counting starts before the bridges load.
   */
  public function testLateLoadMakesNoJqueryCalls(): void {
    $this->drupalGet('form_test/vertical-tabs-with-summary');
    $session = $this->getSession();
    $this->assertSame('undefined', $session->evaluateScript('typeof window.jQuery'));
    $session->executeScript($this->jqueryCallCounter());
    $scripts = array_column(
      \Drupal::service('library.discovery')->getLibraryByName('core', 'jquery')['js'],
      'data',
    );
    $vendor = json_encode(array_slice($scripts, 0, 1));
    $bridges = json_encode(array_slice($scripts, 1));
    $session->executeScript(<<<JS
      (async () => {
        const load = async (paths) => {
          for (const path of paths) {
            await new Promise((resolve, reject) => {
              const script = document.createElement('script');
              script.src = drupalSettings.path.baseUrl + path;
              script.onload = resolve;
              script.onerror = reject;
              document.head.append(script);
            });
          }
        };
        await load($vendor);
        jqueryCalls.start();
        await load($bridges);
        window.lateScriptsLoaded = true;
      })();
      JS);
    $this->assertJsCondition('window.lateScriptsLoaded === true');
    $page = $session->getPage();
    $page->find('css', '.vertical-tabs__menu-item.last a')->click();
    $this->assertJsCondition("document.getElementById('edit-tab2').open");
    $page->fillField('Field 2', 'Typed');
    $session->executeScript(<<<'JS'
      Drupal.form.setSummary(document.getElementById('edit-tab2'), () => 'Late summary');
      JS);
    $this->assertSession()->elementTextEquals('css', '.vertical-tabs__menu-item.last .vertical-tabs__menu-item-summary', 'Late summary');
    $this->assertNoJqueryCalls($session->evaluateScript('jqueryCalls.stop()'));
  }

}
