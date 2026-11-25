<?php

declare(strict_types=1);

namespace Drupal\Tests\block\FunctionalJavascript;

use Drupal\block\Entity\Block;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests drag and drop blocks on block layout page.
 */
#[Group('block')]
#[RunTestsInSeparateProcesses]
class BlockDragTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user', 'block', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'default_admin';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $admin_user = $this->drupalCreateUser([
      'administer blocks',
    ]);
    $this->drupalLogin($admin_user);
  }

  /**
   * Tests drag and drop blocks.
   */
  public function testDragAndDropBlocks(): void {
    // Resize window to work around https://github.com/bitovi/syn/issues/164.
    $this->getSession()->resizeWindow(1024, 2048);
    $this->drupalGet('admin/structure/block');
    $assertSession = $this->assertSession();
    $session = $this->getSession();
    $page = $session->getPage();

    // Test if drag orientation on block layout page was applied with success.
    $this->assertNotEmpty($assertSession->waitForElementVisible('css', '.tabledrag-handle-y'));

    // Dragging Secondary tabs and Status messages to header region.
    $primaryTabs = $this->getDragRow($page, 'edit-blocks-default-admin-primary-local-tasks');

    $secondaryTabs = $this->getDragRow($page, 'edit-blocks-default-admin-secondary-local-tasks');
    $secondaryTabs->dragTo($primaryTabs);
    $messages = $this->getDragRow($page, 'edit-blocks-default-admin-messages');
    $messages->dragTo($primaryTabs);

    // Test if both blocks above were positioned on the header region.
    $this->assertEquals(
      'header',
      $page->findField('edit-blocks-default-admin-secondary-local-tasks-region')->getValue(),
      'Main menu should be positioned on header region'
    );
    $this->assertEquals(
      'header',
      $page->findField('edit-blocks-default-admin-messages-region')->getValue(),
      'Status messages should be positioned on header region'
    );

    // Check if the message unsaved changed appears.
    $assertSession->pageTextContains('You have unsaved changes.');

    // Test if the message for empty regions appear after drag the unique block
    // on the region.
    $noBlockMessage = $page->find('css', 'tr[data-drupal-selector="edit-blocks-region-pre-content-message"] td')->getText();
    $this->assertSession()->assert($noBlockMessage === 'No blocks in this region', 'Region pre-content should be empty.');

    // Testing drag row to an empty region.
    $pageTitle = $this->getDragRow($page, 'edit-blocks-default-admin-page-title');
    $heroRegion = $page->find('css', 'tr[data-drupal-selector="edit-blocks-region-help-message"]');
    $pageTitle->dragTo($heroRegion);
    $this->assertSession()->assert(
      $page->find('css', 'tr[data-drupal-selector="edit-blocks-region-help-message"] td')->getText() !== 'No blocks in this region',
      "Region help shouldn't be empty"
    );

  }

  /**
   * Tests moving a block with its region select on a fresh page load.
   */
  public function testRegionSelectMovesBlock(): void {
    $this->drupalPlaceBlock('system_powered_by_block', [
      'id' => 'region_select_test',
      'region' => 'content',
      'weight' => 0,
    ]);
    $this->drupalPlaceBlock('system_powered_by_block', [
      'id' => 'existing_help_block',
      'region' => 'help',
    ]);
    $this->drupalGet('admin/structure/block');
    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();
    $row = 'tr[data-drupal-selector="edit-blocks-region-select-test"]';
    $destination = '.region-help-message ~ tr[data-drupal-selector="edit-blocks-existing-help-block"] + ' . $row;
    $expected_weight = -round(count($page->findAll('css', '#blocks tr.draggable')) / 2) + 2;

    $assert->elementExists('css', "$row .block-region-content")->selectOption('help');

    $assert->elementExists('css', "$destination + .region-title");
    $assert->elementExists('css', '.region-help-message.region-populated');
    $assert->elementExists('css', "$row .block-region-help");
    $weight = $assert->elementExists('css', "$row .block-weight-help");
    $this->assertSame((string) $expected_weight, $weight->getValue());
    $this->assertNotNull($assert->waitForElementVisible('css', '.tabledrag-changed-warning'));
    $assert->elementTextContains('css', '.tabledrag-changed-warning', 'You have unsaved changes.');

    $page->pressButton('Save blocks');
    $assert->elementExists('css', $destination);
    $assert->fieldValueEquals('blocks[region_select_test][region]', 'help');
    $this->assertSame('help', Block::load('region_select_test')->getRegion());
  }

  /**
   * Helper function to find block tr element on the page.
   */
  private function getDragRow($page, string $blockId) {
    return $page->find('css', '#blocks tbody tr[data-drupal-selector="' . $blockId . '"] a.tabledrag-handle');
  }

}
