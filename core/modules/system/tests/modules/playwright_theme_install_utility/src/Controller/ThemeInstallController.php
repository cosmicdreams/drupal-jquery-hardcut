<?php

declare(strict_types=1);

namespace Drupal\playwright_theme_install_utility\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Installs theme overrides on a disposable Playwright site.
 */
final class ThemeInstallController extends ControllerBase {

  /**
   * Constructs the theme install controller.
   */
  public function __construct(
    private readonly ThemeInstallerInterface $themeInstaller,
    private readonly ConfigFactoryInterface $themeConfigFactory,
  ) {}

  /**
   * Installs and selects the default theme.
   */
  public function installDefault(string $theme): JsonResponse {
    return $this->installTheme($theme, 'default');
  }

  /**
   * Installs and selects the administration theme.
   */
  public function installAdmin(string $theme): JsonResponse {
    return $this->installTheme($theme, 'admin');
  }

  /**
   * Installs and selects a theme without rendering it in the same request.
   */
  private function installTheme(string $theme, string $role): JsonResponse {
    $this->themeInstaller->install([$theme]);
    $this->themeConfigFactory->getEditable('system.theme')->set($role, $theme)->save();
    return new JsonResponse(['theme' => $theme, 'role' => $role]);
  }

}
