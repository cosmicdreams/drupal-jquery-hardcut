<?php

declare(strict_types=1);

namespace Drupal\Tests\default_admin\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
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
  }

}
