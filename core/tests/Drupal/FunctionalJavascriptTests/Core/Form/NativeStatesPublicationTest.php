<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests\Core\Form;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the native and legacy views of an owned States event.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
final class NativeStatesPublicationTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['jquery_bc_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests publications from a real select and its dependent field.
   */
  public function testSelectPublication(): void {
    $this->drupalGet('jquery-bc-test/events');
    $session = $this->getSession();
    $field = $this->assertSession()->fieldExists('Dependent field');
    $this->assertFalse($field->isVisible());
    $session->executeScript(<<<'JS'
      const native = [];
      const legacy = [];
      for (const [type, field] of [['state:value', 'choice'], ['state:visible', 'dependent']]) {
        document.addEventListener(type, (event) => {
          if (event.target.name === field) {
            native.push(event);
          }
        });
        jQuery(document).on(type, (event) => {
          if (event.target.name === field) {
            legacy.push(event);
          }
        });
      }
      jQuery('select[name="choice"]').val('show').trigger('change');
      window.statePublications = [
        native.map((event) => [
          event.type, event.target.name, event.detail.value,
          event.detail.oldValue ?? null, event.detail.trigger ?? null,
        ]),
        legacy.map((event) => [
          event.type, event.target.name, event.value, event.oldValue ?? null,
          event.trigger ?? null, native.includes(event.originalEvent),
        ]),
      ];
      JS);

    $this->assertSession()->assertExpectedAjaxRequest(1);
    $this->assertTrue($field->isVisible());
    $this->assertSame([
      [
        ['state:visible', 'dependent', TRUE, NULL, TRUE],
        ['state:value', 'choice', 'show', 'hide', NULL],
      ],
      [
        ['state:visible', 'dependent', TRUE, NULL, TRUE, TRUE],
        ['state:value', 'choice', 'show', 'hide', NULL, TRUE],
      ],
    ], $session->evaluateScript('window.statePublications'));
  }

}
