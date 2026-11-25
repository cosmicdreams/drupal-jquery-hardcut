<?php

namespace Drupal\Core\Ajax;

/**
 * An Ajax command for replacing each match with new content.
 *
 * The 'insert/replaceWith' command replaces the elements matching the selector.
 *
 * @see Drupal.AjaxCommands.prototype.insert
 */
class ReplaceCommand extends InsertCommand {

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {

    return [
      'command' => 'insert',
      'method' => 'replaceWith',
      'selector' => $this->selector,
      'data' => $this->getRenderedContent(),
      'settings' => $this->settings,
    ];
  }

}
