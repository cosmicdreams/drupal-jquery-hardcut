<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests effective asset resolution after a non-interactive profile install.
 */
#[Group('Asset')]
#[RunTestsInSeparateProcesses]
class InstalledProfileAssetsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    mkdir($this->siteDirectory . '/files/config/sync', 0775, TRUE);
    $settings = Settings::getAll();
    $settings['config_sync_directory'] = $this->siteDirectory . '/files/config/sync';
    $databases = ['default' => $this->getDatabaseConnectionInfo()];
    file_put_contents($this->siteDirectory . '/settings.php', '<?php' . "\n"
      . '$settings = ' . var_export($settings, TRUE) . ";\n"
      . '$databases = ' . var_export($databases, TRUE) . ";\n"
      . '$config["system.advisories"]["enabled"] = FALSE;' . "\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function bootKernel(): void {
    // The installer boots the kernel once the site settings are in place.
  }

  /**
   * Tests ordered dependencies and resources under the installed themes.
   */
  #[TestWith(['minimal', 'stark', ''], 'Minimal default')]
  #[TestWith(['minimal', 'stark', '', 'starterkit_theme'], 'Minimal with Starterkit')]
  #[TestWith(['standard', 'default_admin', 'default_admin'], 'Standard default and admin')]
  #[TestWith(['demo_umami', 'umami', 'default_admin'], 'Umami default')]
  #[TestWith(['demo_umami', 'umami', 'default_admin', 'default_admin'], 'Umami admin')]
  public function testInstalledProfileResolution(string $profile, string $default_theme, string $admin_theme, ?string $theme = NULL): void {
    require_once 'core/includes/install.core.inc';
    $error_handler = get_error_handler();
    try {
      install_drupal($this->classLoader, [
        'site_path' => $this->siteDirectory,
        'parameters' => ['profile' => $profile, 'langcode' => 'en'],
        'forms' => [
          'install_configure_form' => [
            'site_name' => 'Asset resolution test',
            'site_mail' => 'admin@example.com',
            'account' => [
              'name' => 'admin',
              'mail' => 'admin@example.com',
              'pass' => ['pass1' => 'admin', 'pass2' => 'admin'],
            ],
          ],
        ],
      ]);
    }
    finally {
      set_error_handler($error_handler);
    }
    $this->container = \Drupal::getContainer();

    $this->assertSame('done', $this->container->get('state')->get('install_task'));
    $this->assertSame($profile, \Drupal::installProfile());
    $themes = $this->config('system.theme');
    $this->assertSame($default_theme, $themes->get('default'));
    $this->assertSame($admin_theme, $themes->get('admin'));
    $theme ??= $default_theme;
    if ($theme !== $default_theme && $theme !== $admin_theme) {
      $this->container->get('theme_installer')->install([$theme]);
    }
    $active_theme = $this->container->get('theme.initialization')->initTheme($theme);
    $this->container->get('theme.manager')->setActiveTheme($active_theme);
    $this->container->get('library.discovery')->clear();

    $attachments = ['core/drupal.announce', 'core/drupal.dropbutton', 'system/base'];
    $libraries = $this->container->get('library.dependency_resolver')->getLibrariesWithDependencies($attachments);
    $assets = AttachedAssets::createFromRenderArray(['#attached' => ['library' => $attachments]]);
    $resolver = $this->container->get('asset.resolver');
    $this->container->get('cache.data')->deleteAll();
    $this->container->get('cache.memory')->deleteAll();
    $css = $resolver->getCssAssets($assets, FALSE);
    [$header, $footer] = $resolver->getJsAssets($assets, FALSE);
    $this->assertSame([], $header);

    $this->container->get('cache.memory')->deleteAll();
    $this->assertSame($css, $resolver->getCssAssets(clone $assets, FALSE));
    $this->assertSame([$header, $footer], $resolver->getJsAssets(clone $assets, FALSE));

    $fields = array_flip([
      'data', 'library', 'type', 'category', 'media', 'group', 'weight', 'scope',
      'preprocess', 'version', 'minified', 'attributes',
    ]);
    $metadata = static fn (array $assets): array => array_values(array_map(
      static function (array $asset) use ($fields): array {
        if ($asset['version'] === \Drupal::VERSION) {
          $asset['version'] = 'VERSION';
        }
        return array_intersect_key($asset, $fields);
      },
      $assets,
    ));
    unset($footer['drupalSettings']);
    $actual = ['libraries' => $libraries, 'css' => $metadata($css), 'js' => $metadata($footer)];
    // The fixture records resolution at 92e5c3a3047.
    $baseline = Json::decode(file_get_contents(__DIR__ . '/fixtures/effective-assets-pinned.json'));
    $expected = $baseline['profiles']["$profile:$theme"];
    // The explicit compatibility library restores the alias and connects its
    // adapter immediately after the distribution.
    foreach ($expected['js'] as $index => $asset) {
      if ($asset['data'] === 'core/assets/vendor/jquery/jquery.min.js') {
        $bootstrap = $asset;
        $bootstrap['data'] = 'core/misc/jquery.no-conflict.js';
        $bootstrap['minified'] = FALSE;
        $adapter = $bootstrap;
        $adapter['data'] = 'core/misc/jquery.drupal-bc.js';
        array_splice($expected['js'], $index + 1, 0, [$bootstrap, $adapter]);
        break;
      }
    }
    $this->assertSame($expected, $actual);
  }

}
