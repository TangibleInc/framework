<?php
namespace tests\framework;

use tangible\env;

class Env_TestCase extends \WP_UnitTestCase {

  static $is_staging_hook = 'tangible_env_is_staging';

  function test_env() {
    $this->assertTrue( function_exists( 'tangible\\env\\is_staging' ) );
    $this->assertTrue( env\is_staging() );

    add_filter(self::$is_staging_hook, '__return_false');
    $this->assertTrue( env\is_staging() === false );

    remove_filter(self::$is_staging_hook, '__return_false');
    $this->assertTrue( env\is_staging() );
  }

  function common_test_env_type( string $type ) {

    // Globally false
    add_filter(self::$is_staging_hook, '__return_false');

    // Only true for this type
    $fn = function($result, $check_type) use ($type) {
     return $check_type === $type;
    };

    add_filter(self::$is_staging_hook, $fn, 10, 2);
    $this->assertTrue( env\is_staging() === true );

    remove_filter(self::$is_staging_hook, $fn, 10);
    $this->assertTrue( env\is_staging() === false );

    // Reset global
    remove_filter(self::$is_staging_hook, '__return_false');
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

  function test_env_type_local() {
    $this->common_test_env_type('local');
  }

  function test_env_type_flywheel() {
    $this->common_test_env_type('flywheel');
  }

}
