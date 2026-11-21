<?php

namespace Drupal\Core\Ajax;

/**
 * AJAX command for invoking an arbitrary jQuery method.
 *
 * The 'invoke' command will instruct the client to invoke the given jQuery
 * method with the supplied arguments on the elements matched by the given
 * selector. Intended for simple jQuery commands, such as attr(), addClass(),
 * removeClass(), toggleClass(), etc.
 *
 * This command is implemented by Drupal.AjaxCommands.prototype.invoke()
 * defined in misc/ajax.js.
 *
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a native
 *   command for the operation, or define an explicit command in your extension.
 *
 * @see https://www.drupal.org/node/CHANGE_RECORD
 *
 * @ingroup ajax
 */
class InvokeCommand implements CommandInterface {

  /**
   * Constructs an InvokeCommand object.
   *
   * @param string $selector
   *   A jQuery selector.
   * @param string $method
   *   The name of a jQuery method to invoke.
   * @param array $arguments
   *   An optional array of arguments to pass to the method.
   */
  public function __construct(
    protected $selector,
    protected $method,
    protected array $arguments = [],
  ) {
  }

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {
    @trigger_error(__CLASS__ . ' is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a native command for the operation, or define an explicit command in your extension. See https://www.drupal.org/node/CHANGE_RECORD.', E_USER_DEPRECATED);
    return [
      'command' => 'invoke',
      'selector' => $this->selector,
      'method' => $this->method,
      'args' => $this->arguments,
    ];
  }

}
