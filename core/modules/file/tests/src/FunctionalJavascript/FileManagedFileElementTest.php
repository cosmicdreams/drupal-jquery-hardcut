<?php

declare(strict_types=1);

namespace Drupal\Tests\file\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the 'managed_file' element type.
 */
#[Group('file')]
#[RunTestsInSeparateProcesses]
class FileManagedFileElementTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'file', 'file_module_test', 'field_ui'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with administration permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser([
      'access content',
      'access administration pages',
      'administer site configuration',
      'administer users',
      'administer permissions',
      'administer content types',
      'administer node fields',
      'administer node display',
      'administer nodes',
      'bypass node access',
    ]);
    $this->drupalLogin($this->adminUser);
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
  }

  /**
   * Tests the managed_file element type.
   */
  public function testManagedFile(): void {
    // Perform the tests with all permutations of $form['#tree'],
    // $element['#extended'], and $element['#multiple'].
    $filename = \Drupal::service('file_system')->tempnam('temporary://', "testManagedFile") . '.txt';
    file_put_contents($filename, $this->randomString(128));
    foreach ([0, 1] as $tree) {
      foreach ([0, 1] as $extended) {
        foreach ([0, 1] as $multiple) {
          $path = 'file/test/' . $tree . '/' . $extended . '/' . $multiple;
          $input_base_name = $tree ? 'nested_file' : 'file';
          $file_field_name = $multiple ? 'files[' . $input_base_name . '][]' : 'files[' . $input_base_name . ']';

          // Now, test the Upload and Remove buttons, with Ajax.
          // Upload, then Submit.
          $last_fid_prior = $this->getLastFileId();
          $this->drupalGet($path);
          $this->getSession()->getPage()->attachFileToField($file_field_name, $this->container->get('file_system')->realpath($filename));
          $uploaded_file = $this->assertSession()->waitForElement('css', '.file--mime-text-plain');
          $this->assertNotEmpty($uploaded_file);
          $last_fid = $this->getLastFileId();
          $this->assertGreaterThan($last_fid_prior, $last_fid, 'New file got uploaded.');
          $this->submitForm([], 'Save');

          // Remove, then Submit.
          $remove_button_title = $multiple ? 'Remove selected' : 'Remove';
          $this->drupalGet($path . '/' . $last_fid);
          if ($multiple) {
            $selected_checkbox = ($tree ? 'nested[file]' : 'file') . '[file_' . $last_fid . '][selected]';
            $this->getSession()->getPage()->checkField($selected_checkbox);
          }
          $this->getSession()->getPage()->pressButton($remove_button_title);
          $this->assertSession()->assertWaitOnAjaxRequest();
          $this->submitForm([], 'Save');
          $this->assertSession()->pageTextContains('The file ids are .');

          // Upload, then Remove, then Submit.
          $this->drupalGet($path);
          $this->getSession()->getPage()->attachFileToField($file_field_name, $this->container->get('file_system')->realpath($filename));
          $uploaded_file = $this->assertSession()->waitForElement('css', '.file--mime-text-plain');
          $this->assertNotEmpty($uploaded_file);
          if ($multiple) {
            $selected_checkbox = ($tree ? 'nested[file]' : 'file') . '[file_' . $this->getLastFileId() . '][selected]';
            $this->getSession()->getPage()->checkField($selected_checkbox);
          }
          $this->getSession()->getPage()->pressButton($remove_button_title);
          $this->assertSession()->assertWaitOnAjaxRequest();

          $this->submitForm([], 'Save');
          $this->assertSession()->pageTextContains('The file ids are .');
        }
      }
    }
  }

  /**
   * Tests automatic upload through the managed file progress event.
   */
  public function testManagedFileProgressUpload(): void {
    $file_system = $this->container->get('file_system');
    $filename = $file_system->tempnam('temporary://', 'progress-upload') . '.txt';
    file_put_contents($filename, 'Managed file upload with progress.');

    $this->drupalGet('file/test/0/0/0', ['query' => ['upload_progress' => 1]]);
    $session = $this->getSession();
    $this->assertJsCondition('Object.values(drupalSettings.ajax).some((settings) => settings.event === "fileUpload")');
    // Only web servers with the UploadProgress extension provide an upload
    // identifier and polling endpoint. The fallback fixture still exercises
    // the event and the progress bar with its initial message.
    if ($session->getPage()->has('css', 'input.file-progress')) {
      $this->assertJsCondition('Object.values(drupalSettings.ajax).some((settings) => settings.event === "fileUpload" && settings.progress.url)');
    }

    // Fast uploads can finish before WebDriver inspects the page. Record the
    // indicator when Ajax inserts it.
    $session->executeScript(<<<'JS'
      document.addEventListener('drupal:ajax-before-send', ({ detail: { ajax } }) => {
        const receive = ajax.receiveResponse;
        ajax.receiveResponse = (...args) => new Promise((resolve) => {
          window.finishProgressUpload = () => resolve(receive.apply(ajax, args));
        });
      }, { once: true });
      const widget = document.querySelector('.js-form-managed-file');
      const observer = new MutationObserver(() => {
        const bar = widget.querySelector('.ajax-progress-bar');
        const description = bar?.querySelector('.progress__description');
        if (description) {
          window.managedFileProgressMessage = description.textContent.trim();
          bar.style.cssText = 'display:none; padding: 8px 0; margin: 10px 0';
          const animate = bar.animate;
          bar.animate = (frames, options) => {
            window.managedFileProgressFrames = frames;
            return animate.call(bar, frames, options);
          };
          observer.disconnect();
        }
      });
      observer.observe(widget, { childList: true, subtree: true });
      JS);
    $session->getPage()->attachFileToField('files[file]', $file_system->realpath($filename));
    $this->assertJsCondition(<<<'JS'
      (() => {
        const frames = window.managedFileProgressFrames;
        return typeof window.finishProgressUpload === 'function' && frames?.[0].height === '0' &&
          frames[0].paddingTop === '0' && frames[0].marginBottom === '0' &&
          frames[1].paddingTop === '8px' && frames[1].marginBottom === '10px';
      })()
      JS);
    $session->executeScript('window.finishProgressUpload();');
    $this->assertNotNull($this->assertSession()->waitForLink(basename($filename)));
    $this->assertSame('Processing...', $session->evaluateScript('window.managedFileProgressMessage'));
    $this->assertSession()->elementNotExists('css', '.js-form-managed-file .ajax-progress-bar');
    $this->assertGreaterThan(0, $this->getLastFileId());
  }

  /**
   * Tests rejecting files in the browser without clearing another field's error.
   */
  public function testClientValidation(): void {
    $file_system = $this->container->get('file_system');
    $wrong_extension = $file_system->tempnam('temporary://', 'invalid') . '.jpg';
    $valid = $file_system->tempnam('temporary://', 'valid') . '.txt';
    file_put_contents($wrong_extension, 'wrong extension');
    file_put_contents($valid, 'valid');

    $this->drupalGet('file/test/0/0/1', ['query' => ['validate_upload' => 1]]);
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $file = '.js-form-managed-file:has(input[name="files[file][]"])';
    $other = '.js-form-managed-file:has(input[name="files[other_file][]"])';
    $fid = $this->getLastFileId();

    $page->attachFileToField('files[file][]', $file_system->realpath($wrong_extension));
    $assert->elementTextContains('css', "$file .file-upload-js-error", 'Only files with the following extensions are allowed');
    $assert->fieldValueEquals('files[file][]', '');
    $page->attachFileToField('files[other_file][]', $file_system->realpath($wrong_extension));
    $assert->elementTextContains('css', "$other .file-upload-js-error", 'Only files with the following extensions are allowed');
    $assert->fieldValueEquals('files[other_file][]', '');
    $assert->elementExists('css', "$file .file-upload-js-error");
    $this->assertSame($fid, $this->getLastFileId());

    // Use the real multiple input and let the change event upload the valid
    // file. Verify the rejected filename is still shown after replacement.
    $this->getSession()->executeScript(<<<'JS'
      const input = document.querySelector('input[name="files[file][]"]');
      const selection = new DataTransfer();
      selection.items.add(new File(['valid'], 'valid-extension.txt'));
      selection.items.add(new File(['invalid'], 'invalid.jpg'));
      input.files = selection.files;
      input.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
      JS);
    $this->assertNotNull($assert->waitForLink('valid-extension.txt'));
    $assert->assertWaitOnAjaxRequest();
    $assert->linkNotExists('invalid.jpg');
    $assert->elementTextContains('css', "$file .file-upload-js-error", 'invalid.jpg');
    $assert->elementTextContains('css', "$file .file-upload-js-error", 'following extensions are allowed');

    // A managed file button disables only other inputs in the same form.
    $this->getSession()->executeScript(<<<'JS'
      const upload = document.querySelector('[data-drupal-selector$="file-upload-button"]');
      const otherInput = upload.form.querySelector('input[name="files[other_file][]"]');
      const extraForm = document.createElement('form');
      extraForm.innerHTML = '<div class="js-form-managed-file"><input class="js-form-file" type="file"></div>';
      document.body.append(extraForm);
      const extraInput = extraForm.querySelector('input');
      Drupal.file.disableFields({ currentTarget: upload });
      window.onlySubmittingFormLocked = otherInput.disabled && !extraInput.disabled;
      extraForm.remove();
      JS);
    $this->assertJsCondition('window.onlySubmittingFormLocked');

    $page->attachFileToField('files[file][]', $file_system->realpath($valid));
    $this->assertNotNull($assert->waitForLink(basename($valid)));
    $assert->elementNotExists('css', "$file .file-upload-js-error");
    $assert->elementExists('css', "$other .file-upload-js-error");
  }

  /**
   * Tests that whitespace-only extensions do not enable browser validation.
   */
  public function testBlankExtensions(): void {
    $this->drupalGet('file/test/0/0/1', ['query' => ['blank_extensions' => 1]]);
    $assert = $this->assertSession();
    $assert->elementNotExists('css', 'input[name="files[file][]"][data-file-extensions]');

    // Exercise the client guard even if a form provides a blank attribute.
    $this->assertJsCondition(<<<'JS'
      (() => {
        const input = document.querySelector('input[name="files[file][]"]');
        const selection = new DataTransfer();
        selection.items.add(new File(['valid'], 'valid.txt'));
        input.files = selection.files;
        input.dataset.fileExtensions = '   ';
        input.addEventListener('validate-extension', Drupal.file.validateExtension, { once: true });
        input.dispatchEvent(new Event('validate-extension', { cancelable: true }));
        return input.files.length === 1;
      })()
      JS);
    $assert->elementNotExists('css', '.file-upload-js-error');
  }

  /**
   * Tests cleanup when a mixed upload leaves the widget on the page.
   */
  public function testMixedUploadInsertCleanup(): void {
    $this->drupalGet('file/test/0/0/1', ['query' => ['validate_upload' => 1]]);
    $session = $this->getSession();
    $result = $session->evaluateScript(<<<'JS'
      (async () => {
        const input = document.querySelector('input[name="files[file][]"]');
        const widget = input.closest('.js-form-managed-file');
        const button = widget.querySelector('[data-drupal-selector$="upload-button"]');
        const ajax = Drupal.ajax.instances.find((instance) => instance?.element === button);
        const { beforeSend, success } = ajax;
        const selectFiles = () => {
          const selection = new DataTransfer();
          selection.items.add(new File(['valid'], 'valid.txt'));
          selection.items.add(new File(['invalid'], 'invalid.jpg'));
          input.files = selection.files;
          input.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
        };

        try {
          ajax.beforeSend = () => false;
          selectFiles();
          const afterCancellation = Object.hasOwn(ajax.commands, 'insert');
          ajax.beforeSend = beforeSend;

          ajax.success = () => success.call(ajax, [
            { command: 'insert', selector: '.file-upload-js-error', method: 'append', data: '<span></span>' },
          ]);
          const completed = new Promise((resolve) => {
            document.addEventListener('drupal:ajax-complete', resolve, { once: true });
          });
          selectFiles();
          await completed;
          return [afterCancellation, Object.hasOwn(ajax.commands, 'insert'), widget.isConnected];
        }
        finally {
          ajax.beforeSend = beforeSend;
          ajax.success = success;
        }
      })()
      JS);
    $this->assertSame([FALSE, FALSE, TRUE], $result);
    $this->assertSession()->elementTextContains('css', '.file-upload-js-error', 'invalid.jpg');
  }

  /**
   * Retrieves the fid of the last inserted file.
   */
  protected function getLastFileId(): int {
    return (int) \Drupal::entityQueryAggregate('file')
      ->accessCheck(FALSE)
      ->aggregate('fid', 'max')
      ->execute()[0]['fid_max'];
  }

}
