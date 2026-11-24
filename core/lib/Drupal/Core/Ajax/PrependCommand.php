<?php

namespace Drupal\Core\Ajax;

/**
 * An Ajax command for prepending content to each match.
 *
 * The 'insert/prepend' command operates on elements that match the selector.
 *
 * @see Drupal.AjaxCommands.prototype.insert
 */
class PrependCommand extends InsertCommand {

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {

    return [
      'command' => 'insert',
      'method' => 'prepend',
      'selector' => $this->selector,
      'data' => $this->getRenderedContent(),
      'settings' => $this->settings,
    ];
  }

}
