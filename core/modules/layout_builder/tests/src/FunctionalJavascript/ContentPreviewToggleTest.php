<?php

declare(strict_types=1);

namespace Drupal\Tests\layout_builder\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\SortableTestTrait;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\contextual\FunctionalJavascript\ContextualLinkClickTrait;
use Drupal\Tests\system\Traits\OffCanvasTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore blocknodebundle testbody
/**
 * Tests toggling of content preview.
 */
#[Group('layout_builder')]
#[RunTestsInSeparateProcesses]
class ContentPreviewToggleTest extends WebDriverTestBase {

  use ContextualLinkClickTrait;
  use OffCanvasTestTrait;
  use SortableTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'layout_builder',
    'block',
    'node',
    'contextual',
    'off_canvas_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createContentType(['type' => 'bundle_for_this_particular_test']);
    LayoutBuilderEntityViewDisplay::load('node.bundle_for_this_particular_test.default')
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'access contextual links',
    ]));
  }

  /**
   * Tests the content preview toggle.
   */
  public function testContentPreviewToggle(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $links_field_placeholder_label = '"Links" field';
    $body_field_placeholder_label = '"Body" field';
    $content_preview_body_text = 'I should only be visible if content preview is enabled.';

    $this->createNode([
      'type' => 'bundle_for_this_particular_test',
      'body' => [
        [
          'value' => $content_preview_body_text,
        ],
      ],
    ]);

    // Open single item layout page.
    $this->drupalGet('node/1/layout');

    // Placeholder label should not be visible, preview content should be.
    $assert_session->elementNotExists('css', '.layout-builder-block__content-preview-placeholder-label');
    $assert_session->pageTextContains($content_preview_body_text);

    // Disable content preview, confirm presence of placeholder labels.
    $this->assertTrue($page->hasCheckedField('layout-builder-content-preview'));
    $page->uncheckField('layout-builder-content-preview');
    $this->assertNotEmpty($assert_session->waitForElementVisible('css', '.layout-builder-block__content-preview-placeholder-label'));

    // Confirm that block content is not on page.
    $assert_session->pageTextNotContains($content_preview_body_text);
    $this->assertContextualLinks();

    // Check that content preview is still disabled on page reload.
    $this->getSession()->reload();
    $this->assertNotEmpty($assert_session->waitForElement('css', '.layout-builder-block__content-preview-placeholder-label'));
    $assert_session->pageTextNotContains($content_preview_body_text);
    $this->assertContextualLinks();

    // Confirm repositioning blocks works with content preview disabled.
    $this->assertOrderInPage([$links_field_placeholder_label, $body_field_placeholder_label]);

    $links_block = "[data-layout-content-preview-placeholder-label='$links_field_placeholder_label']";
    $body_block = "[data-layout-content-preview-placeholder-label='$body_field_placeholder_label']";

    $assert_session->elementExists('css', $links_block . " div");
    $assert_session->elementExists('css', $body_block . " div");

    $this->getSession()->executeScript(<<<'JS'
      window.previousLayoutRegion = document.querySelector('.layout__region--content');
      const sortable = Sortable.get(window.previousLayoutRegion);
      Drupal.behaviors.layoutBuilderBlockDrag.attach(document.getElementById('layout-builder'));
      window.layoutSortableRetained = !!sortable && Sortable.get(window.previousLayoutRegion) === sortable;
      Drupal.detachBehaviors(window.previousLayoutRegion, drupalSettings, 'unload');
      window.layoutSortableReleased = !Sortable.get(window.previousLayoutRegion);
      Drupal.attachBehaviors(document.getElementById('layout-builder'));
      JS);
    $this->assertJsCondition('window.layoutSortableRetained && window.layoutSortableReleased');
    $this->dragSortableItem(
      $assert_session->elementExists('css', $links_block),
      $assert_session->elementExists('css', $body_block),
    );
    $assert_session->assertWaitOnAjaxRequest();

    $this->assertJsCondition('!window.previousLayoutRegion.isConnected && !Sortable.get(window.previousLayoutRegion)');

    // Check that the drag-triggered rebuild did not trigger content preview.
    $assert_session->pageTextNotContains($content_preview_body_text);

    // Check that drag successfully repositioned blocks.
    $this->assertOrderInPage([$body_field_placeholder_label, $links_field_placeholder_label]);

    $this->getSession()->reload();
    $this->assertOrderInPage([$body_field_placeholder_label, $links_field_placeholder_label]);

    // The block itself can be the root of an Ajax behavior attachment.
    $this->getSession()->executeScript(<<<'JS'
      const block = document.querySelector('[data-layout-content-preview-placeholder-label]');
      block.querySelector('.js-layout-builder-content-preview-placeholder-label').remove();
      Drupal.attachBehaviors(block);
      window.reattachedBlock = block;
      const child = document.createElement('div');
      child.id = 'preview-child';
      child.textContent = 'Preview child';
      const style = document.createElement('style');
      style.textContent = '#preview-child { display: none; }';
      document.head.append(style);
      block.append(child);
      JS);
    $this->assertJsCondition('!!window.reattachedBlock.querySelector(":scope > .js-layout-builder-content-preview-placeholder-label")');
    $this->assertFalse($assert_session->elementExists('css', '#preview-child')->isVisible());

    // Check if block position maintained after enabling content preview.
    $this->assertTrue($page->hasUncheckedField('layout-builder-content-preview'));
    $page->checkField('layout-builder-content-preview');
    $this->assertTrue($assert_session->elementExists('css', '#preview-child')->isVisible());
    $this->assertNotEmpty($assert_session->waitForText($content_preview_body_text));
    $assert_session->pageTextContains($content_preview_body_text);
    $this->assertNotEmpty($assert_session->waitForText('Placeholder for the "Links" field'));
    $this->assertOrderInPage([$content_preview_body_text, 'Placeholder for the "Links" field']);

    $page->pressButton('Save layout');
    $this->assertTrue($assert_session->waitForText('The layout override has been saved.'));
    $this->drupalGet('node/1/layout');
    $this->assertOrderInPage([$content_preview_body_text, 'Placeholder for the "Links" field']);
  }

  /**
   * Tests that block labels are properly escaped in preview.
   */
  public function testBlockLabelXss(): void {
    $page = $this->getSession()->getPage();
    $node = $this->createNode([
      'type' => 'bundle_for_this_particular_test',
    ]);

    $section = new Section('layout_onecol');
    $component = new SectionComponent(
      \Drupal::service('uuid')->generate(),
      'content',
      [
        'id' => 'user_login_block',
        'label' => '<img src="x" onerror="document.querySelector(\'body\').innerHTML = \'XSS has been triggered\';">',
      ]
    );
    $section->appendComponent($component);
    $node->get(OverridesSectionStorage::FIELD_NAME)->appendSection($section);
    $node->save();

    $this->drupalGet('node/1/layout');
    $page->uncheckField('layout-builder-content-preview');
    $this->assertNotEmpty($this->assertSession()->waitForElementVisible('css', '.layout-builder-block__content-preview-placeholder-label'));
    // Add a micro-sleep here to give the browser a moment to try
    // trigger the XSS.
    usleep(100000);

    // If this is still present after the preview has been enabled, then
    // the XSS has not been triggered.
    $this->assertSession()->elementExists('css', '.layout-builder-block__content-preview-placeholder-label');

  }

  /**
   * Checks if contextual links are working properly.
   *
   * @internal
   */
  protected function assertContextualLinks(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->clickContextualLink('.block-field-blocknodebundle-for-this-particular-testbody', 'Configure');
    $this->waitForOffCanvasArea();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertNotEmpty($this->assertSession()->waitForButton('Close'));
    $page->pressButton('Close');
    $assert_session->assertNoElementAfterWait('css', '#drupal-off-canvas');
  }

  /**
   * Asserts that blocks in a given order in the page.
   *
   * @param string[] $items
   *   An ordered list of strings that should appear in the blocks.
   *
   * @internal
   */
  protected function assertOrderInPage(array $items): void {
    $session = $this->getSession();
    $page = $session->getPage();
    $blocks = $page->findAll('css', '[data-layout-content-preview-placeholder-label]');

    // Filter will only return value if block contains expected text.
    $blocks_with_expected_text = array_filter($blocks, function ($block, $key) use ($items): bool {
      $block_text = $block->getText();
      return str_contains($block_text, $items[$key]);
    }, ARRAY_FILTER_USE_BOTH);

    $this->assertSameSize($items, $blocks_with_expected_text);
  }

}
