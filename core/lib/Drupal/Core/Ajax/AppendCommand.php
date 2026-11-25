<?php

namespace Drupal\Core\Ajax;

/**
 * An Ajax command for appending content to each match.
 *
 * The 'insert/append' command operates on elements that match the selector.
 *
 * @see Drupal.AjaxCommands.prototype.insert
 */
class AppendCommand extends InsertCommand {

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {

    return [
      'command' => 'insert',
      'method' => 'append',
      'selector' => $this->selector,
      'data' => $this->getRenderedContent(),
      'settings' => $this->settings,
    ];
  }

}
