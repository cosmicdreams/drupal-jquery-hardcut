<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Asset;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\KernelTests\KernelTestBase;
use Drupal\system\Controller\CssAssetController;
use Drupal\system\Controller\JsAssetController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests effective resolution and aggregate delivery for render attachments.
 */
#[Group('Asset')]
#[RunTestsInSeparateProcesses]
class EffectiveAssetResolutionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'theme_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    // The aggregate controller returns a BinaryFileResponse for existing files.
    mkdir($this->siteDirectory . '/files/config/sync', 0775, TRUE);
    $this->setSetting('file_public_path', $this->siteDirectory . '/files');
    $this->setSetting('config_sync_directory', $this->siteDirectory . '/files/config/sync');
  }

  /**
   * Tests effective inheritance of theme library extensions and overrides.
   */
  public function testInheritedThemeResolution(): void {
    $this->container->get('theme_installer')->install(['test_subsubtheme']);
    $theme_initialization = $this->container->get('theme.initialization');
    $theme = $theme_initialization->initTheme('test_subsubtheme');
    $this->container->get('theme.manager')->setActiveTheme($theme);
    $this->assertSame(['test_subtheme', 'test_base_theme'], array_keys($theme->getBaseThemeExtensions()));
    $this->container->get('library.discovery')->clear();

    $libraries = ['core/loadjs', 'test_base_theme/base', 'test_base_theme/global-styling'];
    $this->assertSame([
      'core/loadjs',
      'test_base_theme/base',
      'test_base_theme/global-styling',
    ], $this->container->get('library.dependency_resolver')->getLibrariesWithDependencies($libraries));

    $assets = (new AttachedAssets())->setLibraries($libraries);
    $resolver = $this->container->get('asset.resolver');
    $this->container->get('cache.data')->deleteAll();
    $this->container->get('cache.memory')->deleteAll();
    $css = $resolver->getCssAssets($assets, FALSE);
    [$header, $footer] = $resolver->getJsAssets($assets, FALSE);
    $this->assertSame([], $header);
    $replacement = 'core/modules/system/tests/themes/test_base_theme/js/loadjs.min.js';
    $this->assertSame([$replacement], array_keys($footer));
    $this->assertTrue($footer[$replacement]['minified']);
    $this->assertSame('core/loadjs', $footer[$replacement]['library']);
    $this->assertSame([
      'test_base_theme/base:core/modules/system/tests/themes/test_subtheme/css/sub-add.css:base:-200',
      'test_base_theme/base:core/modules/system/tests/themes/test_subtheme/css/same-name.css:base:-200',
      'test_base_theme/base:core/modules/system/tests/themes/test_subtheme/css/sub-libraries-extend.css:base:-200',
      'test_base_theme/global-styling:core/modules/system/tests/themes/test_base_theme/base-add.css:base:-200',
      'test_base_theme/global-styling:core/modules/system/tests/themes/test_base_theme/same-name.css:base:-200',
      'test_base_theme/global-styling:core/modules/system/tests/themes/test_base_theme/css/base-libraries-extend.css:base:-200',
      'test_base_theme/base:core/modules/system/tests/themes/test_base_theme/css/components/button.css:component:0',
      'test_base_theme/base:core/modules/system/tests/themes/test_base_theme/css/components/container-inline.css:component:0',
      'test_base_theme/base:core/modules/system/tests/themes/test_base_theme/css/components/details.css:component:0',
    ], self::cssMetadata($css));

    $this->container->get('cache.memory')->deleteAll();
    $this->assertSame($css, $resolver->getCssAssets(clone $assets, FALSE));
    $this->assertSame([$header, $footer], $resolver->getJsAssets(clone $assets, FALSE));
  }

  /**
   * Returns ordered stylesheet metadata without incidental resolver fields.
   */
  private static function cssMetadata(array $css): array {
    return array_values(array_map(static fn (array $asset): string =>
      implode(':', [$asset['library'], $asset['data'], $asset['category'], $asset['weight']]),
      $css,
    ));
  }

  /**
   * Tests constituent provenance in lazy aggregates and their responses.
   */
  #[TestWith(['neutral'], 'neutral render attachment')]
  #[TestWith(['already-loaded'], 'shared libraries already loaded')]
  #[TestWith(['explicit-legacy'], 'explicit legacy render attachment')]
  public function testAggregateProvenance(string $context): void {
    $already_loaded = $context === 'already-loaded';
    $legacy = $context === 'explicit-legacy';
    $loaded = $already_loaded ? ['core/drupal.checkbox', 'system/base'] : [];
    // The fixture records groups at 92e5c3a3047.
    $baseline = Json::decode(file_get_contents(__DIR__ . '/fixtures/effective-assets-pinned.json'));
    $expected_groups = $baseline['groups'][$context];
    $this->enableModules(['common_test']);
    $this->container->get('theme_installer')->install(['stark']);
    $theme = $this->container->get('theme.initialization')->initTheme('stark');
    $this->container->get('theme.manager')->setActiveTheme($theme);
    $this->container->get('library.discovery')->clear();

    $libraries = ['common_test/aggregation', 'core/drupal.checkbox', 'system/base', 'common_test/external'];
    $legacy_library = 'core/internal.jquery.form';
    if ($legacy) {
      $libraries[] = $legacy_library;
    }
    $build['content'] = ['#markup' => 'Asset provenance', '#attached' => ['library' => $libraries]];
    $this->container->get('renderer')->renderRoot($build);
    $assets = AttachedAssets::createFromRenderArray($build);
    $this->assertSame($libraries, $assets->getLibraries());
    if ($already_loaded) {
      $assets->setAlreadyLoadedLibraries($loaded);
      // The lazy optimizer takes the exclusion list from the request.
      $this->container->get('request_stack')->getCurrentRequest()->attributes
        ->set('ajax_page_state', ['libraries' => implode(',', $loaded)]);
    }

    $resolver = $this->container->get('asset.resolver');
    $this->container->get('cache.data')->deleteAll();
    $this->container->get('cache.memory')->deleteAll();
    $css = $resolver->getCssAssets($assets, FALSE);
    [$header, $footer] = $resolver->getJsAssets($assets, FALSE);
    $this->assertSame([], $header);
    $this->assertSame(!$already_loaded, isset($css['core/modules/system/css/components/align.module.css']));
    $this->assertArrayHasKey('core/assets/vendor/normalize-css/normalize.css', $css);
    $this->assertSame(!$already_loaded, isset($footer['core/misc/drupal.js']));
    $this->assertSame(!$already_loaded, isset($footer['core/misc/checkbox.js']));
    $this->assertArrayHasKey('core/misc/debounce.js', $footer);
    $this->assertSame($legacy, isset($footer['core/assets/vendor/jquery/jquery.min.js']));
    $this->assertSame($legacy, isset($footer['core/misc/jquery.no-conflict.js']));
    $this->assertSame($legacy, isset($footer['core/misc/jquery.drupal-bc.js']));
    $this->assertSame($legacy, isset($footer['core/misc/jquery.form.js']));

    $optimized_css = $resolver->getCssAssets($assets, TRUE);
    [$optimized_header, $optimized_footer] = $resolver->getJsAssets($assets, TRUE);
    $this->assertSame([], $optimized_header);
    // Resolve on a fresh attachment object with the same libraries.
    $this->container->get('cache.memory')->deleteAll();
    $this->assertSame($optimized_css, $resolver->getCssAssets(clone $assets, TRUE));
    $this->assertSame([$optimized_header, $optimized_footer], $resolver->getJsAssets(clone $assets, TRUE));

    $files_only = static fn (array $asset): bool => $asset['type'] !== 'setting';
    $this->assertAggregates('css', $css, $optimized_css, $expected_groups['css']);
    $optimized_footer = array_filter($optimized_footer, $files_only);
    $exclusions = array_filter(array_map(static fn (array $asset): ?string =>
      Request::create($asset['data'])->query->get('exclude'),
      $optimized_footer,
    ));
    $this->assertSame($already_loaded, $exclusions !== []);
    foreach ($exclusions as $exclude) {
      $this->assertSame($loaded, explode(',', UrlHelper::uncompressQueryParameter($exclude)));
    }
    // The explicit jQuery bootstrap and adapter follow the vendor.
    if ($legacy) {
      $jquery_group = array_search(['core/assets/vendor/jquery/jquery.min.js'], $expected_groups['js'], TRUE);
      $this->assertNotFalse($jquery_group);
      array_splice($expected_groups['js'], $jquery_group + 1, 0, [
        ['core/misc/jquery.no-conflict.js'],
        ['core/misc/jquery.drupal-bc.js'],
      ]);
    }
    $this->assertAggregates('js', array_filter($footer, $files_only), $optimized_footer, $expected_groups['js']);
  }

  /**
   * Matches aggregate metadata and served bytes to ordered source assets.
   */
  private function assertAggregates(string $type, array $unoptimized, array $optimized, array $expected_groups): void {
    $groups = $this->container->get("asset.$type.collection_grouper")->group($unoptimized);
    $optimized = array_values($optimized);
    $actual_groups = array_map(static fn (array $group): array => array_column($group['items'], 'data'), $groups);
    $this->assertSame($expected_groups, $actual_groups);
    $this->assertSame(array_keys($unoptimized), array_merge(...$actual_groups));
    $this->assertCount(count($groups), $optimized);
    $controller = $type === 'css' ? CssAssetController::create($this->container) : JsAssetController::create($this->container);
    $optimizer = $this->container->get("asset.$type.optimizer");
    $aggregate_count = 0;
    foreach ($groups as $delta => $group) {
      $aggregate = $optimized[$delta];
      $this->assertSame($group['type'], $aggregate['type']);
      $this->assertSame($group['preprocess'], $aggregate['preprocess']);
      if (empty($aggregate['preprocessed'])) {
        $this->assertCount(1, $group['items']);
        $this->assertSame($group['items'][0]['data'], $aggregate['data']);
        continue;
      }

      $aggregate_count++;
      $this->assertSame($group['libraries'] ?? NULL, $aggregate['libraries'] ?? NULL);
      $request = Request::create($aggregate['data']);
      $this->assertSame('stark', $request->query->get('theme'));
      $filename = basename($request->getPathInfo());
      $this->assertFileDoesNotExist("assets://$type/$filename");
      $response = $controller->deliver($request, $filename);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame($type === 'css' ? 'text/css' : 'text/javascript', $response->headers->get('Content-Type'));
      $body = $response->getContent();

      // Build the byte expectation with the individual file optimizer. Match
      // the complete payload after removing group-level license comments.
      $expected = '';
      foreach ($group['items'] as $item) {
        $expected .= $type === 'js' && !empty($item['minified'])
          ? file_get_contents($item['data'])
          : $optimizer->optimize($item);
        if ($type === 'js') {
          $expected .= ";\n";
        }
      }
      if ($type === 'js') {
        $expected = $optimizer->clean($expected);
      }
      $this->assertSame($expected, preg_replace('~^/\* @license [^\n]* \*/\n~m', '', $body));
      $warm_response = $controller->deliver(Request::create($aggregate['data']), $filename);
      $this->assertInstanceOf(BinaryFileResponse::class, $warm_response);
      $this->assertSame(200, $warm_response->getStatusCode());
      ob_start();
      try {
        $warm_response->prepare($request)->sendContent();
        $warm_body = ob_get_contents();
      }
      finally {
        ob_end_clean();
      }
      $this->assertSame($body, $warm_body);
    }
    $this->assertGreaterThan(0, $aggregate_count);
  }

}
