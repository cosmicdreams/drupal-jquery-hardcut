<?php

declare(strict_types=1);

namespace Drupal\TestSite;

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Creates the account used by the Playwright login test.
 */
class PlaywrightLoginTestSetup implements TestSetupInterface {

  /**
   * {@inheritdoc}
   */
  public function setup(): void {
    Role::create(['id' => 'reports', 'label' => 'Reports'])
      ->grantPermission('access site reports')
      ->grantPermission('administer site configuration')
      ->save();
    User::create([
      'name' => 'user',
      'pass' => '123',
      'status' => 1,
      'roles' => ['reports'],
    ])->save();
  }

}
