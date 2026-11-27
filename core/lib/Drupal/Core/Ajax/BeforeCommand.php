<?php

namespace Drupal\Core\Ajax;

/**
 * An Ajax command for inserting content before each match.
 *
 * The 'insert/before' command operates on elements that match the selector.
 *
 * @see Drupal.AjaxCommands.prototype.insert
 */
class BeforeCommand extends InsertCommand {

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {

    return [
      'command' => 'insert',
      'method' => 'before',
      'selector' => $this->selector,
      'data' => $this->getRenderedContent(),
      'settings' => $this->settings,
    ];
  }

}
