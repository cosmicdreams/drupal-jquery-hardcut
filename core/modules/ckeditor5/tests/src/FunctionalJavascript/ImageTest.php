<?php

declare(strict_types=1);

namespace Drupal\Tests\ckeditor5\FunctionalJavascript;

use Drupal\ckeditor5\Plugin\CKEditor5Plugin\Image;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore imageresize
/**
 * Tests Drupal\ckeditor5\Plugin\CKEditor5Plugin\Image.
 *
 * @internal
 */
#[CoversClass(Image::class)]
#[Group('ckeditor5')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
class ImageTest extends ImageTestTestBase {
  use ImageTestBaselineTrait;

  /**
   * Tests the ckeditor5_imageResize and ckeditor5_imageUpload settings forms.
   */
  public function testImageSettingsForm(): void {
    $assert_session = $this->assertSession();

    $this->drupalGet('admin/config/content/formats/manage/test_format');

    // The image resize and upload plugin settings forms should be present.
    $assert_session->elementExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-imageresize"]');
    $assert_session->elementExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-image"]');

    // Removing the drupalImageInsert button from the toolbar must remove the
    // plugin settings forms too.
    $this->triggerKeyUp('.ckeditor5-toolbar-item-drupalInsertImage', 'ArrowUp');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementNotExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-imageresize"]');
    $assert_session->elementNotExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-image"]');

    // Re-adding the drupalImageInsert button to the toolbar must re-add the
    // plugin settings forms too.
    $this->triggerKeyUp('.ckeditor5-toolbar-item-drupalInsertImage', 'ArrowDown');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-imageresize"]');
    $assert_session->elementExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-image"]');
  }

  /**
   * Tests that it's possible to upload SVG image, with the test module enabled.
   */
  public function testCanUploadSvg(): void {
    $this->container->get('module_installer')
      ->install(['ckeditor5_test_module_allowed_image']);

    $page = $this->getSession()->getPage();

    $src = 'core/modules/ckeditor5/tests/fixtures/test-svg-upload.svg';

    $this->drupalGet($this->host->toUrl('edit-form'));
    $this->waitForEditor();

    $this->assertNotEmpty($image_upload_field = $page->find('css', '.ck-file-dialog-button input[type="file"]'));
    $image_upload_field->attachFile($this->container->get('file_system')->realpath($src));
    // Wait for the image to be uploaded and rendered by CKEditor 5.
    $this->assertNotEmpty($this->assertSession()->waitForElementVisible('css', '.ck-widget.image-inline > img[src$="test-svg-upload.svg"]'));
  }

  /**
   * Tests that destroying an editor aborts its pending image upload.
   */
  public function testDetachDuringUpload(): void {
    $this->drupalGet($this->host->toUrl('edit-form'));
    $this->waitForEditor();
    $this->getSession()->executeScript(<<<'JS'
      const editor = Drupal.CKEditor5Instances.values().next().value;
      const uploadUrl = editor.config.get('drupalImageUpload').uploadUrl;
      const { open, send, abort } = XMLHttpRequest.prototype;
      XMLHttpRequest.prototype.open = function (method, url, ...args) {
        this.testEditorUpload = url === uploadUrl;
        return open.call(this, method, url, ...args);
      };
      XMLHttpRequest.prototype.send = function (...args) {
        if (this.testEditorUpload) {
          window.pendingEditorUpload = true;
        } else {
          send.apply(this, args);
        }
      };
      XMLHttpRequest.prototype.abort = function () {
        if (this.testEditorUpload) {
          window.abortedEditorUpload = true;
          this.dispatchEvent(new Event('abort'));
        } else {
          abort.call(this);
        }
      };

      const wrapper = editor.sourceElement.closest('.js-text-format-wrapper');
      window.removeEditorUnderTest = () => {
        Drupal.detachBehaviors(wrapper, null, 'unload');
        wrapper.remove();
      };
      const repository = editor.plugins.get('FileRepository');
      const loader = repository.createLoader(new File(['image'], 'image.png'));
      loader.upload().catch(() => {});
      JS);
    $this->assertJsCondition('window.pendingEditorUpload === true');
    $this->getSession()->executeScript('window.removeEditorUnderTest();');
    $this->assertJsCondition('window.abortedEditorUpload === true && Drupal.CKEditor5Instances.size === 0');
  }

}
