<?php

declare(strict_types=1);

namespace Drupal\Tests\media_library\FunctionalJavascript;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the views in the media library widget.
 */
#[Group('media_library')]
#[RunTestsInSeparateProcesses]
class WidgetViewsTest extends MediaLibraryTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a few example media items for use in selection.
    $this->createMediaItems([
      'type_one' => [
        'Horse',
        'Bear',
        'Cat',
        'Dog',
        'Goat',
        'Sheep',
        'Pig',
        'Cow',
        'Chicken',
        'Duck',
        'Donkey',
        'Llama',
        'Mouse',
        'Goldfish',
        'Rabbit',
        'Turkey',
        'Dove',
        'Giraffe',
        'Tiger',
        'Hamster',
        'Parrot',
        'Monkey',
        'Koala',
        'Panda',
        'Kangaroo',
      ],
      'type_two' => [
        'Crocodile',
        'Lizard',
        'Snake',
        'Turtle',
      ],
    ]);

    // Create a user who can use the Media library.
    $user = $this->drupalCreateUser([
      'access content',
      'create basic_page content',
      'view media',
      'create media',
    ]);
    $this->drupalLogin($user);
  }

  /**
   * Tests that the views in the Media library's widget work as expected.
   */
  public function testWidgetViews(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalGet('node/add/basic_page');

    $this->openMediaLibraryForField('field_unlimited_media');
    $this->getSession()->executeScript(<<<'JS'
      const container = document.getElementById('media-library-view');
      container.removeAttribute('id');
      Drupal.behaviors.MediaLibraryViewsDisplay.attach(container.querySelector('.js-media-library-view'));
      JS);
    $assert_session->elementExists('css', '#media-library-view');

    // Assert the 'Apply filter' button is not moved to the button pane.
    $button_pane = $assert_session->elementExists('css', '.drupal-dialog-buttonpane');
    $assert_session->buttonExists('Insert selected', $button_pane);
    $assert_session->buttonNotExists('Apply filters', $button_pane);

    // Assert the pager works as expected.
    // An active pager item is not linked and contains "Page #" as text.
    $assert_session->elementTextContains('css', '.js-media-library-view .js-pager__items > li:nth-of-type(1)', 'Page 1');
    $assert_session->elementNotExists('css', '.js-media-library-view .js-pager__items > li:nth-of-type(1) a');
    $assert_session->elementExists('css', '.js-media-library-view .js-pager__items > li:nth-of-type(2) a');
    $this->assertCount(24, $this->getCheckboxes());
    $page->clickLink('Next page');
    $this->waitForElementTextContains('.js-media-library-view .js-pager__items > li:nth-of-type(2)', 'Page 2');
    $assert_session->elementExists('css', '.js-media-library-view .js-pager__items > li:nth-of-type(1) a');
    $assert_session->elementNotExists('css', '.js-media-library-view .js-pager__items > li:nth-of-type(2) a');
    $this->assertCount(1, $this->getCheckboxes());
    $page->clickLink('Previous page');
    $this->waitForElementTextContains('.js-media-library-view .js-pager__items > li:nth-of-type(1)', 'Page 1');
    $this->assertCount(24, $this->getCheckboxes());

    $page->checkField('Select Bear');
    $this->pressInsertSelected('Added one media item.');
    $assert_session->pageTextContains('Bear');
    $assert_session->pageTextNotContains('Cat');
    $assert_session->pageTextNotContains('Turtle');

    $this->openMediaLibraryForField('field_unlimited_media');
    $this->switchToMediaLibraryTable();

    // Assert the 'Apply filter' button is not moved to the button pane.
    $assert_session->buttonExists('Insert selected', $button_pane);
    $assert_session->buttonNotExists('Apply filters', $button_pane);
    $assert_session->pageTextContains('Dog');
    $assert_session->pageTextContains('Bear');
    $assert_session->pageTextNotContains('Turtle');

    // Assert the exposed filters can be applied and page is reset from second
    // page.
    $page->clickLink('Next page');
    $this->waitForElementTextContains('.js-media-library-view .js-pager__items > li:nth-of-type(2)', 'Page 2');
    $page->fillField('Name', 'Bear');
    $page->pressButton('Apply filters');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->pageTextNotContains('Dog');
    $assert_session->pageTextContains('Bear');
    $assert_session->pageTextNotContains('Turtle');

    // Test clearing the filters.
    $page->fillField('Name', '');
    $page->pressButton('Apply filters');
    $assert_session->waitForLink('Next page');
    $page->clickLink('Next page');
    $this->waitForElementTextContains('.js-media-library-view .js-pager__items > li:nth-of-type(2)', 'Page 2');

    // Assert the exposed filters are persisted when changing display.
    $page->fillField('Name', 'Dog');
    $page->pressButton('Apply filters');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->pageTextContains('Dog');
    $assert_session->pageTextNotContains('Crocodile');
    $assert_session->pageTextNotContains('Turtle');
    $page->checkField('Select Dog');
    $assert_session->linkExists('Table');
    $this->switchToMediaLibraryGrid();
    $this->assertJsCondition('document.activeElement.matches("#media-library-view .views-display-link-widget")');

    $this->assertSame('Dog', $page->findField('Name')->getValue());
    $assert_session->pageTextContains('Dog');
    $assert_session->pageTextNotContains('Crocodile');
    $assert_session->pageTextNotContains('Turtle');
    $assert_session->linkExists('Grid');
    $this->switchToMediaLibraryTable();
    $this->assertJsCondition('document.activeElement.matches("#media-library-view .views-display-link-widget_table")');

    // Select the item.
    $this->pressInsertSelected('Added one media item.');
    // Ensure that the selection completed successfully.
    $assert_session->pageTextContains('Dog');
    $assert_session->pageTextContains('Bear');
    $assert_session->pageTextNotContains('Turtle');
  }

  /**
   * Tests switching displays while a media type response is pending.
   */
  #[DataProvider('responseOrders')]
  public function testOverlappingTypeAndDisplayRequests(bool $type_first): void {
    $this->drupalGet('node/add/basic_page');
    $menu = $this->openMediaLibraryForField('field_no_add_media');
    $session = $this->getSession();
    $assert = $this->assertSession();

    // Hold both real responses so the test controls their delivery order.
    $session->executeScript(<<<'JS'
      const request = Drupal.ajaxRequest;
      window.pickerResponses = {};
      Drupal.ajaxRequest = (options, complete) => {
        const query = new URL(options.url, document.baseURI).searchParams;
        const type = query.get('media_library_selected_type');
        if (!type) {
          return request(options, complete);
        }
        const key = type === 'type_two' ? 'type' : 'table';
        return request(options, (response) => new Promise((resolve) => {
          window.pickerResponses[key] = () => resolve(complete(response));
        }));
      };
      JS);
    $assert->elementExists('css', 'a[data-title="Type Two"]', $menu)->click();
    $this->assertJsCondition('typeof window.pickerResponses.type === "function"');
    $session->getPage()->clickLink('Table');
    $this->assertJsCondition('typeof window.pickerResponses.table === "function"');

    if ($type_first) {
      $session->executeScript('window.pickerResponses.type();');
      $this->assertAnnounceContains('Showing Type Two media.');
      $session->executeScript('window.pickerResponses.table();');
      $this->assertAnnounceContains('Changed to table view.');
      $this->assertAnnounceContains('Showing Type One media.');
    }
    else {
      $session->executeScript('window.pickerResponses.table();');
      $this->assertAnnounceContains('Changed to table view.');
      $session->executeScript('window.pickerResponses.type();');
      $this->assertAnnounceContains('Showing Type Two media.');
    }

    $assert->assertWaitOnAjaxRequest();
    $title = $type_first ? 'Type One' : 'Type Two';
    $assert->elementTextEquals('css', '.js-media-library-menu a.active', "Show $title media (selected)");
    $display = $type_first ? 'widget_table' : 'widget';
    $view = ".js-media-library-view[data-view-display-id=\"$display\"]";
    $assert->elementTextContains('css', $view, $type_first ? 'Bear' : 'Turtle');
    $assert->elementTextNotContains('css', $view, $type_first ? 'Turtle' : 'Bear');
  }

  /**
   * Provides both response orders for overlapping picker requests.
   */
  public static function responseOrders(): array {
    return [
      'type response first' => [TRUE],
      'table response first' => [FALSE],
    ];
  }

}
