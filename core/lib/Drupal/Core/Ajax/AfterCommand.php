<?php

namespace Drupal\Core\Ajax;

/**
 * An Ajax command for inserting content after each match.
 *
 * The 'insert/after' command operates on elements that match the selector.
 *
 * @see Drupal.AjaxCommands.prototype.insert
 */
class AfterCommand extends InsertCommand {

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {

    return [
      'command' => 'insert',
      'method' => 'after',
      'selector' => $this->selector,
      'data' => $this->getRenderedContent(),
      'settings' => $this->settings,
    ];
  }

}
