<?php

declare(strict_types=1);

namespace Drupal\Tests\ckeditor5\FunctionalJavascript;

use Drupal\editor\Entity\Editor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

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
  #[TestWith([FALSE], 'unchanged')]
  #[TestWith([TRUE], 'edited')]
  public function testAjaxEditorSerialization(bool $edit): void {
    $original = "<p>First section.</p>\n<p>Another paragraph.</p>";
    $this->openSectionsForm($original);
    $assert = $this->assertSession();
    $session = $this->getSession();
    if (!$edit) {
      $this->assertSame('<p>First section.</p><p>Another paragraph.</p>', $session->evaluateScript('window.switchingEditor.getData()'));
    }
    $this->beginPendingFormatSwitch($edit);
    $expected = $edit ? '<p>Edited before the format switch.</p>' : $original;
    $this->assertSame($expected, $session->evaluateScript('window.formatFilterInput'));

    // Leave another editor mounted with fresh data and a stale textarea.
    $session->executeScript(<<<'JS'
      Array.from(Drupal.CKEditor5Instances.values()).find(
        (editor) => editor.sourceElement.name === 'field_sections[1][value]'
      ).setData('<p>Kept through the Ajax rebuild.</p>');
      JS);
    $assert->fieldValueEquals('field_sections[1][value]', '<p>Second section.</p>');

    $session->getPage()->pressButton('Add another item');
    $assert->assertWaitOnAjaxRequest();
    $session->executeScript('window.completeFormatSwitch();');
    $assert->fieldValueEquals('field_sections[0][format]', 'alternate');
    $assert->fieldValueEquals('field_sections[0][value]', $expected);
    $assert->fieldValueEquals('field_sections[1][value]', '<p>Kept through the Ajax rebuild.</p>');
    $this->assertTrue($session->evaluateScript('window.formatSwitchSignal.aborted'));
    $editor_data = json_encode(str_replace("\n", '', $expected), JSON_THROW_ON_ERROR);
    $this->assertJsCondition(<<<JS
      (() => {
        const sections = new Map(Array.from(Drupal.CKEditor5Instances.values())
          .map((editor) => [editor.sourceElement.name, editor]));
        return sections.get('field_sections[0][value]')?.getData() === {$editor_data} &&
          sections.get('field_sections[1][value]')?.getData() === '<p>Kept through the Ajax rebuild.</p>' &&
          sections.get('field_sections[2][value]')?.getData() === '';
      })()
      JS);
  }

  /**
   * Tests saving the selected format and editor content before filtering ends.
   */
  public function testSaveDuringPendingFormatSwitch(): void {
    $node = $this->openSectionsForm();
    $this->beginPendingFormatSwitch();

    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->addressEquals($node->toUrl()->toString());
    $saved = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $this->assertSame('alternate', $saved->field_sections->format);
    $this->assertSame('<p>Edited before the format switch.</p>', $saved->field_sections->value);
  }

  /**
   * Tests a keyboard row move during a confirmed format switch.
   */
  public function testMoveDuringPendingFormatSwitch(): void {
    $this->openSectionsForm();
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      window.alternateAttachments = [];
      const attach = Drupal.editorAttach;
      Drupal.editorAttach = (field, format) => {
        if (field === window.switchingEditor.sourceElement && format.format === 'alternate') {
          window.alternateAttachments.push(field.value);
        }
        attach(field, format);
      };
      JS);
    $this->beginPendingFormatSwitch();
    $assert = $this->assertSession();
    $row = 'tr.draggable:has(textarea[name="field_sections[0][value]"])';
    $handle = $assert->elementExists('css', "$row .tabledrag-handle");
    $handle->focus();
    $handle->keyDown(40);
    $handle->keyUp(40);

    $this->assertJsCondition("document.querySelector('$row').previousElementSibling.matches('tr.draggable')");
    $assert->fieldValueEquals('field_sections[0][format]', 'alternate');
    $this->assertJsCondition(<<<'JS'
      (() => {
        const field = window.switchingEditor.sourceElement;
        const row = field.closest('tr');
        const editor = Drupal.CKEditor5Instances.get(field.dataset.ckeditor5Id);
        return !window.formatSwitchSignal.aborted &&
          field.dataset.editorActiveTextFormat === 'alternate' &&
          row.previousElementSibling.querySelector('textarea[name="field_sections[1][value]"]') &&
          row.querySelectorAll('.ck-editor__editable').length === 1 &&
          editor?.state === 'ready' && editor.getData() === '<p>Edited before the format switch.</p>';
      })()
      JS);
    $this->assertSame(['<p>Edited before the format switch.</p>'], $session->evaluateScript('window.alternateAttachments'));
    $session->executeScript('window.completeFormatSwitch();');
    $this->assertJsCondition('window.alternateAttachments.length === 2');
    $this->assertSame([
      '<p>Edited before the format switch.</p>',
      '<p>Filtered text.</p>',
    ], $session->evaluateScript('window.alternateAttachments'));
  }

  /**
   * Tests restoring the plain text format guidelines after canceling.
   */
  public function testCancelRestoresFormatGuidelines(): void {
    $this->createSectionsField();
    $field = FieldConfig::loadByName('node', 'page', 'field_sections');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setSetting('allowed_formats', ['plain_text', 'alternate'])->save();
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'uid' => $this->loggedInUser->id(),
      'field_sections' => [['value' => 'First section.', 'format' => 'plain_text']],
    ]);
    $this->drupalGet($node->toUrl('edit-form'));
    $assert = $this->assertSession();
    $selector = '.js-filter-wrapper:has(select[name="field_sections[0][format]"]) [data-drupal-format-id="plain_text"]';
    $assert->elementExists('css', $selector);
    // The guidelines can be empty even when the format's container is shown.
    $shown = "getComputedStyle(document.querySelector('$selector')).display !== 'none'";
    $this->assertJsCondition($shown);

    $this->getSession()->getPage()->selectFieldOption('field_sections[0][format]', 'alternate');
    $this->assertJsCondition("!($shown)");
    $assert->elementExists('css', 'dialog.editor-change-text-format-modal')->pressButton('Cancel');
    $assert->fieldValueEquals('field_sections[0][format]', 'plain_text');
    $this->assertJsCondition($shown);

    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      window.bodyOverflowBeforeConfirmation = document.body.style.overflow;
      window.confirmationCloses = 0;
      document.addEventListener('dialog:afterclose', () => window.confirmationCloses++);
      JS);
    $session->getPage()->selectFieldOption('field_sections[0][format]', 'alternate');
    $this->dismissEditorOverlay();
    $assert->elementExists('css', 'dialog.editor-change-text-format-modal[open]');
    $this->dismissEditorOverlay();
    $this->assertJsCondition('window.confirmationCloses === 1');
    $assert->elementNotExists('css', 'dialog.editor-change-text-format-modal');
    $assert->fieldValueEquals('field_sections[0][format]', 'plain_text');
    $this->assertJsCondition("$shown && document.body.style.overflow === window.bodyOverflowBeforeConfirmation");
  }

  /**
   * Tests typing in the textarea before its previous editor finishes loading.
   */
  public function testDiscardedEditorPreservesPlainText(): void {
    $this->openSectionsForm(allow_plain_text: TRUE);
    $session = $this->getSession();
    $page = $session->getPage();
    $page->selectFieldOption('field_sections[0][format]', 'plain_text');
    $this->assertJsCondition('window.switchingEditor.state === "destroyed"');

    $session->executeScript(<<<'JS'
      const { ClassicEditor } = CKEDITOR;
      const create = ClassicEditor.create;
      ClassicEditor.create = async (...args) => {
        ClassicEditor.create = create;
        await new Promise((resolve) => { window.resumeEditorCreation = resolve; });
        window.pendingEditor = await create.apply(ClassicEditor, args);
        return window.pendingEditor;
      };
      JS);
    $this->confirmAlternateFormat();
    $this->assertJsCondition('typeof window.resumeEditorCreation === "function"');
    $page->selectFieldOption('field_sections[0][format]', 'plain_text');
    $page->fillField('field_sections[0][value]', 'Typed while the editor was loading.');
    $session->executeScript('window.resumeEditorCreation()');
    $this->assertJsCondition('window.pendingEditor?.state === "destroyed"');
    $this->assertSession()->fieldValueEquals('field_sections[0][value]', 'Typed while the editor was loading.');
  }

  /**
   * Opens an edit form with two existing formatted text items.
   */
  private function openSectionsForm(string $original = '<p>First section.</p>', bool $allow_plain_text = FALSE): NodeInterface {
    $this->createSectionsField();
    if ($allow_plain_text) {
      $field = FieldConfig::loadByName('node', 'page', 'field_sections');
      $this->assertInstanceOf(FieldConfig::class, $field);
      $field->setSetting('allowed_formats', ['ckeditor5', 'alternate', 'plain_text'])->save();
    }
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'uid' => $this->loggedInUser->id(),
      'field_sections' => [
        ['value' => $original, 'format' => 'ckeditor5'],
        ['value' => '<p>Second section.</p>', 'format' => 'ckeditor5'],
      ],
    ]);
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertJsCondition(<<<'JS'
      (window.switchingEditor = Array.from(Drupal.CKEditor5Instances.values()).find(
        (editor) => editor.sourceElement.name === 'field_sections[0][value]'
      ))?.state === 'ready' &&
        Array.from(Drupal.CKEditor5Instances.values()).some(
          (editor) => editor.sourceElement.name === 'field_sections[1][value]' && editor.state === 'ready'
        )
      JS);
    return $node;
  }

  /**
   * Confirms a format switch and holds its filter response.
   */
  private function beginPendingFormatSwitch(bool $edit = TRUE): void {
    $session = $this->getSession();
    if ($edit) {
      $session->executeScript('window.switchingEditor.setData("<p>Edited before the format switch.</p>");');
    }
    $session->executeScript(<<<'JS'
      const fetch = window.fetch;
      window.fetch = (url, options) => {
        if (!String(url).includes('editor/filter_xss/alternate')) {
          return fetch(url, options);
        }
        window.formatSwitchSignal = options.signal;
        window.formatFilterInput = options.body.get('value');
        return new Promise((resolve) => {
          window.completeFormatSwitch = () => resolve({
            ok: true,
            json: async () => '<p>Filtered text.</p>',
          });
        });
      };
      JS);
    $this->confirmAlternateFormat();
    $this->assertJsCondition('typeof window.completeFormatSwitch === "function" && window.switchingEditor.state === "destroyed"');
  }

  /**
   * Selects and confirms the alternate text format for the first item.
   */
  private function confirmAlternateFormat(): void {
    $this->getSession()->getPage()->selectFieldOption('field_sections[0][format]', 'alternate');
    $this->assertSession()->elementExists('css', 'dialog.editor-change-text-format-modal')->pressButton('Continue');
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
   * Tests serializing after switching to a restrictive format and back.
   */
  #[TestWith([FALSE], 'unchanged')]
  #[TestWith([TRUE], 'edited')]
  #[TestWith([FALSE, TRUE], 'unchanged Ajax serialization')]
  public function testFormatRoundTrip(bool $edit, bool $ajax = FALSE): void {
    $this->createSectionsField();
    $format = FilterFormat::load('ckeditor5');
    $html_filter = $format->filters('filter_html')->getConfiguration();
    $html_filter['settings']['allowed_html'] .= ' <object>';
    $format->setFilterConfig('filter_html', $html_filter)->save();

    $original = '<p>Original text.</p><object></object>';
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'uid' => $this->loggedInUser->id(),
      'field_sections' => [['value' => $original, 'format' => 'ckeditor5']],
    ]);
    $this->drupalGet($node->toUrl('edit-form'));
    $session = $this->getSession();
    $page = $session->getPage();
    $assert = $this->assertSession();
    $session->executeScript(<<<'JS'
      window.sectionsEditor = () => Array.from(Drupal.CKEditor5Instances.values()).find(
        (editor) => editor.sourceElement.name === 'field_sections[0][value]'
      );
      JS);
    $this->assertJsCondition('window.sectionsEditor()?.state === "ready"');

    $page->selectFieldOption('field_sections[0][format]', 'alternate');
    $assert->elementExists('css', 'dialog.editor-change-text-format-modal')->pressButton('Continue');
    $this->assertJsCondition(<<<'JS'
      window.sectionsEditor()?.state === 'ready' &&
        window.sectionsEditor().sourceElement.dataset.editorActiveTextFormat === 'alternate'
      JS);
    $this->assertSame('<p>Original text.</p>', $session->evaluateScript('window.sectionsEditor().getData()'));
    if ($edit) {
      $session->executeScript('window.sectionsEditor().setData("<p>Edited in the alternate format.</p>");');
    }

    $page->selectFieldOption('field_sections[0][format]', 'ckeditor5');
    $assert->elementExists('css', 'dialog.editor-change-text-format-modal')->pressButton('Continue');
    $this->assertJsCondition(<<<'JS'
      window.sectionsEditor()?.state === 'ready' &&
        window.sectionsEditor().sourceElement.dataset.editorActiveTextFormat === 'ckeditor5'
      JS);
    if ($ajax) {
      $session->executeScript(<<<'JS'
        Drupal.detachBehaviors(window.sectionsEditor().sourceElement.form, drupalSettings, 'serialize');
        JS);
      $assert->fieldValueEquals('field_sections[0][value]', $original);
      return;
    }
    $page->pressButton('Save');
    $assert->addressEquals($node->toUrl()->toString());
    $saved = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $this->assertSame('ckeditor5', $saved->field_sections->format);
    $this->assertSame($edit ? '<p>Edited in the alternate format.</p>' : '<p>Original text.</p>', $saved->field_sections->value);
  }

  /**
   * Tests moving a row after filtering fails during a format switch.
   */
  public function testMoveAfterFailedFormatSwitch(): void {
    $this->openSectionsForm();
    $assert = $this->assertSession();
    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const fetch = window.fetch;
      window.fetch = (url, options) => {
        if (!String(url).includes('editor/filter_xss/alternate')) {
          return fetch(url, options);
        }
        return Promise.resolve({ ok: false, statusText: 'Test filter failure' });
      };
      JS);
    $this->confirmAlternateFormat();
    $assert->fieldValueEquals('field_sections[0][format]', 'alternate');
    $assert->fieldValueEquals('field_sections[0][value]', '<p>First section.</p>');
    $this->assertNotNull($assert->waitForElementVisible('css', 'textarea[name="field_sections[0][value]"]'));
    $this->assertJsCondition(<<<'JS'
      window.switchingEditor.state === 'destroyed' &&
        !window.switchingEditor.sourceElement.dataset.ckeditor5Id
      JS);
    $row = 'tr.draggable:has(textarea[name="field_sections[0][value]"])';
    $handle = $assert->elementExists('css', "$row .tabledrag-handle");
    $handle->focus();
    $handle->keyDown(40);
    $handle->keyUp(40);

    $this->assertJsCondition(<<<'JS'
      (() => {
        const field = window.switchingEditor.sourceElement;
        const editor = Drupal.CKEditor5Instances.get(field.dataset.ckeditor5Id);
        return field.closest('tr').previousElementSibling.matches('tr.draggable') &&
          field.dataset.editorActiveTextFormat === 'alternate' &&
          editor?.state === 'ready' && editor.getData() === '<p>First section.</p>';
      })()
      JS);
    $assert->fieldValueEquals('field_sections[0][format]', 'alternate');
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
