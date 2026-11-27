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
   *
   * The Nightwatch version returned a render array, so it rendered through the
   * newly installed theme in the request that installed it. Installing a theme
   * rebuilds the container mid-request, and with Default Admin that rendering
   * failed with an ArgumentCountError from PreprocessHooks. The earlier fix
   * for #2538970 worked around a related same-request theme negotiation
   * problem. A plain JSON response avoids rendering until the next request.
   *
   * @see https://www.drupal.org/i/2538970
   * @see https://www.drupal.org/i/3293469
   */
  private function installTheme(string $theme, string $role): JsonResponse {
    $this->themeInstaller->install([$theme]);
    $this->themeConfigFactory->getEditable('system.theme')->set($role, $theme)->save();
    return new JsonResponse(['theme' => $theme, 'role' => $role]);
  }

}
