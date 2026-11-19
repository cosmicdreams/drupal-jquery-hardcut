<?php

declare(strict_types=1);

namespace Drupal\jquery_bc_test\Controller;

/**
 * Provides an isolated page for the event bridge prototype.
 *
 * @internal
 */
final class EventBridgeController {

  /**
   * Builds the fixture page with or without the explicit bridge library.
   */
  public function build(bool $legacy): array {
    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'event-bridge-fixture'],
      '#attached' => [
        'library' => [$legacy ? 'jquery_bc_test/event_bridge' : 'core/drupal'],
      ],
    ];
  }

}
