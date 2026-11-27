<?php

declare(strict_types=1);

namespace Drupal\Core\Ajax;

/**
 * Sets or removes an attribute on each matched element.
 *
 * @ingroup ajax
 */
class SetAttributeCommand implements CommandInterface {

  /**
   * Constructs a SetAttributeCommand object.
   *
   * @param string $selector
   *   A CSS selector.
   * @param string $name
   *   The attribute name.
   * @param string|null $value
   *   The attribute value or NULL to remove it.
   */
  public function __construct(
    protected string $selector,
    protected string $name,
    protected ?string $value,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'setAttribute',
      'selector' => $this->selector,
      'name' => $this->name,
      'value' => $this->value,
    ];
  }

}
