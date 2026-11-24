<?php

declare(strict_types=1);

namespace Drupal\Tests\ckeditor5\FunctionalJavascript;

use Drupal\editor\Entity\Editor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests editor serialization and a pending format switch during Ajax rebuilds.
 *
 * @internal
 */
#[Group('ckeditor5')]
#[RunTestsInSeparateProcesses]
class EditorSerializationTest extends CKEditor5TestBase {

  /**
   * Tests that adding another item submits editor data before an Ajax rebuild.
   */
  public function testAjaxEditorSerialization(): void {
    $this->addNewTextFormat();
    $format = FilterFormat::load('ckeditor5');
    $this->assertInstanceOf(FilterFormat::class, $format);
    $format->createDuplicate()
      ->set('format', 'alternate')
      ->set('name', 'Alternate')
      ->set('weight', 10)
      ->set('roles', [RoleInterface::AUTHENTICATED_ID])
      ->save();
    Editor::create([
      'format' => 'alternate',
      'editor' => 'ckeditor5',
      'image_upload' => ['status' => FALSE],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_sections',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_sections',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Sections',
      'settings' => ['allowed_formats' => ['ckeditor5', 'alternate']],
    ])->save();
    $this->container->get('entity_display.repository')->getFormDisplay('node', 'page')
      ->setComponent('field_sections', ['type' => 'text_textarea'])
      ->save();

    $this->drupalGet('node/add/page');
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->assertJsCondition(<<<'JS'
      Array.from(Drupal.CKEditor5Instances.values()).some(
        (editor) => editor.sourceElement.name === 'field_sections[0][value]'
      )
      JS);
    $this->getSession()->executeScript(<<<'JS'
      Array.from(Drupal.CKEditor5Instances.values()).find(
        (editor) => editor.sourceElement.name === 'field_sections[0][value]'
      ).setData('<p>Kept through the Ajax rebuild.</p>');
      const fetch = window.fetch;
      window.fetch = (url, options) => {
        if (!String(url).includes('editor/filter_xss/alternate')) {
          return fetch(url, options);
        }
        return new Promise((resolve) => {
          window.completeFormatSwitch = () => resolve({
            ok: true,
            json: async () => '<p>Obsolete response.</p>',
          });
        });
      };
      JS);

    $page->selectFieldOption('field_sections[0][format]', 'alternate');
    $assert->elementExists('css', 'dialog.editor-change-text-format-modal')->pressButton('Continue');
    $this->assertJsCondition('typeof window.completeFormatSwitch === "function"');
    $page->pressButton('Add another item');
    $assert->assertWaitOnAjaxRequest();
    $this->getSession()->executeScript('window.completeFormatSwitch();');
    $this->assertJsCondition(<<<'JS'
      (() => {
        const sections = Array.from(Drupal.CKEditor5Instances.values()).filter(
          (editor) => editor.sourceElement.name.startsWith('field_sections[')
        );
        return sections.length === 2 &&
          sections[0].sourceElement.dataset.editorActiveTextFormat === 'ckeditor5' &&
          sections[0].getData() === '<p>Kept through the Ajax rebuild.</p>' &&
          sections[1].getData() === '';
      })()
      JS);
  }

}
