<?php

declare(strict_types=1);

namespace Drupal\Tests\default_admin\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\media\Entity\Media;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the sticky administration header and sticky table headers.
 */
#[Group('default_admin')]
#[RunTestsInSeparateProcesses]
class StickyTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'default_admin';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block'];

  /**
   * Tests that a sticky table header sits below the displaced action bar.
   */
  public function testStickyHeaderDisplacement(): void {
    $this->drupalPlaceBlock('system_breadcrumb_block', ['region' => 'breadcrumb']);
    $this->drupalLogin($this->drupalCreateUser(['administer permissions']));
    $session = $this->getSession();
    $session->resizeWindow(1280, 600);
    $this->drupalGet('admin/people/permissions');
    $assert = $this->assertSession();
    $assert->elementExists('css', '.region-sticky');
    $this->assertNotNull($assert->waitForElementVisible('css', '.sticky-table-header'));
    $session->executeScript('window.scrollTo(0, document.querySelector(".sticky-table-header").getBoundingClientRect().top + 100);');
    $this->assertJsCondition(<<<'JS'
      (() => {
        const actions = document.querySelector('.region-sticky[data-offset-top]');
        const header = document.querySelector('.sticky-table-header');
        const offset = Drupal.displace.offsets.top;
        return actions?.classList.contains('region-sticky--is-sticky') &&
          offset > 0 &&
          Math.abs(actions.getBoundingClientRect().bottom - offset) <= 1 &&
          Math.abs(header.getBoundingClientRect().top - offset) <= 2;
      })()
      JS);
    $session->executeScript('window.scrollTo(0, 0);');
    $this->assertJsCondition('!document.querySelector(".region-sticky[data-offset-top]")');

    $session->executeScript(<<<'JS'
      const pair = document.createElement('div');
      pair.innerHTML = '<div class="region-sticky-watcher"></div><div class="region-sticky"></div>';
      document.body.append(pair);
      const OriginalObserver = window.IntersectionObserver;
      let callback;
      try {
        window.IntersectionObserver = class {
          constructor(onIntersection) {
            callback = onIntersection;
          }
          observe(element) {
            window.stickyMarkerIsObserved = element === pair.firstElementChild;
          }
          disconnect() {
            window.stickyMarkerIsDisconnected = true;
          }
        };
        Drupal.behaviors.adminSticky.attach(pair);
      } finally {
        window.IntersectionObserver = OriginalObserver;
      }
      callback([{ intersectionRatio: 0 }]);
      window.stickyPairMatches = pair.lastElementChild.hasAttribute('data-offset-top') &&
        document.querySelectorAll('.region-sticky[data-offset-top]').length === 1;
      Drupal.detachBehaviors(pair.firstElementChild, drupalSettings, 'unload');
      pair.firstElementChild.remove();
      pair.remove();
      JS);
    $this->assertJsCondition('window.stickyMarkerIsObserved && window.stickyPairMatches && window.stickyMarkerIsDisconnected');
  }

  /**
   * Tests the live selection count appears before the picker actions.
   */
  public function testMediaLibrarySelectionCount(): void {
    $this->container->get('module_installer')->install(['media_library_test']);
    Media::create([
      'bundle' => 'type_one',
      'name' => 'Example media',
      'field_media_test' => 'Example media',
    ])->save();

    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'create basic_page content',
      'view media',
    ]));
    $this->drupalGet('node/add/basic_page');
    $assert = $this->assertSession();
    $widget = $assert->elementExists('css', '#field_unlimited_media-media-library-wrapper');
    $widget->pressButton('Add media');

    $pane = '.media-library-widget-modal .drupal-dialog-buttonpane';
    $counter = "$pane > .js-media-library-selected-count:first-child";
    $this->assertNotNull($assert->waitForElementVisible('css', $counter));
    $assert->elementTextEquals('css', $counter, '0 items selected');

    $page = $this->getSession()->getPage();
    $page->checkField('Select Example media');
    $assert->elementTextEquals('css', $counter, '1 item selected');
    $assert->elementAttributeContains('css', $counter, 'aria-live', 'polite');
    $assert->buttonExists('Insert selected', $assert->elementExists('css', $pane));
  }

  /**
   * Tests closing the picker before the theme receives its selection count.
   */
  public function testMediaLibraryCounterObserverDisconnects(): void {
    $this->drupalGet('<front>');
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const script = document.createElement('script');
      script.src = Drupal.url('core/themes/default_admin/js/media-library.ui.js');
      document.head.append(script);
      JS);
    $this->assertJsCondition('!!Drupal.behaviors.mediaLibraryItemSelectionDefaultAdmin');
    $session->executeScript(<<<'JS'
      Drupal.behaviors.mediaLibraryItemSelectionDefaultAdmin.attach();
      const dialog = document.createElement('div');
      dialog.className = 'media-library-widget-modal';
      dialog.innerHTML = '<div class="drupal-dialog-content"></div><div class="drupal-dialog-buttonpane"></div>';
      const [content, pane] = dialog.children;
      document.body.append(dialog);
      const OriginalObserver = window.MutationObserver;
      let observed;
      let disconnected;
      try {
        window.MutationObserver = class {
          observe(element) {
            observed = element === pane;
          }
          disconnect() {
            disconnected = true;
          }
        };
        content.dispatchEvent(new Event('dialog:aftercreate', { bubbles: true }));
        content.dispatchEvent(new Event('dialog:beforeclose', { bubbles: true }));
      } finally {
        window.MutationObserver = OriginalObserver;
      }
      dialog.remove();
      window.mediaCountObserverReleased = observed && disconnected;
      JS);
    $this->assertJsCondition('window.mediaCountObserverReleased');
  }

}
