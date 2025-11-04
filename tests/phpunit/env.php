<?php
namespace tests\framework;

use tangible\env;

class Env_TestCase extends \WP_UnitTestCase {

  static $is_staging_hook = 'tangible_env_is_staging';

  /**
   * Helper to avoid false negative: test env is considered staging due to
   * localhost and wp_get_environment_type().
   */
  function filter_test_env($result, $type) {
    return $type==='localhost' || $type==='wp' ? false : $result;
  }

  function add_filter_test_env() {
    add_filter(self::$is_staging_hook, [$this, 'filter_test_env'], 10, 2);
  }

  function remove_filter_test_env() {
    remove_filter(self::$is_staging_hook, [$this, 'filter_test_env'], 10);
  }

  /**
   * Basic env test
   */
  function test_env() {

    $this->assertTrue( function_exists( 'tangible\\env\\is_staging' ) );
    $this->assertTrue( env\is_staging() === true ); // Test env is staging

    $this->add_filter_test_env();
    $this->assertTrue( env\is_staging() === false ); // Skip check for localhost

    add_filter(self::$is_staging_hook, '__return_true');
    $this->assertTrue( env\is_staging() === true );

    remove_filter(self::$is_staging_hook, '__return_true');
    $this->assertTrue( env\is_staging() === false );

    $this->remove_filter_test_env();
    $this->assertTrue( env\is_staging() === true ); // Test env is staging
  }

  function common_test_env_type( string $type ) {

    $this->add_filter_test_env();

    add_filter(self::$is_staging_hook, '__return_false');
    $this->assertTrue( env\is_staging() === false );

    // Only true for this type
    $fn = function($result, $check_type) use ($type) {
      return $check_type === $type;
    };

    add_filter(self::$is_staging_hook, $fn, 10, 2);
    $this->assertTrue( env\is_staging() === true );

    remove_filter(self::$is_staging_hook, $fn, 10);
    $this->assertTrue( env\is_staging() === false );

    remove_filter(self::$is_staging_hook, '__return_false');
    $this->assertTrue( env\is_staging() === false );

    $this->remove_filter_test_env();
    $this->assertTrue( env\is_staging() === true );
  }

  // Using each function to see test titles, instead of data provider with numeric index

  function test_env_type_wp() {
    $this->common_test_env_type('wp');
  }

  function test_env_type_jetpack() {
    $this->common_test_env_type('jetpack');
  }

  function test_env_type_txp() {
    $this->common_test_env_type('txp');
  }

  function test_env_type_kinsta() {
    $this->common_test_env_type('kinsta');
  }

  function test_env_type_rapyd() {
    $this->common_test_env_type('rapyd');
  }

  function test_env_type_wpengine() {
    $this->common_test_env_type('wpengine');
  }

  function test_env_type_subdomain() {
    $this->common_test_env_type('subdomain');
  }

  function test_env_type_localhost() {
    $this->common_test_env_type('localhost');
  }

  function test_env_type_local_domain() {
    $this->common_test_env_type('local_domain');
  }

  function test_env_type_flywheel() {
    $this->common_test_env_type('flywheel');
  }

  /**
   * @dataProvider provide_known_staging_domains
   */
  function test_known_staging_domains( string $host ) {
    $this->add_filter_test_env();
    $this->assertTrue( env\is_staging($host) === true );
    $this->remove_filter_test_env();
  }

  function provide_known_staging_domains() {
    return [
      // Domains
      ['example.tangiblelaunchpad.com'],
      ['example.kinsta.cloud'],
      ['example.rapydapps.cloud'],
      ['example.wpengine.com'],
      // Subdomains
      ['dev.example.com'],
      ['development.example.com'],
      ['preview.example.com'],
      ['sandbox.example.com'],
      ['stage.example.com'],
      ['staging.example.com'],
      ['test.example.com'],

      // Nested
      ['123.dev.example.com'],
      // Dashed
      ['123-dev.example.com'],

      // Local
      ['example.test'],
      ['example.local'],
      ['example.localhost'],
    ];
  }


  /**
   * @dataProvider provide_known_non_staging_domains
   */
  function test_known_non_staging_domains( string $host ) {
    $this->add_filter_test_env();
    $this->assertTrue( env\is_staging($host) === false );
    $this->remove_filter_test_env();
  }

  function provide_known_non_staging_domains() {
    return [
      ['example.com'],
      ['legit-preview.com'],
      ['my-name-is-test.com'],
      ['any.example.com'],
      ['production.example.com'],
    ];
  }
}
