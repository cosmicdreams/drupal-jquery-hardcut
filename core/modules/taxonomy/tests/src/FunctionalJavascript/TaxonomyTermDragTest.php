<?php

declare(strict_types=1);

namespace Drupal\Tests\taxonomy\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests taxonomy preview markers after moving a term across a page divider.
 */
#[Group('taxonomy')]
#[RunTestsInSeparateProcesses]
class TaxonomyTermDragTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['taxonomy'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that a move updates both divider and preview classes.
   */
  public function testPreviewAcrossPageDivider(): void {
    $this->config('taxonomy.settings')->set('terms_per_page_admin', 2)->save();
    Vocabulary::create(['vid' => 'example', 'name' => 'Example'])->save();
    $root = Term::create(['vid' => 'example', 'name' => 'Parent']);
    $root->save();
    Term::create(['vid' => 'example', 'name' => 'First child', 'parent' => $root->id()])->save();
    Term::create(['vid' => 'example', 'name' => 'Second child', 'parent' => $root->id(), 'weight' => 1])->save();
    $this->drupalLogin($this->drupalCreateUser(['administer taxonomy']));
    $this->drupalGet('admin/structure/taxonomy/manage/example/overview');

    $assert = $this->assertSession();
    $table = '#taxonomy tbody';
    $assert->elementTextContains('css', "$table .taxonomy-term-divider-top", 'First child');
    $assert->elementTextContains('css', "$table .taxonomy-term-preview", 'Second child');
    $this->getSession()->executeScript(<<<'JS'
      const handle = document.querySelector('#taxonomy tbody .taxonomy-term-preview .tabledrag-handle');
      handle.focus();
      handle.dispatchEvent(new KeyboardEvent('keydown', {bubbles: true, keyCode: 38}));
      handle.blur();
      JS);
    $assert->elementTextContains('css', "$table .taxonomy-term-divider-top", 'Second child');
    $assert->elementTextContains('css', "$table .taxonomy-term-divider-bottom.taxonomy-term-preview", 'First child');
    $assert->elementsCount('css', "$table .taxonomy-term-preview", 1);
  }

}
