<?php

declare(strict_types=1);

namespace Drupal\Core\Ajax;

/**
 * Dispatches a bubbling, cancelable event on each matched element.
 *
 * @ingroup ajax
 */
class DispatchEventCommand implements CommandInterface {

  /**
   * Constructs a DispatchEventCommand object.
   *
   * @param string $selector
   *   A CSS selector.
   * @param string $event
   *   The browser event name.
   */
  public function __construct(
    protected string $selector,
    protected string $event,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'dispatchEvent',
      'selector' => $this->selector,
      'event' => $this->event,
    ];
  }

}
