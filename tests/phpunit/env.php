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

  // Hosts and constants that used to be reached only through Jetpack's is_staging_site(), which
  // it deprecated in 3.3.0 in favour of in_safe_mode() — a method covering just one of that
  // method's five checks. They are detectors of their own now, so they also work with no Jetpack
  // installed. See is_jetpack_staging().

  function test_env_type_pantheon() {
    $this->common_test_env_type('pantheon');
  }

  function test_env_type_cloudways() {
    $this->common_test_env_type('cloudways');
  }

  function test_env_type_dreampress() {
    $this->common_test_env_type('dreampress');
  }

  function test_env_type_newspack() {
    $this->common_test_env_type('newspack');
  }

  function test_env_type_azure() {
    $this->common_test_env_type('azure');
  }

  function test_env_type_wpserveur() {
    $this->common_test_env_type('wpserveur');
  }

  function test_env_type_liquidweb() {
    $this->common_test_env_type('liquidweb');
  }

  function test_env_type_constant() {
    $this->common_test_env_type('constant');
  }

  /**
   * Every key in the check order has a function to call.
   *
   * is_staging() builds the callable from the key by string interpolation, so a key added to that
   * list without its function is not a fatal at load — it is a fatal the first time anything asks
   * whether the site is staging, on whichever site happens to get that far.
   */
  function test_every_check_in_the_order_has_an_implementation() {
    foreach ( [
      'wp', 'jetpack', 'txp', 'kinsta', 'rapyd', 'wpengine', 'subdomain', 'localhost',
      'local_domain', 'flywheel', 'pantheon', 'cloudways', 'dreampress', 'newspack', 'azure',
      'wpserveur', 'liquidweb', 'constant',
    ] as $key ) {
      $this->assertTrue(
        function_exists( "tangible\\env\\is_{$key}_staging" ),
        "Missing tangible\\env\\is_{$key}_staging()"
      );
    }
  }

  /**
   * The staging constants, asserted through the one list that CAN be varied per test.
   *
   * A constant alone marks a site whatever its hostname, and on a host with no recognisable
   * domain — which is most of them once a custom domain is bound — it is the only way to say so.
   * Defining one for real is not testable in-process: define() cannot be undone, so the first
   * test to set WP_LOCAL_DEV would make every later non-staging assertion in the run fail. What
   * this covers instead is that a defined truthy constant IS honoured, using the filterable list;
   * is_constant_staging()'s own three names are a static list checked the identical way.
   */
  function test_a_defined_staging_constant_marks_an_ordinary_domain() {
    $this->add_filter_test_env();
    $this->assertTrue( env\is_staging('example.com') === false );

    // Defined by the WordPress test bootstrap, so it is guaranteed truthy here without this test
    // defining anything of its own.
    $fn = fn( $known ) => [ 'urls' => [], 'constants' => [ 'WP_TESTS_DOMAIN' ] ];

    add_filter( 'jetpack_known_staging', $fn );
    $this->assertTrue( env\is_staging('example.com') === true );
    remove_filter( 'jetpack_known_staging', $fn );

    $this->assertTrue( env\is_staging('example.com') === false );
    $this->remove_filter_test_env();
  }

  /**
   * A host pattern added through Jetpack's filter is matched against the host under test, not
   * against home_url() — every other detector in the chain takes the host it is given, and
   * is_staging($host) would otherwise ignore its own argument here.
   */
  function test_jetpack_known_staging_urls_are_matched_against_the_given_host() {
    $this->add_filter_test_env();

    $fn = fn( $known ) => [ 'urls' => [ '#\.customhost\.example$#i' ], 'constants' => [] ];
    add_filter( 'jetpack_known_staging', $fn );

    $this->assertTrue( env\is_staging('site.customhost.example') === true );
    $this->assertTrue( env\is_staging('example.com') === false );

    remove_filter( 'jetpack_known_staging', $fn );
    $this->remove_filter_test_env();
  }

  /**
   * Jetpack's filter still reaches a site that already uses it.
   *
   * It carries no deprecation notice of its own, but Jetpack fires it only from the deprecated
   * is_staging_site() — so on 3.3.0+ nothing fires it unless this module does, and a site using
   * it to force staging mode would go quietly unheard.
   */
  function test_jetpack_force_filter_is_still_honoured() {
    $this->add_filter_test_env();

    $this->assertTrue( env\is_staging('example.com') === false );

    add_filter( 'jetpack_is_staging_site', '__return_true' );
    $this->assertTrue( env\is_staging('example.com') === true );
    remove_filter( 'jetpack_is_staging_site', '__return_true' );

    $this->assertTrue( env\is_staging('example.com') === false );

    $this->remove_filter_test_env();
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

      // Previously covered only by Jetpack's deprecated is_staging_site()
      ['dev-example.pantheonsite.io'],
      ['test-example.pantheonsite.io'],
      ['example.cloudwaysapps.com'],
      ['example.stage.site'],
      ['example.newspackstaging.com'],
      ['example.azurewebsites.net'],
      ['example.wpserveur.net'],
      ['example-liquidwebsites.com'],
      ['example.flywheelstaging.com'],
      ['example.staging.kinsta.com'],
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

      // Pantheon's LIVE environment, which shares the staging domain and differs only by prefix.
      ['live-example.pantheonsite.io'],
      // Not the Azure/Cloudways/DreamPress domains, merely ending in similar words.
      ['example.com.azurewebsites.net.evil.com'],
      ['notcloudwaysapps.com'],
    ];
  }
}
