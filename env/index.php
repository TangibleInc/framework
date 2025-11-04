<?php
namespace tangible\env;

use tangible\env;

defined( 'ABSPATH' ) || exit;

/**
 * Returns whether or not the current site is a staging site.
 * @return boolean
 */
function is_staging( $given_host = null ): bool {

  static $hook = 'tangible_env_is_staging';
  static $host;

  if (!isset($host)) {
     $host = strtolower( ( string ) wp_parse_url( home_url(), PHP_URL_HOST ) );
  }

  if ( is_null($given_host) ) {
    $given_host = $host;
  }

  // Short-circuit if env type "all" is staging
  $result = apply_filters( $hook, false, 'all' );
  if ($result === true) return true;

  // Checks applied in this order
  foreach ([
    'wp',
    'jetpack',
    'txp',
    'kinsta',
    'rapyd',
    'wpengine',
    'subdomain',
    'local',
    'flywheel',
  ] as $key) {
    $result = call_user_func("tangible\\env\\is_${key}_staging", $given_host);
    if (apply_filters( $hook, $result, $key, $given_host )) {
      return true;
    }
  }

  return false;
}

function is_wp_staging() {
  if ( ! function_exists( 'wp_get_environment_type' ) ) {
    return false;
  }

  $env_type = wp_get_environment_type();
  if ( in_array( $env_type, array( 'staging', 'development', 'local' ), true ) ) { 
    return true; 
  } 

  return false;
}

function is_jetpack_staging() {
  if ( ! class_exists( '\Automattic\Jetpack\Status' ) ) {
    return false;
  }

  $status = new \Automattic\Jetpack\Status();
  if ( method_exists( $status, 'is_staging_site' ) && $status->is_staging_site() ) {
    return true;
  }

  return false;
}

function is_txp_staging( $host ) {
  if ( str_contains( $host, 'tangiblelaunchpad.com' ) ) {
    return true;
  }

  return false;
}

function is_kinsta_staging( $host ) {
  if ( str_contains ( $host, '.kinsta.cloud' ) ) {
    return true;
  }

  return false;
}

function is_rapyd_staging( $host ) {
  if ( str_contains ( $host, '.rapydapps.cloud' ) ) {
    return true;
  }

  return false;
}


function is_wpengine_staging( $host ) {
  if ( str_contains ( $host, '.wpengine.com' ) ) {
    return true;
  }

  return false;
}

function is_subdomain_staging( $host ) {
  static $staging_subdomains = [
    'staging',
    'stage',
    'dev',
    'development',
    'sandbox',
    'test',
    'preview'
  ];

  foreach ( $staging_subdomains as $subdomain ) { 
    if (
      str_contains( $host, $subdomain . '.' )          // Prefixed
      || str_contains( $host, '.' . $subdomain . '.' ) // Nested
      || str_contains( $host, '-' . $subdomain )       // Dashed
    ) { 
      return true;
    } 
  }

  return false;
}

function is_local_staging( $host ) {
  $local_hosts = [ 'localhost', '127.0.0.1', '::1' ];
  $local_tlds = [ '.test', '.local', '.localhost' ];

  if ( in_array( $host, $local_hosts, true ) ) {
    return true;
  }

  foreach ( $local_tlds as $tld ) { 
    if ( str_ends_with( $host, $tld ) ) {
      return true;
    }
  }

  return false;
}

function is_flywheel_staging( $host ) {
  if ( getenv( 'FLYWHEEL_CONFIG_DIR' ) ) {  
    if ( str_contains( $host, 'preview' ) || str_contains( $host, 'flywheelsites' ) ) { 
      return true; 
    } 
  }

  return false;
}
