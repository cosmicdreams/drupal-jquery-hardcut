<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\test_jquery_free\ReadinessScanner;

/**
 * Displays the jQuery readiness report.
 */
final class ReadinessReportController extends ControllerBase {

  public function __construct(
    private readonly ReadinessScanner $scanner,
  ) {}

  /**
   * Builds a table of jQuery findings.
   */
  public function report(): array {
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Extension'),
        $this->t('Library or file'),
        $this->t('Line'),
        $this->t('Reason'),
      ],
      '#rows' => array_map('array_values', $this->scanner->rows()),
      '#empty' => $this->t('No jQuery references found in enabled contributed or custom extensions.'),
      '#cache' => ['max-age' => 0],
    ];
  }

}
