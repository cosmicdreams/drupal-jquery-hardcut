<?php

declare(strict_types=1);

namespace Drupal\Tests\test_jquery_free\Kernel;

use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Generic module test for the jQuery readiness canary.
 */
#[Group('test_jquery_free')]
#[RunTestsInSeparateProcesses]
final class GenericTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * Tests normal discovery and module installation.
   */
  public function testInstall(): void {
    $extensions = (new ExtensionDiscovery($this->root))->scan('module', FALSE);
    $this->assertArrayHasKey('test_jquery_free', $extensions);

    $this->installConfig(['system', 'user']);
    $this->installSchema('user', 'users_data');
    $module_installer = $this->container->get('module_installer');
    $this->assertTrue($module_installer->install(['test_jquery_free']));
    $this->assertTrue($module_installer->uninstall(['test_jquery_free']));
  }

}
