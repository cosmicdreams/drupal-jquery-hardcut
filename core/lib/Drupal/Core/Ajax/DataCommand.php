<?php

namespace Drupal\Core\Ajax;

/**
 * An AJAX command for associating a value with each matched element.
 *
 * Set $native to TRUE to use Drupal.ajax.elementData. The default jQuery data
 * behavior requires the core/drupal.ajax.jquery compatibility library.
 *
 * @see Drupal.AjaxCommands.prototype.data()
 *
 * @ingroup ajax
 */
class DataCommand implements CommandInterface {

  /**
   * Constructs a DataCommand object.
   *
   * @param string $selector
   *   A CSS selector for the elements to which the data will be attached.
   * @param string $name
   *   The key of the data to be attached to elements matched by the selector.
   * @param mixed $value
   *   The value of the data to be attached to elements matched by the selector.
   * @param bool $native
   *   Whether to use native selectors and command semantics.
   */
  public function __construct(
    protected $selector,
    protected $name,
    protected $value,
    protected bool $native = FALSE,
  ) {
  }

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {

    return [
      'command' => 'data',
      'selector' => $this->selector,
      'name' => $this->name,
      'value' => $this->value,
    ] + ($this->native ? ['native' => TRUE] : []);
  }

}
