<?php

declare(strict_types=1);

namespace Drupal\Tests\system\FunctionalJavascript\Pager;

use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests pager functionality in a modal.
 */
#[Group('Pager')]
#[RunTestsInSeparateProcesses]
class PagerModalTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dblog', 'pager_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Insert 300 log messages.
    $logger = $this->container->get('logger.factory')->get('pager_test');
    for ($i = 0; $i < 300; $i++) {
      $logger->debug($this->randomString());
    }
  }

  /**
   * Tests pagers work inside of modals.
   */
  public function testPagerInsideModal(): void {
    $this->drupalGet(Url::fromRoute('pager_test.modal_pager'));

    $this->clickLink('Open modal');
    $this->assertSession()->waitForElementVisible('css', '.pager-test-modal');

    $this->assertSession()->responseContains('Pagers in modal');
    $this->assertSession()->elementExists('css', '.test-pager-0')->clickLink('Go to page 2');
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Include the visually hidden "Page" label in the active item's text.
    $current_page = $this->getSession()->evaluateScript(<<<'JS'
      document.querySelector('#drupal-modal .test-pager-0 .pager__item.is-active a')
        ?.textContent.trim().replace(/\s+/g, ' ')
      JS);
    $this->assertSame('Page 2', $current_page);
    // Ensure we're still in the modal.
    $this->assertTrue($this->assertSession()->elementExists('css', '#drupal-modal')->isVisible());
  }

}
