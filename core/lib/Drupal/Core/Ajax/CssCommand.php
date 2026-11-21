<?php

namespace Drupal\Core\Ajax;

/**
 * An AJAX command for setting inline CSS properties.
 *
 * Set $native to TRUE to use CSS selectors and absolute property values.
 * Relative values and jQuery CSS hooks require the core/drupal.ajax.jquery
 * compatibility library. Absolute values with CSS selectors work without it.
 *
 * @see Drupal.AjaxCommands.prototype.css()
 *
 * @ingroup ajax
 */
class CssCommand implements CommandInterface {

  /**
   * Constructs a CssCommand object.
   *
   * @param string $selector
   *   A CSS selector for elements to which the CSS will be applied.
   * @param array $css
   *   An array of CSS property/value pairs to set.
   * @param bool $native
   *   Whether to use native selectors and command semantics.
   */
  public function __construct(
    protected $selector,
    protected array $css = [],
    protected bool $native = FALSE,
  ) {
  }

  /**
   * Adds a property/value pair to the CSS to be added to this element.
   *
   * @param string $property
   *   The CSS property to be changed.
   * @param string $value
   *   The new value of the CSS property.
   *
   * @return $this
   */
  public function setProperty($property, $value) {
    $this->css[$property] = $value;
    return $this;
  }

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {
    if (!$this->native) {
      @trigger_error('CssCommand without native: TRUE is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use native: TRUE with CSS selectors and absolute values. See https://www.drupal.org/node/CHANGE_RECORD.', E_USER_DEPRECATED);
    }

    return [
      'command' => 'css',
      'selector' => $this->selector,
      'argument' => $this->css,
    ] + ($this->native ? ['native' => TRUE] : []);
  }

}
