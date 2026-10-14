<?php

/**
 * Tests: References
 * 
 * - [PHPUnit](https://github.com/sebastianbergmann/phpunit)
 * - [PHPUnit Polyfills](https://github.com/Yoast/PHPUnit-Polyfills)
 * - [WP_UnitTestCase](https://github.com/WordPress/wordpress-develop/blob/trunk/tests/phpunit/includes/abstract-testcase.php)
 * - [Assertions](https://docs.phpunit.de/en/10.2/assertions.html)
 */

 if ( ! $_WORDPRESS_DEVELOP_DIR = getenv( 'WORDPRESS_DEVELOP_DIR' ) ) {
  $_WORDPRESS_DEVELOP_DIR = __DIR__ . '/../wordpress-develop';
}

/**
 * Directory of PHPUnit test files
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/#using-included-wordpress-phpunit-test-files
 */
if ( ! $_WORDPRESS_TESTS_DIR = getenv( 'WP_TESTS_DIR' ) ) {
  $_WORDPRESS_TESTS_DIR = $_WORDPRESS_DEVELOP_DIR . '/tests/phpunit';
}

$_PLUGIN_ENTRYPOINT = __DIR__ . '/../../plugin.php';

/**
 * NEVER run the suite against a non-test database.
 *
 * The WordPress test bootstrap REINSTALLS WordPress into whatever database its config resolves —
 * dropping and recreating the core tables. Some environments (the docker-factory images, among
 * others) hand every container the same WORDPRESS_DB_NAME variable and point only their dedicated
 * test container at a separate database, and those images' wp-tests-config.php reads that variable
 * with `getenv('WORDPRESS_DB_NAME') ?: 'wordpress_test'`. Run this suite from the DEVELOPMENT
 * container there and the "test" run silently reinstalls over the development site: posts gone,
 * options reset, plugins deactivated, no error, exit code 0.
 *
 * That is not hypothetical. It happened on 2026-09-23 and cost a development site its entire
 * content. A test suite must never be able to do that, whichever directory it is launched from.
 *
 * So the database name is forced to one that says what it is. A name that already carries 'test' or
 * 'tests' as its own word — 'wordpress_test', or wp-env's 'tests-wordpress' — is left alone (it is
 * a deliberate choice); anything else is replaced rather than trusted. wp-env's own
 * wp-tests-config.php DOES read this variable, and its test database is 'tests-wordpress', so a
 * stricter '_test' suffix rule breaks `npm run test` with "Cannot select database".
 *
 * Plain string functions only: this runs before WordPress loads its PHP 8 polyfills, and CI runs
 * the suite on PHP 7.4, where str_ends_with() / str_contains() do not exist yet.
 *
 * WORDPRESS_URL gets the same treatment for a smaller reason: WP_TESTS_DOMAIN wants a bare HOST,
 * and these images set the variable to a full URL. Passing it straight through makes home_url()
 * 'http://http://localhost:8888', whose parsed host is the string 'http' — which quietly breaks
 * every test that asks anything about the site's own domain, including this module's env tests.
 */
$_DB_NAME = getenv( 'WORDPRESS_DB_NAME' );

if ( ! is_string( $_DB_NAME ) || ! preg_match( '/(^|[_-])tests?([_-]|$)/i', $_DB_NAME ) ) {
  putenv( 'WORDPRESS_DB_NAME=wordpress_test' );
  $_ENV['WORDPRESS_DB_NAME'] = 'wordpress_test';
}

$_URL = getenv( 'WORDPRESS_URL' );

if ( is_string( $_URL ) && $_URL !== '' && strpos( $_URL, '://' ) !== false ) {
  $_HOST = (string) parse_url( $_URL, PHP_URL_HOST );
  $_PORT = parse_url( $_URL, PHP_URL_PORT );
  $_BARE = $_PORT ? $_HOST . ':' . $_PORT : $_HOST;

  putenv( 'WORDPRESS_URL=' . $_BARE );
  $_ENV['WORDPRESS_URL'] = $_BARE;
}

require_once $_WORDPRESS_TESTS_DIR . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function() use ($_PLUGIN_ENTRYPOINT) {

  // Setup

  require $_PLUGIN_ENTRYPOINT;
});

require $_WORDPRESS_TESTS_DIR . '/includes/bootstrap.php';
