<?php

declare(strict_types=1);

namespace Drupal\Tests\test_jquery_free\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\test_jquery_free\Controller\ReadinessReportController;
use Drupal\test_jquery_free\Drush\Commands\ReadinessReportCommand;
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
  protected static $modules = ['system', 'test_jquery_free', 'jqf_dependency', 'jqf_script', 'jqf_clean'];

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
    foreach (['jqf_dependency', 'jqf_script', 'jqf_clean'] as $module) {
      $filesystem->mirror(__DIR__ . '/../../modules/' . $module, "$fixture_path/$module");
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
   * Tests report rows, runtime requirements, and warning logs.
   */
  public function testScan(): void {
    $fixture_path = $this->fixtureSite . '/modules';
    $module_handler = $this->container->get('module_handler');
    $this->assertSame($fixture_path . '/jqf_dependency', $module_handler->getModule('jqf_dependency')->getPath());

    $logger = new TestLogger();
    $this->container->get('logger.channel.test_jquery_free')->addLogger($logger);

    $dependency_rows = array_map(static fn (string $reason): array => [
      'source' => 'jqf_dependency/legacy',
      'line' => NULL,
      'reason' => $reason,
    ], [
      'Library depends on core/jquery',
      'Library depends on core/jquery.once',
      'Library depends on core/internal.jquery_ui',
      'Library depends on core/internal.jquery.form',
    ]);
    $script_path = $fixture_path . '/jqf_script/js';
    $script_rows = [
      ['source' => "$script_path/capture.js", 'line' => 17, 'reason' => 'Possible jQuery reference'],
      ['source' => "$script_path/probe.js", 'line' => 7, 'reason' => 'Possible jQuery reference'],
    ];

    $scanner = $this->container->get(ReadinessScanner::class);
    $this->assertSame([
      'jqf_dependency' => $dependency_rows,
      'jqf_script' => $script_rows,
    ], $scanner->scan());

    $requirements = $module_handler->invoke('test_jquery_free', 'runtime_requirements')['test_jquery_free'];
    $this->assertSame('jQuery readiness', (string) $requirements['title']);
    $this->assertSame('2 extensions with possible jQuery dependencies', (string) $requirements['value']);
    $this->assertSame(RequirementSeverity::Warning, $requirements['severity']);
    $this->assertSame('test_jquery_free.report', $requirements['description']['#url']->getRouteName());

    $expected_rows = [
      ...array_map(static fn (array $row): array => ['extension' => 'jqf_dependency'] + $row, $dependency_rows),
      ...array_map(static fn (array $row): array => ['extension' => 'jqf_script'] + $row, $script_rows),
    ];
    $this->assertSame($expected_rows, $scanner->rows());
    $this->assertSame(array_map('array_values', $expected_rows), ReadinessReportController::create($this->container)->report()['#rows']);

    $command = new CommandTester(ReadinessReportCommand::create($this->container));
    $this->assertSame(0, $command->execute(['--format' => 'json']));
    $this->assertSame($expected_rows, json_decode($command->getDisplay(), TRUE, flags: JSON_THROW_ON_ERROR));
    $this->assertSame(0, $command->execute([]));
    $this->assertStringContainsString('jqf_dependency/legacy', $command->getDisplay());

    // Every entry point logs once per extension, even with multiple hits.
    $records = $logger->recordsByLevel[RfcLogLevel::WARNING];
    $this->assertCount(12, $records);
    $expected_extensions = array_merge(...array_fill(0, 6, ['jqf_dependency', 'jqf_script']));
    $this->assertSame($expected_extensions, array_column(array_column($records, 'context'), '@extension'));
    $this->assertSame('@extension depends on jQuery (@reason). jQuery is removed from Drupal core; test this module with test_jquery_free enabled. See https://www.drupal.org/node/CHANGE_RECORD.', $records[0]['message']);
    $this->assertSame('test_jquery_free', $records[0]['context']['channel']);
    $this->assertSame('Possible jQuery reference', $records[1]['context']['@reason']);
  }

}
