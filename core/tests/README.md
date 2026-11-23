<!-- cspell:ignore skiptags -->

# Running tests

## Setting up

### PHP dependencies

You need the Drupal core development dependencies installed, in order to run
any tests. You can install them using Composer by running
```
composer install
```
in the Drupal root directory. These dependencies should not be installed on a
production site.

### Test directory

Create a directory called sites/simpletest and make sure that it is writable by
the web server and/or all users.

### PHPUnit configuration

Copy the core/phpunit.xml.dist file to phpunit.xml, and place it somewhere
convenient (inside the core directory may not be the best spot, since that
directory may be managed by Composer or Git). You can use the -c option on the
command line to tell PHPUnit where this file is (use the full path).

Settings to change in this file:
* SIMPLETEST_BASE_URL: The URL of your site
* SIMPLETEST_DB: The URL of your Drupal database
* The bootstrap attribute of the top-level phpunit tag, to take into account
  the location of the file

### Additional setup for JavaScript tests

To run JavaScript tests  based on the
\Drupal\FunctionalJavascriptTests\WebDriverTestBase base class, you will need
to install the following additional software:

* Google Chrome or Chromium browser
* chromedriver (tested with version 2.45) -- see
  https://sites.google.com/chromium.org/driver/

## Running tests

The PHPUnit executable is vendor/bin/phpunit -- you will need to locate your
vendor directory (which may be outside the Drupal root).

Here are commands to run one test class, list groups, and run all the tests in
a particular group:
```
./vendor/bin/phpunit -c /path/to/your/phpunit.xml path/to/your/class/file.php
./vendor/bin/phpunit --list-groups
./vendor/bin/phpunit -c /path/to/your/phpunit.xml --group group_name
```

Drupal core currently has a number of different types of tests that can be run
using PHPUnit:
- unit
- kernel
- functional
- functional-javascript
- build

These are organized into PHPUnit test suites. You can run one test suite or many
in combination by using `--testsuite` on the command line:
```
./vendor/bin/phpunit -c /path/to/your/phpunit.xml --testsuite unit
./vendor/bin/phpunit -c /path/to/your/phpunit.xml --testsuite functional,functional-javascript
```
These can be combined with groups and filters:
```
./vendor/bin/phpunit -c /path/to/your/phpunit.xml --testsuite build --group Composer
./vendor/bin/phpunit -c /path/to/your/phpunit.xml --testsuite build --exclude-group Build
./vendor/bin/phpunit -c /path/to/your/phpunit.xml --testsuite unit --filter ClassTest::testMethod
```
More information on running tests can be found at
https://www.drupal.org/docs/8/phpunit/running-phpunit-tests

## Setup for running Functional JavaScript tests

You can run JavaScript tests that are based on the
\Drupal\FunctionalJavascriptTests\WebDriverTestBase base class in the same way
as other PHPUnit tests, except that before you start, you will need to start
chromedriver using port 4444, and keep it running:
```
/path/to/chromedriver --port=4444
```

## Troubleshooting test running

If you run into file permission problems while running tests, you may need to
invoke the phpunit executable with a user in the same group as the web server
user, or with access to files owned by the web server user. For example:
```
sudo -u www-data ./vendor/bin/phpunit -c /path/to/your/phpunit.xml --group group_name
```

If you have permission problems accessing files after running tests, try
putting
```
$settings['file_chmod_directory'] = 02775;
```
in your settings.php or local.settings.php file.

You may need to use absolute paths in your phpunit.xml file, and/or in your
phpunit command arguments.
