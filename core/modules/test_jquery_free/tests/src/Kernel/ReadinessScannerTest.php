<?php

declare(strict_types=1);

namespace Drupal\Tests\test_jquery_free\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\test_jquery_free\Controller\ReadinessReportController;
use Drupal\test_jquery_free\Drush\Commands\ReadinessReportCommand;
use Drupal\test_jquery_free\Form\ReadinessRescanForm;
use Drupal\test_jquery_free\ReadinessScanner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the report using installed extension fixtures and library discovery.
 */
#[Group('test_jquery_free')]
#[RunTestsInSeparateProcesses]
final class ReadinessScannerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'test_jquery_free', 'jqf_dependency', 'jqf_script', 'jqf_clean', 'jqf_alter'];

  /**
   * The real test site directory used to discover copied extensions.
   */
  private string $fixtureSite;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    $this->fixtureSite = $this->siteDirectory;
    parent::setUpFilesystem();

    // The fixtures must be present before the kernel discovers extensions.
    // The separate site path makes the fixtures non-core for this test.
    $fixture_path = $this->root . '/' . $this->fixtureSite . '/modules';
    $filesystem = new Filesystem();
    foreach (['jqf_dependency', 'jqf_script', 'jqf_clean', 'jqf_alter'] as $module) {
      $filesystem->mirror(__DIR__ . '/../../modules/' . $module, "$fixture_path/$module");
    }
    foreach (['jqf_extend', 'jqf_global'] as $theme) {
      $filesystem->mirror(__DIR__ . '/../../themes/' . $theme, $this->root . '/' . $this->fixtureSite . "/themes/$theme");
    }
    // The legacy library declaration belongs only to the non-core copy.
    $dependency_path = "$fixture_path/jqf_dependency";
    $filesystem->rename("$dependency_path/legacy.libraries.yml", "$dependency_path/jqf_dependency.libraries.yml");
    $this->setSetting('test_parent_site', $this->fixtureSite);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    if (isset($this->fixtureSite)) {
      (new Filesystem())->remove($this->root . '/' . $this->fixtureSite);
    }
  }

  /**
   * Tests dependency chains, declaration owners, and theme isolation.
   */
  public function testScan(): void {
    $theme_installer = $this->container->get('theme_installer');
    $theme_installer->install(['jqf_extend', 'jqf_global']);
    $theme_manager = $this->container->get('theme.manager');
    $stark = $this->container->get('theme.initialization')->initTheme('stark');
    $theme_manager->setActiveTheme($stark);
    $this->assertSame($this->fixtureSite . '/modules/jqf_dependency', $this->container->get('module_handler')->getModule('jqf_dependency')->getPath());

    $discovery = $this->container->get('library.discovery');
    $expected_libraries = $discovery->getLibrariesByExtension('jqf_script');
    $expected_defaults = $theme_manager->getDefaultTemplateVariables();
    $discovery->reset();
    $theme_manager->resetActiveTheme();
    $theme_manager->setActiveTheme($stark);
    $cache = $this->container->get('cache.discovery');
    $child_cache_key = 'library_info:jqf_extend';
    $child_cache = $cache->get($child_cache_key)->data ?? NULL;

    $findings = $this->container->get(ReadinessScanner::class)->scan();
    $this->assertSame($stark, $theme_manager->getActiveTheme());
    $this->assertSame($expected_defaults, $theme_manager->getDefaultTemplateVariables());
    $this->assertSame($expected_libraries, $discovery->getLibrariesByExtension('jqf_script'));
    $discovery->destruct();
    $this->assertSame($child_cache, $cache->get($child_cache_key)->data ?? NULL);
    $this->assertSame($expected_libraries, $cache->get('library_info:stark')->data['jqf_script']);
    $this->assertArrayNotHasKey('core', $findings);
    $this->assertArrayNotHasKey('jqf_clean', $findings);
    $this->assertFinding($findings['jqf_script'], 'jqf_script/transitive', 'Library depends on jqf_script/transitive → jqf_dependency/cycle → jqf_dependency/legacy → core/jquery', 'jqf_global, stark');
    $this->assertFinding($findings['jqf_dependency'], 'jqf_dependency/legacy', 'Library depends on jqf_dependency/legacy → core/drupal.jquery.position');
    $this->assertFinding($findings['jqf_dependency'], 'jqf_dependency/legacy', 'Library depends on jqf_dependency/legacy → core/tabbable.jquery.shim');
    $this->assertFinding($findings['jqf_dependency'], 'core/components.jqf_dependency--legacy', 'Library depends on core/components.jqf_dependency--legacy → core/jquery');
    $this->assertFinding($findings['jqf_global'], '42', 'library definition error: libraries-override keys must be library IDs.', 'jqf_extend, jqf_global');
    $this->assertFinding($findings['jqf_global'], 'invalid', 'library definition error: libraries-override keys must be library IDs.', 'jqf_extend, jqf_global');
    $this->assertFinding($findings['jqf_extend'], 'core/drupalSettings', 'Library depends on core/drupalSettings → jqf_dependency/legacy → core/jquery', 'jqf_extend');
    $this->assertFinding($findings['jqf_extend'], 'core/drupalSettings', 'Library depends on core/drupalSettings → core/jquery.ui.dialog', 'jqf_extend');
    $this->assertFinding($findings['jqf_global'], 'core/tabbable.jquery.shim', 'Theme attaches core/tabbable.jquery.shim', 'jqf_extend, jqf_global');
    $this->assertFinding($findings['jqf_global'], 'core/drupalSettings', 'Theme attaches core/drupalSettings → jqf_dependency/legacy → core/jquery.once', 'jqf_extend');
    $this->assertFinding($findings['jqf_global'], 'jqf_script/script', 'Library depends on jqf_script/script → jqf_dependency/legacy → core/jquery', 'jqf_extend, jqf_global');
    $this->assertNotContains('jqf_script/script', array_column($findings['jqf_extend'], 'source'));
    $this->assertFinding($findings['jqf_global'], 'core/once', 'library definition error: The specified library jqf_global/missing does not exist.', 'jqf_extend, jqf_global');
    $this->assertFinding($findings['jqf_extend'], 'core/drupalSettings', 'library definition error: Invalid library extension int at key 1.', 'jqf_extend');
    $this->assertFinding($findings['jqf_alter'], 'core/once', 'Library depends on core/once → core/jquery', 'stark');
    $this->assertFinding($findings['jqf_alter'], 'core/drupalSettings', 'Library depends on core/drupalSettings → core/jquery');
    $this->assertFinding($findings['jqf_alter'], 'core/drupalSettings', 'library definition error: Dependency at key 1 must be a library ID.');
    $this->assertFinding($findings['jqf_alter'], 'jqf_clean/recipient', 'Library depends on jqf_clean/recipient → core/jquery');
    $this->assertContains([
      'source' => $this->fixtureSite . '/modules/jqf_script/js/probe.js',
      'line' => 7,
      'theme' => NULL,
      'reason' => 'Possible jQuery reference',
    ], $findings['jqf_script']);
  }

  /**
   * Tests attribution when core rejects an altered library definition.
   */
  public function testNormalizationFailure(): void {
    $this->container->get('state')->set('jqf_alter.invalid_header', TRUE);
    $findings = $this->container->get(ReadinessScanner::class)->scan();
    $this->assertArrayNotHasKey('core', $findings);
    $this->assertFinding($findings['jqf_alter'], 'core/drupalSettings', "library definition error: The 'header' key in the library definition 'drupalSettings' in extension 'core' is invalid: it must be a boolean.");
    $this->assertFinding($findings['jqf_dependency'], 'jqf_dependency/legacy', 'Library depends on jqf_dependency/legacy → core/jquery');
  }

  /**
   * Tests cached status, invalidation, report output, and explicit rescans.
   */
  public function testCacheAndReport(): void {
    $logger = new TestLogger();
    $this->container->get('logger.channel.test_jquery_free')->addLogger($logger);
    $scanner = $this->container->get(ReadinessScanner::class);
    $module_handler = $this->container->get('module_handler');
    $requirements = static fn (): array => $module_handler->invoke('test_jquery_free', 'runtime_requirements')['test_jquery_free'];

    $this->assertNull($scanner->cachedFindings());
    $this->assertSame('Scan needed', (string) $requirements()['value']);
    $this->assertSame(RequirementSeverity::Info, $requirements()['severity']);
    $this->assertSame([], $logger->recordsByLevel[RfcLogLevel::WARNING] ?? []);

    $report = ReadinessReportController::create($this->container)->report();
    $advisory = 'Advisory: static analysis is best-effort. Enable this module and exercise the site to confirm, using the browser console canary.';
    $this->assertSame($advisory, (string) $report['advisory']['#plain_text']);
    $this->assertSame('No scan yet', (string) $report['report']['#empty']);
    $this->assertNull($scanner->cachedFindings());
    $this->assertSame([], $logger->recordsByLevel[RfcLogLevel::WARNING] ?? []);

    $expected_rows = $scanner->rows();
    $this->assertSame('jQuery readiness', (string) $requirements()['title']);
    $this->assertSame('3 extensions with possible jQuery dependencies', (string) $requirements()['value']);
    $this->assertSame(RequirementSeverity::Warning, $requirements()['severity']);
    $this->assertSame('test_jquery_free.report', $requirements()['description']['#url']->getRouteName());

    $report = ReadinessReportController::create($this->container)->report();
    $this->assertSame(array_map('array_values', $expected_rows), $report['report']['#rows']);
    $this->assertSame('Rescan', (string) $report['rescan']['submit']['#value']);
    $command = new CommandTester(ReadinessReportCommand::create($this->container));
    $this->assertSame(0, $command->execute(['--format' => 'json'], ['capture_stderr_separately' => TRUE]));
    $this->assertSame($expected_rows, json_decode($command->getDisplay(), TRUE, flags: JSON_THROW_ON_ERROR));
    $this->assertSame($advisory, trim($command->getErrorOutput()));
    $this->assertSame(0, $command->execute([]));
    $this->assertStringContainsString($advisory, $command->getDisplay());
    $this->assertStringContainsString('jqf_dependency/legacy', $command->getDisplay());
    $this->assertCount(3, $logger->recordsByLevel[RfcLogLevel::WARNING]);

    $this->assertSame(0, $command->execute(['--rescan' => TRUE, '--format' => 'json'], ['capture_stderr_separately' => TRUE]));
    $this->assertSame($expected_rows, json_decode($command->getDisplay(), TRUE, flags: JSON_THROW_ON_ERROR));
    $form = [];
    ReadinessRescanForm::create($this->container)->submitForm($form, new FormState());
    $records = $logger->recordsByLevel[RfcLogLevel::WARNING];
    $this->assertSame(array_merge(...array_fill(0, 3, ['jqf_alter', 'jqf_dependency', 'jqf_script'])), array_column(array_column($records, 'context'), '@extension'));
    $this->assertSame('@extension depends on jQuery (@reason). jQuery is removed from Drupal core; test this module with test_jquery_free enabled. See https://www.drupal.org/node/CHANGE_RECORD.', $records[0]['message']);
    $this->assertSame('test_jquery_free', $records[0]['context']['channel']);

    $this->container->get('library.discovery')->clear();
    $this->assertNull($scanner->cachedFindings());
    $this->assertSame($expected_rows, $scanner->rows());
    $this->container->get('extension.list.module')->reset();
    $this->assertNull($scanner->cachedFindings());
    $this->assertSame($expected_rows, $scanner->rows());
    $this->container->get('extension.list.theme')->reset();
    $this->assertNull($scanner->cachedFindings());
    $this->assertSame($expected_rows, $scanner->rows());

    $scanner = static fn (): ReadinessScanner => \Drupal::service(ReadinessScanner::class);
    $this->container->get('theme_installer')->install(['jqf_global']);
    $this->assertNull($scanner()->cachedFindings());
    $scanner()->scan();
    $this->container->get('theme_installer')->uninstall(['jqf_global']);
    $this->assertNull($scanner()->cachedFindings());
    $scanner()->scan();
    $this->container->get('module_installer')->install(['page_cache']);
    $this->assertNull($scanner()->cachedFindings());
    $scanner()->scan();
    $this->container->get('module_installer')->uninstall(['page_cache']);
    $this->assertNull($scanner()->cachedFindings());
  }

  /**
   * Checks a library finding and its optional theme context.
   */
  private function assertFinding(array $findings, string $source, string $reason, ?string $theme = NULL): void {
    $this->assertContains([
      'source' => $source,
      'line' => NULL,
      'theme' => $theme,
      'reason' => $reason,
    ], $findings);
  }

}
