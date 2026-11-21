<?php

declare(strict_types=1);

namespace Drupal\Core\Ajax;

/**
 * Adds or removes a class according to the given state.
 *
 * @ingroup ajax
 */
class ToggleClassCommand implements CommandInterface {

  /**
   * Constructs a ToggleClassCommand object.
   *
   * @param string $selector
   *   A CSS selector.
   * @param string $className
   *   The class name.
   * @param bool $enabled
   *   Whether the class should be present.
   */
  public function __construct(
    protected string $selector,
    protected string $className,
    protected bool $enabled,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'toggleClass',
      'selector' => $this->selector,
      'className' => $this->className,
      'enabled' => $this->enabled,
    ];
  }

}
