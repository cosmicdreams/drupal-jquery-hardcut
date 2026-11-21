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
  public function build(bool $legacy, bool $early = FALSE): array {
    $library = $early ? 'jquery_bc_test/event_bridge_early' : 'jquery_bc_test/event_bridge_late';
    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'event-bridge-fixture'],
      '#attached' => [
        'library' => [$legacy ? $library : 'core/drupal'],
      ],
    ];
  }

}
