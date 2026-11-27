<?php

declare(strict_types=1);

namespace Drupal\Tests\language\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests language negotiation table visibility.
 */
#[Group('language')]
#[RunTestsInSeparateProcesses]
class NegotiationVisibilityTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Shows the negotiation methods only when customization is enabled.
   */
  public function testNegotiationTableVisibility(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->drupalLogin($this->drupalCreateUser(['administer languages']));
    $this->drupalGet('admin/config/regional/language/detection');

    $group = $this->assertSession()->elementExists('css', '.table-language-group:has(input[name="language_content[configurable]"])');
    $checkbox = $group->findField('language_content[configurable]');
    $table = $group->find('css', 'table');
    $weight_toggle = $group->find('css', '.tabledrag-toggle-weight');

    $this->assertFalse($table->isVisible());
    $this->assertFalse($weight_toggle->isVisible());
    $checkbox->check();
    $this->assertTrue($table->isVisible());
    $this->assertTrue($weight_toggle->isVisible());
    $checkbox->uncheck();
    $this->assertFalse($table->isVisible());
    $this->assertFalse($weight_toggle->isVisible());
  }

}
