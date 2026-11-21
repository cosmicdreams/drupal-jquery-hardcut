<?php

declare(strict_types=1);

namespace Drupal\Core\Ajax;

/**
 * Sets the value of a form control.
 *
 * @ingroup ajax
 */
class SetValueCommand implements CommandInterface {

  /**
   * Constructs a SetValueCommand object.
   *
   * @param string $selector
   *   A CSS selector.
   * @param string $value
   *   The value to set.
   */
  public function __construct(
    protected string $selector,
    protected string $value,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'setValue',
      'selector' => $this->selector,
      'value' => $this->value,
    ];
  }

}
