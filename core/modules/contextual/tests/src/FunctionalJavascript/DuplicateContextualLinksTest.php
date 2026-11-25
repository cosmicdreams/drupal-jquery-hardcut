<?php

declare(strict_types=1);

namespace Drupal\Tests\contextual\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that duplicate contextual links are initialized independently.
 */
#[Group('contextual')]
#[RunTestsInSeparateProcesses]
class DuplicateContextualLinksTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'contextual_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that duplicate contextual links each get their own model view.
   */
  public function testSameContextualLinks(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access contextual links',
    ]));

    // Ensure same contextual links work correctly with fresh and cached page.
    $contextual_id = '[data-contextual-id^="contextual_test"]';
    foreach (['fresh', 'cached'] as $state) {
      $this->drupalGet('contextual-tests/duplicate-links');
      $this->assertJsCondition("!document.querySelector('[data-contextual-id]:empty')");
      // Click each duplicate contextual trigger and verify only that region's
      // links open. If the cached path doesn't isolate duplicates, toggling
      // one opens both.
      foreach (['first', 'second'] as $id) {
        $other = $id === 'first' ? 'second' : 'first';
        $this->getSession()->executeScript("document.querySelector('#region-$id $contextual_id .trigger').click();");
        $this->assertNotNull($this->assertSession()->waitForElementVisible('css', "#region-$id $contextual_id .contextual-links"), "Contextual links in region-$id should open ($state page).");
        $this->assertFalse($this->getSession()->getPage()->find('css', "#region-$other $contextual_id .contextual-links")->isVisible(), "Contextual links in region-$other must NOT open when only region-$id was toggled ($state page).");
        // Close it again for the next iteration.
        $this->getSession()->executeScript("document.querySelector('#region-$id $contextual_id .trigger').click();");
        $this->assertSession()->waitForElementRemoved('css', "#region-$id $contextual_id .contextual.open");
      }
    }

    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const first = document.querySelector('#region-first .trigger');
      const second = document.querySelector('#region-second .trigger');
      first.dispatchEvent(new Event('touchend', { cancelable: true }));
      const model = Drupal.contextual.instances.find((item) => item.trigger === first);
      model.render = () => { window.otherContextualRendered = true; };
      second.dispatchEvent(new FocusEvent('focus'));
      JS);
    $this->assertJsCondition('document.querySelector("#region-first .trigger").getAttribute("aria-pressed") === "true" && !window.otherContextualRendered');
    $session->executeScript(<<<'JS'
      const region = document.getElementById('region-first');
      const placeholder = region.querySelector('[data-contextual-id]');
      Drupal.detachBehaviors(region, drupalSettings, 'unload');
      window.contextualUnloaded = !Drupal.contextual.instances.some((model) => model.element === placeholder);
      region.remove();
      JS);
    $this->assertJsCondition('window.contextualUnloaded');

    // Give the two nested regions the same top edge.
    $session->executeScript(<<<'JS'
      const outer = document.getElementById('region-second');
      const inner = document.createElement('div');
      inner.className = 'contextual-region';
      inner.style.cssText = 'position:absolute; inset:0';
      const placeholder = document.createElement('div');
      placeholder.dataset.contextualId = outer.querySelector('[data-contextual-id]').dataset.contextualId;
      inner.append(placeholder);
      outer.append(inner);
      Drupal.attachBehaviors(inner);
      JS);
    $this->assertJsCondition(<<<'JS'
      (() => {
        const [outer, inner] = document.querySelectorAll('#region-second .contextual');
        return inner && parseFloat(inner.style.top) > 0 &&
          inner.getBoundingClientRect().top > outer.getBoundingClientRect().top;
      })()
      JS);
  }

}
