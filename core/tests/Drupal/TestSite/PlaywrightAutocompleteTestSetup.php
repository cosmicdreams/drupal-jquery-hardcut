<?php

declare(strict_types=1);

namespace Drupal\TestSite;

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Sets up Default Admin and the account for the autocomplete fixture.
 */
final class PlaywrightAutocompleteTestSetup implements TestSetupInterface {

  /**
   * {@inheritdoc}
   */
  public function setup(): void {
    \Drupal::service('theme_installer')->install(['default_admin']);
    \Drupal::configFactory()->getEditable('system.theme')
      ->set('default', 'default_admin')
      ->save();

    Role::create(['id' => 'autocomplete', 'label' => 'Autocomplete tester'])
      ->grantPermission('access autocomplete test')
      ->save();
    User::create([
      'name' => 'user',
      'status' => 1,
      'roles' => ['autocomplete'],
    ])->save();
  }

}
