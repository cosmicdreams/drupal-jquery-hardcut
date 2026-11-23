<?php

namespace Drupal\Core\Ajax;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Xss;

/**
 * Defines an AJAX command to open certain content in a dialog.
 *
 * @ingroup ajax
 */
class OpenDialogCommand implements CommandInterface, CommandWithAttachedAssetsInterface {

  use CommandWithAttachedAssetsTrait;

  /**
   * The title of the dialog.
   *
   * @var string
   */
  protected $title;

  /**
   * Stores options passed to Drupal.dialog.
   *
   * @var array
   */
  protected $dialogOptions;

  /**
   * Constructs an OpenDialogCommand object.
   *
   * @param string $selector
   *   The selector of the dialog.
   * @param string|\Stringable|null $title
   *   The title of the dialog. Markup objects are converted to plain text.
   * @param string|array $content
   *   The content that will be placed in the dialog, either a render array
   *   or an HTML string.
   * @param array $dialog_options
   *   (optional) Options to be passed to the dialog implementation.
   * @param array|null $settings
   *   (optional) Custom settings that will be passed to the Drupal behaviors
   *   on the content of the dialog. If left empty, the settings will be
   *   populated automatically from the current request.
   */
  public function __construct(
    protected $selector,
    string|\Stringable|null $title,
    protected $content,
    array $dialog_options = [],
    protected $settings = NULL,
  ) {
    $dialog_options += ['title' => $title];

    $this->dialogOptions = $dialog_options;
  }

  /**
   * Returns the dialog options.
   *
   * @return array
   *   An array of dialog options.
   */
  public function getDialogOptions() {
    return $this->dialogOptions;
  }

  /**
   * Sets the dialog options array.
   *
   * @param array $dialog_options
   *   Options to be passed to the dialog implementation.
   */
  public function setDialogOptions($dialog_options) {
    $this->dialogOptions = $dialog_options;
  }

  /**
   * Sets a single dialog option value.
   *
   * @param string $key
   *   Key of the dialog option.
   * @param mixed $value
   *   Option to be passed to the dialog implementation.
   */
  public function setDialogOption($key, $value) {
    $this->dialogOptions[$key] = $value;
  }

  /**
   * Sets the dialog title (an alias of setDialogOptions).
   *
   * @param string $title
   *   The new title of the dialog.
   */
  public function setDialogTitle($title) {
    $this->setDialogOption('title', $title);
  }

  /**
   * Implements \Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {
    // For consistency ensure the modal option is set to TRUE or FALSE.
    $this->dialogOptions['modal'] = isset($this->dialogOptions['modal']) && $this->dialogOptions['modal'];

    if (!empty($this->dialogOptions['buttons'])) {
      foreach ($this->dialogOptions['buttons'] as &$button) {
        // Only allow specific attributes to be defined for a button.
        $button = \array_intersect_key($button, \array_flip(['disabled', 'icons', 'label', 'text']));
        foreach ($button as &$value) {
          if (is_string($value)) {
            // Apply Xss::filter to button attribute values.
            $value = Xss::filter($value);
          }
        }
      }
    }

    // The client renders dialog titles as text. Convert markup titles once.
    $options = $this->dialogOptions;
    if (array_key_exists('title', $options)) {
      $title = $options['title'];
      $options['title'] = match (TRUE) {
        $title instanceof MarkupInterface => PlainTextOutput::renderFromHtml($title),
        is_scalar($title), $title instanceof \Stringable => (string) $title,
        default => '',
      };
    }

    return [
      'command' => 'openDialog',
      'selector' => $this->selector,
      'settings' => $this->settings,
      'data' => $this->getRenderedContent(),
      'dialogOptions' => $options,
    ];
  }

}
