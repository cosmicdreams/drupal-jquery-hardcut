<?php

declare(strict_types=1);

namespace Drupal\Tests\block\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the JavaScript functionality of the block add filter.
 */
#[Group('block')]
#[RunTestsInSeparateProcesses]
class BlockFilterTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user', 'block', 'block_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
   * Tests block filter.
   */
  public function testBlockFilter(): void {
    $this->drupalGet('admin/structure/block');
    $assertSession = $this->assertSession();
    $session = $this->getSession();
    $page = $session->getPage();

    // Find the block filter field on the add-block dialog.
    $page->find('css', '#edit-blocks-region-header-title')->click();
    $filter = $assertSession->waitForElement('css', '.block-filter-text');

    // Get all block rows, for assertions later.
    $block_rows = $page->findAll('css', '.block-add-table tbody tr');

    // Test block filter reduces the number of visible rows.
    $filter->setValue('ad');
    $this->assertJsCondition("document.getElementById('drupal-live-announce')?.textContent.includes('blocks are available')");
    $visible_rows = $this->filterVisibleElements($block_rows);
    if (count($block_rows) > 0) {
      $this->assertNotSameSize($block_rows, $visible_rows);
    }

    // Test Drupal.announce() message when multiple matches are expected.
    $expected_message = count($visible_rows) . ' blocks are available in the modified list.';
    $this->assertAnnounceContains($expected_message);

    // Test Drupal.announce() message when only one match is expected.
    $filter->setValue('Powered by');
    $this->assertJsCondition("document.getElementById('drupal-live-announce')?.textContent.includes('block is available')");
    $visible_rows = $this->filterVisibleElements($block_rows);
    $this->assertCount(1, $visible_rows);
    $expected_message = '1 block is available in the modified list.';
    $this->assertAnnounceContains($expected_message);

    // Test Drupal.announce() message when no matches are expected.
    $filter->setValue('Pan-Galactic Gargle Blaster');
    $this->assertJsCondition("document.getElementById('drupal-live-announce')?.textContent.includes('0 blocks are available')");
    $visible_rows = $this->filterVisibleElements($block_rows);
    $this->assertCount(0, $visible_rows);
    $expected_message = '0 blocks are available in the modified list.';
    $this->assertAnnounceContains($expected_message);

    $session->executeScript(<<<'JS'
      const input = document.querySelector('input.block-filter-text');
      const table = document.querySelector(input.dataset.element);
      const copy = table.cloneNode(true);
      table.after(copy);
      once.remove('block-filter-text', input);
      // Reattach using a new input so both matches are present at setup time.
      const firstInput = input.cloneNode(true);
      const secondInput = input.cloneNode(true);
      secondInput.id = 'second-block-filter';
      input.replaceWith(firstInput, secondInput);
      Drupal.attachBehaviors();
      JS);
    $assertSession->elementExists('css', '.block-filter-text')->setValue('Powered by');
    $this->assertJsCondition(<<<'JS'
      (() => {
        const input = document.querySelector('input.block-filter-text');
        const tables = [...document.querySelectorAll(input.dataset.element)];
        return tables.length === 2 && tables.every((table) => {
          const rows = [...table.tBodies[0].rows].filter((row) => row.getClientRects().length);
          return rows.length === 1 && rows[0].textContent.includes('Powered by');
        });
      })()
      JS);

    $assertSession->elementExists('css', '#second-block-filter')->setValue('Pan-Galactic Gargle Blaster');
    $this->assertJsCondition(<<<'JS'
      (() => {
        const input = document.getElementById('second-block-filter');
        const tables = [...document.querySelectorAll(input.dataset.element)];
        return tables.length === 2 && tables.every((table) =>
          [...table.tBodies[0].rows].every((row) => !row.getClientRects().length)
        );
      })()
      JS);

    $this->placeBlock('test_xss');
    $this->failOnJavascriptConsoleErrors = FALSE;
    $this->drupalGet('admin/structure/block');

    // If the XSS variable exists, it means the payload has been executed.
    $this->assertJsCondition("typeof window.XSS === 'undefined'");
  }

  /**
   * Removes any non-visible elements from the passed array.
   *
   * @param \Behat\Mink\Element\NodeElement[] $elements
   *   An array of node elements.
   *
   * @return \Behat\Mink\Element\NodeElement[]
   *   An array of visible elements.
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
