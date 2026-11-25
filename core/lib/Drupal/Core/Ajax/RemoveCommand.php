<?php

namespace Drupal\Core\Ajax;

/**
 * An Ajax command for removing each match.
 *
 * The 'remove' command operates on elements that match the selector.
 *
 * @see Drupal.AjaxCommands.prototype.remove
 */
class RemoveCommand implements CommandInterface {

  /**
   * Constructs a RemoveCommand object.
   *
   * @param string $selector
   *   The selector.
   */
  public function __construct(
    protected $selector,
  ) {
  }

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {
    return [
      'command' => 'remove',
      'selector' => $this->selector,
    ];
  }

}
