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
      const widget = document.querySelector('.js-form-managed-file');
      const observer = new MutationObserver(() => {
        const indicator = widget.querySelector('.ajax-progress-bar .progress__description');
        if (indicator) {
          window.managedFileProgressMessage = indicator.textContent.trim();
          observer.disconnect();
        }
      });
      observer.observe(widget, { childList: true, subtree: true });
      JS);
    $session->getPage()->attachFileToField('files[file]', $file_system->realpath($filename));
    $this->assertNotNull($this->assertSession()->waitForLink(basename($filename)));
    $this->assertSame('Processing...', $session->evaluateScript('window.managedFileProgressMessage'));
    $this->assertSession()->elementNotExists('css', '.js-form-managed-file .ajax-progress-bar');
    $this->assertGreaterThan(0, $this->getLastFileId());
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
