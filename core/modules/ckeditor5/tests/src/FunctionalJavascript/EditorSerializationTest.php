<?php

declare(strict_types=1);

namespace Drupal\Tests\ckeditor5\FunctionalJavascript;

use Drupal\editor\Entity\Editor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
      window.sectionsEditor = Array.from(Drupal.CKEditor5Instances.values()).find(
        (instance) => instance.sourceElement.name === 'field_sections[0][value]'
      );
      window.sectionsEditor.setData('<p>Content before the format switch.</p>');
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
    $session = $this->getSession();
    $this->assertSame('ckeditor5', $session->evaluateScript(<<<'JS'
      new FormData(window.sectionsEditor.sourceElement.form).get('field_sections[0][format]')
      JS));
    $session->executeScript(<<<'JS'
      const editor = window.sectionsEditor;
      const form = editor.sourceElement.form;
      const ajax = Drupal.ajax.instances.find((instance) =>
        instance?.form === form && instance.element.value === 'Add another item'
      );
      ajax.beforeSubmit = () => {
        window.visibleFormatDuringSubmit = form.elements.namedItem('field_sections[0][format]').value;
      };
      editor.setData('<p>Kept through the Ajax rebuild.</p>');
      JS);
    $this->assertSame('<p>Content before the format switch.</p>', $session->evaluateScript('window.sectionsEditor.sourceElement.value'));

    $page->pressButton('Add another item');
    $assert->assertWaitOnAjaxRequest();
    $session->executeScript('window.completeFormatSwitch();');
    $rebuilt = $assert->elementExists('css', 'textarea[name="field_sections[0][value]"]');
    $this->assertSame('<p>Kept through the Ajax rebuild.</p>', $rebuilt->getValue());
    $assert->fieldValueEquals('field_sections[0][format]', 'ckeditor5');
    $this->assertSame('alternate', $session->evaluateScript('window.visibleFormatDuringSubmit'));
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
   * Tests viewport offsets after serialization while the editor stays mounted.
   */
  public function testToolbarDisplacementAfterSerialization(): void {
    $this->container->get('module_installer')->install(['navigation']);
    Role::load(RoleInterface::AUTHENTICATED_ID)->grantPermission('access navigation')->save();
    $this->createSectionsField();
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'uid' => $this->loggedInUser->id(),
    ]);
    $this->getSession()->resizeWindow(1400, 900);
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', '.top-bar[data-offset-top]'));
    $this->assertJsCondition(<<<'JS'
      (window.displacedEditor = Array.from(Drupal.CKEditor5Instances.values()).find(
        (editor) => editor.sourceElement.name === 'field_sections[0][value]'
      ))?.state === 'ready'
      JS);

    $this->getSession()->executeScript(<<<'JS'
      const toolbar = document.querySelector('.top-bar[data-offset-top]');
      const editor = window.displacedEditor;
      Drupal.detachBehaviors(editor.sourceElement.form, drupalSettings, 'serialize');
      window.previousToolbarOffset = toolbar.getBoundingClientRect().bottom;
      toolbar.style.setProperty('--admin-toolbar-top-bar-height', '120px');
      Drupal.displace();
      JS);
    $this->assertJsCondition(<<<'JS'
      (() => {
        const offset = document.querySelector('.top-bar[data-offset-top]').getBoundingClientRect().bottom;
        return offset > window.previousToolbarOffset &&
          Drupal.displace.offsets.top === offset &&
          window.displacedEditor.ui.viewportOffset.top === offset;
      })()
      JS);
  }

  /**
   * Tests moving a row with changes still in the Source Editing textarea.
   */
  #[DataProvider('tableDragModes')]
  public function testTableDragEditorReattachment(bool $keyboard): void {
    $this->createSectionsField();
    $editor = Editor::load('ckeditor5');
    $settings = $editor->getSettings();
    $settings['toolbar']['items'][] = 'sourceEditing';
    $settings['plugins']['ckeditor5_sourceEditing']['allowed_tags'] = [];
    $editor->setSettings($settings)->save();

    $node = $this->drupalCreateNode([
      'type' => 'page',
      'uid' => $this->loggedInUser->id(),
      'field_sections' => [
        ['value' => '<p>First section.</p>', 'format' => 'ckeditor5'],
        ['value' => '<p>Original second section.</p>', 'format' => 'ckeditor5'],
      ],
    ]);
    $this->getSession()->resizeWindow(1400, 1200);
    $this->drupalGet($node->toUrl('edit-form'));
    $assert = $this->assertSession();
    $this->assertJsCondition(<<<'JS'
      (window.movedEditor = Array.from(Drupal.CKEditor5Instances.values()).find(
        (editor) => editor.sourceElement.name === 'field_sections[1][value]'
      ))?.state === 'ready'
      JS);
    $first_row = 'tr.draggable:has(textarea[name="field_sections[0][value]"])';
    $moved_row = 'tr.draggable:has(textarea[name="field_sections[1][value]"])';
    $row = $assert->elementExists('css', $moved_row);
    $assert->elementExists('xpath', './/button[span[text()="Source"]]', $row)->click();
    $source = $assert->elementExists('css', "$moved_row .ck-source-editing-area textarea");
    $source->setValue('<p>Moved source text.</p>');
    $this->assertSame('false', $this->getSession()->evaluateScript('window.movedEditor.sourceElement.dataset.editorValueIsChanged'));

    $handle = $assert->elementExists('css', "$moved_row .tabledrag-handle");
    if ($keyboard) {
      $handle->focus();
      $handle->keyDown(38);
      $handle->keyUp(38);
    }
    else {
      $handle->dragTo($assert->elementExists('css', "$first_row .tabledrag-handle"));
    }

    $this->assertJsCondition(<<<'JS'
      (() => {
        const previous = window.movedEditor;
        const field = previous.sourceElement;
        const row = field.closest('tr');
        const reattached = Drupal.CKEditor5Instances.get(field.dataset.ckeditor5Id);
        return previous.state === 'destroyed' &&
          row === row.parentElement.firstElementChild &&
          reattached?.state === 'ready' &&
          row.querySelectorAll('.ck-editor__editable').length === 1;
      })()
      JS);
    $this->assertSame('<p>Moved source text.</p>', $this->getSession()->evaluateScript(
      'Drupal.CKEditor5Instances.get(window.movedEditor.sourceElement.dataset.ckeditor5Id).getData()',
    ));
    if ($keyboard) {
      $this->assertJsCondition("document.activeElement.matches('$moved_row .tabledrag-handle')");
    }
  }

  /**
   * Provides the two ways of moving a table row.
   */
  public static function tableDragModes(): array {
    return [
      'keyboard' => [TRUE],
      'pointer' => [FALSE],
    ];
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
   * Tests that a failed switch restores the plain textarea's guidelines.
   */
  public function testGuidelinesAfterFailedFormatSwitch(): void {
    $this->createSectionsField();
    Editor::load('ckeditor5')->delete();
    $this->drupalGet('node/add/page');
    $assert = $this->assertSession();
    $session = $this->getSession();
    $page = $session->getPage();
    $guidelines_selector = '.js-text-format-wrapper:has(textarea[name="field_sections[0][value]"]) [data-drupal-format-id=ckeditor5]';
    $assert->elementExists('css', $guidelines_selector);
    $guidelines = 'getComputedStyle(document.querySelector(' . json_encode($guidelines_selector, JSON_THROW_ON_ERROR) . ')).display';
    $this->assertNotSame('none', $session->evaluateScript($guidelines));
    $page->fillField('field_sections[0][value]', 'Text in the original format.');
    $session->executeScript(<<<'JS'
      const fetch = window.fetch;
      window.fetch = (url, options) => {
        if (!String(url).includes('editor/filter_xss/alternate')) {
          return fetch(url, options);
        }
        return new Promise((resolve) => {
          window.failFormatSwitch = () => resolve({ ok: false, statusText: 'Test filter failure' });
        });
      };
      JS);
    $page->selectFieldOption('field_sections[0][format]', 'alternate');
    $assert->elementExists('css', 'dialog.editor-change-text-format-modal')->pressButton('Continue');
    $this->assertJsCondition('typeof window.failFormatSwitch === "function"');
    $this->assertSame('none', $session->evaluateScript($guidelines));

    $session->executeScript('window.failFormatSwitch();');
    $this->assertNotNull($assert->waitForElement('css', '#drupal-live-announce[aria-live="assertive"]:contains("The text format could not be changed.")'));
    $assert->fieldValueEquals('field_sections[0][format]', 'ckeditor5');
    $assert->fieldValueEquals('field_sections[0][value]', 'Text in the original format.');
    $this->assertNotSame('none', $session->evaluateScript($guidelines));
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
