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
    $this->createSectionsField();
    $this->drupalGet('node/add/page');
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->assertJsCondition(<<<'JS'
      Array.from(Drupal.CKEditor5Instances.values()).some(
        (editor) => editor.sourceElement.name === 'field_sections[0][value]'
      )
      JS);
    $this->getSession()->executeScript(<<<'JS'
      const editor = Array.from(Drupal.CKEditor5Instances.values()).find(
        (instance) => instance.sourceElement.name === 'field_sections[0][value]'
      );
      editor.setData('<p>Kept through the Ajax rebuild.</p>');
      Drupal.detachBehaviors(editor.sourceElement.closest('.js-text-format-wrapper'), drupalSettings, 'serialize');
      document.dispatchEvent(new CustomEvent('drupalViewportOffsetChange', {
        detail: { top: 24, right: 0, bottom: 0, left: 0 },
      }));
      window.serializedEditorOffset = editor.ui.viewportOffset.top;
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
    $this->assertSame(24, $this->getSession()->evaluateScript('window.serializedEditorOffset'));

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

  /**
   * Tests focus and editor data when tabledrag moves a formatted field row.
   */
  public function testTableDragEditorReattachment(): void {
    $this->createSectionsField();
    $this->drupalGet('node/add/page');
    $assert = $this->assertSession();
    $this->getSession()->getPage()->pressButton('Add another item');
    $assert->assertWaitOnAjaxRequest();
    $this->assertJsCondition(<<<'JS'
      (window.movedEditor = Array.from(Drupal.CKEditor5Instances.values()).find(
        (editor) => editor.sourceElement.name === 'field_sections[1][value]'
      ))?.state === 'ready'
      JS);
    $this->getSession()->executeScript("window.movedEditor.setData('<p>Moved text.</p>');");
    $handle = $assert->elementExists('css', 'tr.draggable:has(textarea[name="field_sections[1][value]"]) .tabledrag-handle');
    $handle->focus();
    $handle->keyDown(38);
    $handle->keyUp(38);

    $this->assertJsCondition(<<<'JS'
      (() => {
        const previous = window.movedEditor;
        const field = previous.sourceElement;
        const row = field.closest('tr');
        const reattached = Drupal.CKEditor5Instances.get(field.dataset.ckeditor5Id);
        return previous.state === 'destroyed' &&
          row === row.parentElement.firstElementChild &&
          document.activeElement === row.querySelector('.tabledrag-handle') &&
          reattached?.getData() === '<p>Moved text.</p>' &&
          row.querySelectorAll('.ck-editor__editable').length === 1;
      })()
      JS);
  }

  /**
   * Tests failure recovery and edits made during asynchronous editor teardown.
   */
  public function testFormatSwitchDuringTeardown(): void {
    $this->createSectionsField();
    $this->drupalGet('node/add/page');
    $session = $this->getSession();
    $assert = $this->assertSession();
    $page = $session->getPage();
    $this->assertJsCondition(<<<'JS'
      (window.switchingEditor = Array.from(Drupal.CKEditor5Instances.values()).find(
        (editor) => editor.sourceElement.name === 'field_sections[0][value]'
      ))?.state === 'ready'
      JS);
    $session->executeScript(<<<'JS'
      const editor = window.switchingEditor;
      editor.setData('<p>Original text.</p>');
      const destroy = editor.destroy.bind(editor);
      editor.destroy = async () => {
        await destroy();
        await new Promise((resolve) => {
          window.finishEditorTeardown = resolve;
        });
      };
      const fetch = window.fetch;
      window.formatFilterInputs = [];
      window.fetch = (url, options) => {
        if (!String(url).includes('editor/filter_xss/alternate')) {
          return fetch(url, options);
        }
        const input = options.body.get('value');
        window.formatFilterInputs.push(input);
        return Promise.resolve(window.formatFilterInputs.length === 1
          ? { ok: false, statusText: 'Test filter failure' }
          : { ok: true, json: async () => input.replaceAll(/<\/?em>/g, '') });
      };
      JS);

    $page->selectFieldOption('field_sections[0][format]', 'alternate');
    $assert->elementExists('css', 'dialog.editor-change-text-format-modal')->pressButton('Continue');
    $this->assertNotNull($assert->waitForElement('css', '#drupal-live-announce[aria-live="assertive"]:contains("The text format could not be changed.")'));
    $assert->fieldValueEquals('field_sections[0][format]', 'ckeditor5');
    $this->assertSame('<p>Original text.</p>', $session->evaluateScript('window.switchingEditor.getData()'));

    $page->selectFieldOption('field_sections[0][format]', 'alternate');
    $assert->elementExists('css', 'dialog.editor-change-text-format-modal')->pressButton('Continue');
    $this->assertJsCondition('typeof window.finishEditorTeardown === "function"');
    $page->fillField('field_sections[0][value]', '<p>New <em>text</em>.</p>');
    $session->executeScript('window.finishEditorTeardown();');
    $this->assertJsCondition(<<<'JS'
      (() => {
        const field = window.switchingEditor.sourceElement;
        const editor = Drupal.CKEditor5Instances.get(field.dataset.ckeditor5Id);
        return field.dataset.editorActiveTextFormat === 'alternate' &&
          editor?.getData() === '<p>New text.</p>';
      })()
      JS);
    $this->assertSame(
      ['<p>Original text.</p>', '<p>Original text.</p>', '<p>New <em>text</em>.</p>'],
      $session->evaluateScript('window.formatFilterInputs'),
    );
  }

  /**
   * Creates a multivalue formatted text field and two editor formats.
   */
  private function createSectionsField(): void {
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
  }

}
