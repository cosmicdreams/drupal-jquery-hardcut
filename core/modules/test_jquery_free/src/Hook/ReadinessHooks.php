<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\test_jquery_free\ReadinessScanner;

/**
 * Adds the status report entry and attaches the browser canary.
 */
final class ReadinessHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ReadinessScanner $scanner,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $findings = $this->scanner->cachedFindings();
    $count = count($findings ?? []);
    return [
      'test_jquery_free' => [
        'title' => $this->t('jQuery readiness'),
        'value' => $findings === NULL ? $this->t('Scan needed') : $this->formatPlural($count, '1 extension with possible jQuery dependencies', '@count extensions with possible jQuery dependencies'),
        'description' => Link::createFromRoute($this->t('View the jQuery readiness report'), 'test_jquery_free.report')->toRenderable(),
        'severity' => match (TRUE) {
          $findings === NULL => RequirementSeverity::Info,
          $count > 0 => RequirementSeverity::Warning,
          default => RequirementSeverity::OK,
        },
      ],
    ];
  }

  /**
   * Invalidates findings after extensions are installed or uninstalled.
   */
  #[Hook('modules_installed')]
  #[Hook('modules_uninstalled')]
  #[Hook('themes_installed')]
  #[Hook('themes_uninstalled')]
  public function invalidateReport(): void {
    $this->scanner->invalidate();
  }

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['library'][] = 'test_jquery_free/canary';
  }

}
