<?php

namespace Drupal\Core\Ajax;

/**
 * An Ajax command for replacing the contents of each match.
 *
 * The 'insert/html' command operates on elements that match the selector.
 *
 * @see Drupal.AjaxCommands.prototype.insert
 */
class HtmlCommand extends InsertCommand {

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {

    return [
      'command' => 'insert',
      'method' => 'html',
      'selector' => $this->selector,
      'data' => $this->getRenderedContent(),
      'settings' => $this->settings,
    ];
  }

}
