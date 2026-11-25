<?php

declare(strict_types=1);

namespace Drupal\jqf_script\Controller;

/**
 * Serves a page that exercises the canary.
 */
final class ProbeController {

  /**
   * Attaches the console capture and test script.
   */
  public function page(): array {
    return [
      '#markup' => 'jQuery readiness test',
      '#attached' => ['library' => ['jqf_script/capture', 'jqf_script/script']],
    ];
  }

}
