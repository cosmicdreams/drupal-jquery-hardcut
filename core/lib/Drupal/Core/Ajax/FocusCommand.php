<?php

declare(strict_types=1);

namespace Drupal\Core\Ajax;

/**
 * Focuses the elements matching a selector.
 *
 * @ingroup ajax
 */
class FocusCommand implements CommandInterface {

  /**
   * Constructs a FocusCommand object.
   *
   * @param string $selector
   *   A CSS selector.
   */
  public function __construct(
    protected string $selector,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'focus',
      'selector' => $this->selector,
    ];
  }

}
