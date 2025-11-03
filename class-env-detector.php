
<?php

namespace Tangible\Algolia\Utils; 

defined( 'ABSPATH' ) || exit; 

class Env_Detector { 

  /**
   * Returns whether or not the current site is a staging site.
   *
   * @return boolean
   */
  public static function is_staging(): bool { 

    if ( Env_Detector::check_wp_environment_type() ) {
      return apply_filters( 'tgbl_alg_is_staging_site', true, 'wp_env_type' );
    }

    if ( Env_Detector::check_jetpack_status_package() ) {
      return apply_filters( 'tgbl_alg_is_staging_site', true, 'jetpack_status' ); 
    }

    $host = wp_parse_url( home_url(), PHP_URL_HOST ); 
    $host = strtolower( ( string ) $host );
    
    if ( Env_Detector::check_txp_staging( $host ) ) {
      return apply_filters( 'tgbl_alg_is_staging_site', true, 'txp_staging_site' );
    }

    if ( Env_Detector::check_kinsta_staging( $host ) ) {
      return apply_filters( 'tgbl_alg_is_staging_site', true, 'kinsta_staging_site' );
    }

    if ( Env_Detector::check_rapyd_staging( $host ) ) {
      return apply_filters( 'tgbl_alg_is_staging_site', true, 'rapyd_staging_site' );
    }

    if ( Env_Detector::check_wpengine_staging( $host ) ) {
      return apply_filters( 'tgbl_alg_is_staging_site', true, 'wpengine_staging_site' );
    }

    if ( Env_Detector::check_subdomain( $host ) ) {
      return apply_filters( 'tgbl_alg_is_staging_site', true, 'staging_subdomain' );
    }

    if ( Env_Detector::check_local( $host ) ) {
      return apply_filters( 'tgbl_alg_is_staging_site', true, 'local' );
    }

    if ( Env_Detector::check_flywheel( $host ) ) {
      return apply_filters( 'tgbl_alg_is_staging_site', true, 'flywheel' );
    }

    return apply_filters( 'tgbl_alg_is_staging_site', false, 'all_checks' );
  } 

  private static function check_wp_environment_type() {
    if ( ! function_exists( 'wp_get_environment_type' ) ) {
      return false;
    }
    
    $env_type = wp_get_environment_type();
    if ( in_array( $env_type, array( 'staging', 'development', 'local' ), true ) ) { 
      return true; 
    } 

    return false;
  }

  private static function check_jetpack_status_package() {
    if ( ! class_exists( '\Automattic\Jetpack\Status' ) ) {
      return false;
    }

    $status = new \Automattic\Jetpack\Status();
    if ( method_exists( $status, 'is_staging_site' ) && $status->is_staging_site() ) {
      return true;
    }

    return false;
  }

  private static function check_txp_staging( $host ) {
    if ( str_contains( $host, 'tangiblelaunchpad.com' ) ) {
      return true;
    }
    
    return false;
  }

  private static function check_kinsta_staging( $host ) {
    if ( str_contains ( $host, '.kinsta.cloud' ) ) {
      return true;
    }

    return false;
  }

  private static function check_rapyd_staging( $host ) {
    if ( str_contains ( $host, '.rapydapps.cloud' ) ) {
      return true;
    }

    return false;
  }


  private static function check_wpengine_staging( $host ) {
    if ( str_contains ( $host, '.wpengine.com' ) ) {
      return true;
    }

    return false;
  }

  private static function check_subdomain( $host ) {
    $staging_subdomains = [ 'staging', 'stage', 'dev', 'development', 'sandbox', 'test', 'preview' ]; 

    foreach ( $staging_subdomains as $subdomain ) { 
      $prefixed_staging_subdomain = str_contains( $host, $subdomain . '.' );
      $nested_subdomain = str_contains( $host, '.' . $subdomain . '.' );
      $dashed_staging_subdomain = str_contains( $host, '-' . $subdomain );

      if ( $prefixed_staging_subdomain || $nested_subdomain ||  $dashed_staging_subdomain) { 
        return true;
      } 
    }

    return false;
  }
  
  private static function check_local( $host ) {
    $local_hosts = [ 'localhost', '127.0.0.1', '::1' ];
    $local_tlds = [ '.test', '.local' ];

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

  private static function check_flywheel( $host ) {
    if ( getenv( 'FLYWHEEL_CONFIG_DIR' ) ) {  
      if ( str_contains( $host, 'preview' ) || str_contains( $host, 'flywheelsites' ) ) { 
        return true; 
      } 
    }

    return false;
  }
}
