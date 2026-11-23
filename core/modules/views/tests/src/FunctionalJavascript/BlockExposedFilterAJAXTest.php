<?php

declare(strict_types=1);

namespace Drupal\Tests\views\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\views\Tests\ViewTestData;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the exposed filter ajax functionality in a block.
 */
#[Group('views')]
#[RunTestsInSeparateProcesses]
class BlockExposedFilterAJAXTest extends WebDriverTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'views', 'block', 'views_test_config'];

  /**
   * The views to use for testing.
   *
   * @var string[]
   */
  public static $testViews = ['test_block_exposed_ajax', 'test_block_exposed_ajax_with_page'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ViewTestData::createTestViews(self::class, ['views_test_config']);
    $this->createContentType(['type' => 'page']);
    $this->createContentType(['type' => 'article']);
    $this->createNode(['title' => 'Page A']);
    $this->createNode(['title' => 'Page B']);
    $this->createNode(['title' => 'Article A', 'type' => 'article']);

    $this->drupalLogin($this->drupalCreateUser([
      'access content',
    ]));
  }

  /**
   * Tests if exposed filtering and reset works with a views block and ajax.
   */
  public function testExposedFilteringAndReset(): void {
    $node = $this->createNode();
    $block = $this->drupalPlaceBlock('views_block:test_block_exposed_ajax-block_1');
    $this->drupalGet($node->toUrl());

    $page = $this->getSession()->getPage();

    // Ensure that the Content we're testing for is present.
    $html = $page->getHtml();
    $this->assertStringContainsString('Page A', $html);
    $this->assertStringContainsString('Page B', $html);
    $this->assertStringContainsString('Article A', $html);

    // Filter by page type.
    $this->submitForm(['type' => 'page'], 'Apply');
    $this->assertSession()->waitForElementRemoved('xpath', '//*[text()="Article A"]');

    // Verify that only the page nodes are present.
    $html = $page->getHtml();
    $this->assertStringContainsString('Page A', $html);
    $this->assertStringContainsString('Page B', $html);
    $this->assertStringNotContainsString('Article A', $html);

    // Reset the form.
    $this->submitForm([], 'Reset');
    // Assert we are still on the node page.
    $html = $page->getHtml();
    // Repeat the original tests.
    $this->assertStringContainsString('Page A', $html);
    $this->assertStringContainsString('Page B', $html);
    $this->assertStringContainsString('Article A', $html);
    $this->assertSession()->addressEquals('node/' . $node->id());

    $block->delete();
    // Do the same test with a block that has a page display to test the user
    // is redirected to the page display.
    $this->drupalPlaceBlock('views_block:test_block_exposed_ajax_with_page-block_1');
    $this->drupalGet($node->toUrl());
    $this->submitForm(['type' => 'page'], 'Apply');
    $this->assertSession()->waitForElementRemoved('xpath', '//*[text()="Article A"]');
    $this->submitForm([], 'Reset');
    $this->assertSession()->addressEquals('some-path');
  }

  /**
   * Tests that a refresh restores focus inside the submitted view.
   */
  public function testFocusWithMultipleExposedForms(): void {
    $node = $this->createNode();
    $this->drupalPlaceBlock('views_block:test_block_exposed_ajax-block_1', ['id' => 'first_view', 'weight' => -1]);
    $this->drupalPlaceBlock('views_block:test_block_exposed_ajax-block_1', ['id' => 'second_view', 'weight' => 1]);
    $this->drupalGet($node->toUrl());

    $session = $this->getSession();
    $assert = $this->assertSession();
    $second = $assert->elementExists('css', '#block-second-view');
    $assert->elementTextContains('css', '#block-first-view', 'Article A');
    $assert->elementTextContains('css', '#block-second-view', 'Article A');
    $assert->fieldExists('type', $second)->selectOption('page');

    // Activate Apply while focus is still on the filter, as for a keyboard
    // submission from an exposed field.
    $session->executeScript(<<<'JS'
      const form = document.querySelector('#block-second-view form');
      form.elements.type.focus();
      form.querySelector('[type="submit"]').click();
      JS);
    $assert->assertWaitOnAjaxRequest();
    $assert->elementTextContains('css', '#block-first-view', 'Article A');
    $assert->elementTextNotContains('css', '#block-second-view', 'Article A');
    $this->assertJsCondition("document.activeElement.matches('#block-second-view select[name=type]')");

    // Give the focused field an identity that the response will not contain.
    // Focus should fall back to this view's Apply control.
    $session->executeScript(<<<'JS'
      const form = document.querySelector('#block-second-view form');
      form.elements.type.id = 'removed-filter';
      form.elements.type.dataset.drupalSelector = 'removed-filter';
      form.elements.type.focus();
      form.querySelector('[type="submit"]').click();
      JS);
    $assert->assertWaitOnAjaxRequest();
    $this->assertJsCondition("document.activeElement.matches('#block-second-view [type=submit]')");
  }

}
