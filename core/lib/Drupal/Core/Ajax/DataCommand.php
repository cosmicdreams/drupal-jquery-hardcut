<?php

namespace Drupal\Core\Ajax;

/**
 * Stores data on elements through Drupal.ajax.elementData.
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
   */
  public function __construct(
    protected $selector,
    protected $name,
    protected $value,
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
    ];
  }

}
