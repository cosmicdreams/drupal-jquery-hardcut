<?php

declare(strict_types=1);

namespace Drupal\Tests\layout_builder\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the JavaScript functionality of the block add filter.
 */
#[Group('layout_builder')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
class BlockFilterTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'node',
    'datetime',
    'layout_builder',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
    ]));
    $this->createContentType(['type' => 'bundle_with_section_field']);
    LayoutBuilderEntityViewDisplay::load('node.bundle_with_section_field.default')
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();
    $this->createNode(['type' => 'bundle_with_section_field']);
  }

  /**
   * Tests block filter.
   */
  public function testBlockFilter(): void {
    $assert_session = $this->assertSession();
    $session = $this->getSession();
    $page = $session->getPage();

    // Open the block listing.
    $this->drupalGet('node/1/layout');
    $assert_session->linkExists('Add block');
    $this->clickLink('Add block');
    $assert_session->assertWaitOnAjaxRequest();

    // Get all blocks, for assertions later.
    $blocks = $page->findAll('css', '.js-layout-builder-categories li');
    $categories = $page->findAll('css', '.js-layout-builder-category');

    $filter = $assert_session->elementExists('css', '.js-layout-builder-filter');

    // Set announce to ensure it is not cleared.
    $init_message = 'init message';
    $session->evaluateScript("Drupal.announce('$init_message')");
    // Test block filter does not take effect for 1 character.
    $filter->setValue('a');
    $this->assertAnnounceContains($init_message);
    $visible_rows = $this->filterVisibleElements($blocks);
    $this->assertSameSize($blocks, $visible_rows);

    // Get the Content Fields category, which will be closed before filtering.
    $contentFieldsCategory = $page->find('named', ['content', 'Content fields']);
    // Link that belongs to the Content Fields category, to verify collapse.
    $promotedLink = $page->find('named', ['content', 'Promoted']);
    // Test that promoted link is visible until Content Fields collapsed.
    $this->assertTrue($promotedLink->isVisible());
    $contentFieldsCategory->click();
    $this->assertFalse($promotedLink->isVisible());

    // Filtering searches even a category the user closed.
    $filter->setValue('Promoted');
    $this->assertAnnounceContains('1 block is available in the modified list.');
    $this->assertTrue($promotedLink->isVisible());
    $this->assertCount(1, $this->filterVisibleElements($blocks));
    $this->assertCount(1, $this->filterVisibleElements($categories));

    // A new query replaces the previous result.
    $filter->setValue('Powered by');
    $this->assertNotNull($assert_session->waitForElementVisible('named', ['link', 'Powered by Drupal']));
    $this->assertFalse($promotedLink->isVisible());
    $this->assertCount(1, $this->filterVisibleElements($blocks));
    $this->assertCount(1, $this->filterVisibleElements($categories));

    $filter->setValue('Pan-Galactic Gargle Blaster');
    $this->assertAnnounceContains('0 blocks are available in the modified list.');
    $this->assertCount(0, $this->filterVisibleElements($blocks));
    $this->assertCount(0, $this->filterVisibleElements($categories));

    // Test Drupal.announce() message when all blocks are listed.
    $filter->setValue('');
    $this->assertAnnounceContains('All available blocks are listed.');
    // Confirm the Content Fields category remains collapsed after filtering.
    $this->assertFalse($promotedLink->isVisible());

    // A new chooser starts unfiltered even if the previous one was filtered.
    $filter->setValue('Powered by');
    $this->assertAnnounceContains('1 block is available in the modified list.');
    $session->executeScript('Drupal.dialog.get(document.getElementById("drupal-off-canvas")).close();');
    $this->clickLink('Add block');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementExists('css', '.js-layout-builder-filter')->setValue('a');
    $session->executeScript('setTimeout(() => { window.shortBlockQuerySettled = true; }, 500);');
    $this->assertJsCondition('window.shortBlockQuerySettled && !document.getElementById("drupal-live-announce")?.textContent.includes("All available blocks are listed.")');
  }

  /**
   * Removes any non-visible elements from the passed array.
   *
   * @param \Behat\Mink\Element\NodeElement[] $elements
   *   An array of node elements.
   *
   * @return \Behat\Mink\Element\NodeElement[]
   *   An array of visible node elements.
   */
  protected function filterVisibleElements(array $elements): array {
    return array_filter($elements, function (NodeElement $element) {
      return $element->isVisible();
    });
  }

  /**
   * Checks for inclusion of text in #drupal-live-announce.
   *
   * @param string $expected_message
   *   The text expected to be present in #drupal-live-announce.
   *
   * @internal
   */
  protected function assertAnnounceContains(string $expected_message): void {
    $assert_session = $this->assertSession();
    $this->assertNotEmpty($assert_session->waitForElement('css', "#drupal-live-announce:contains('$expected_message')"));
  }

}
