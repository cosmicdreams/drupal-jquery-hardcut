<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\test_jquery_free\Form\ReadinessRescanForm;
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
    $rows = $this->scanner->cachedRows();
    return [
      'advisory' => [
        '#prefix' => '<p>',
        '#plain_text' => $this->t('Advisory: static analysis is best-effort. Enable this module and exercise the site to confirm, using the browser console canary.'),
        '#suffix' => '</p>',
      ],
      'rescan' => $this->formBuilder()->getForm(ReadinessRescanForm::class),
      'report' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Extension'),
          $this->t('Library or file'),
          $this->t('Line'),
          $this->t('Theme'),
          $this->t('Reason'),
        ],
        '#rows' => array_map('array_values', $rows ?? []),
        '#empty' => $rows === NULL
          ? $this->t('No scan yet')
          : $this->t('No jQuery references found in enabled contributed or custom extensions.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
