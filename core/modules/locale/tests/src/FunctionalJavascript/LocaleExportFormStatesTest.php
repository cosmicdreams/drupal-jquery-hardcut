<?php

declare(strict_types=1);

namespace Drupal\Tests\locale\FunctionalJavascript;

use Drupal\Core\Language\LanguageInterface;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the #states key of the interface translation export form.
 */
#[Group('locale')]
#[RunTestsInSeparateProcesses]
final class LocaleExportFormStatesTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['locale', 'language'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the langcode key matches only the language select.
   */
  public function testLangcodeKeyMatchesOnlyTheSelect(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->drupalLogin($this->drupalCreateUser(['translate interface']));
    $this->drupalGet('admin/config/regional/translate/export');

    $matches = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        // Another element with the same name, outside the form.
        const meta = document.createElement('meta');
        meta.name = 'langcode';
        document.head.append(meta);
        const dependent = document.querySelector('[data-drupal-selector="edit-content-options"]');
        const states = JSON.parse(dependent.dataset.drupalStates);
        return Object.keys(states.invisible).map(
          (key) => Array.from(document.querySelectorAll(key), (element) => element.localName),
        );
      })()
      JS);
    $this->assertSame([['select']], $matches, 'The langcode key matches the language select and no other element with that name.');

    $page = $this->getSession()->getPage();
    $options = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-content-options"]');
    $this->assertTrue($options->isVisible());
    $page->selectFieldOption('langcode', LanguageInterface::LANGCODE_SYSTEM);
    $this->assertFalse($options->isVisible(), 'Selecting source text only hides the export options.');
  }

}
