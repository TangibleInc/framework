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
    'localhost',
    'local_domain',
    'flywheel',
    'pantheon',
    'cloudways',
    'dreampress',
    'newspack',
    'azure',
    'wpserveur',
    'liquidweb',
    'constant',
  ] as $key) {
    $result = call_user_func("tangible\\env\\is_{$key}_staging", $given_host);
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

/**
 * Jetpack's own answer, without calling its deprecated aggregate.
 *
 * jetpack-status 3.3.0 deprecated Status::is_staging_site() and points at in_safe_mode()
 * instead. That annotation is misleading here: is_staging_site() combined FIVE checks
 * (environment type, known staging hosts, known staging constants, Jetpack's Identity Crisis,
 * and its own filters) whereas in_safe_mode() is ONLY the Identity Crisis one. Swapping to it
 * would have made this function return false on every site without a full Jetpack connection,
 * silently dropping staging detection for Pantheon, Cloudways, Azure and friends — and for a
 * caller like a search plugin that pauses indexing on staging, that means a staging site quietly
 * resuming writes to the production index.
 *
 * Picking by version does not help either: from 3.3.0 both methods exist and mean different
 * things. So the host and constant checks now live in this module as detectors of their own
 * (is_pantheon_staging(), is_constant_staging(), ...), which also makes them work with no
 * Jetpack installed at all, and this function is left with what is genuinely Jetpack's:
 * safe mode, and the two filters sites use to force the answer.
 *
 * Calling is_staging_site() below 3.3.0 is deliberate — there it is not deprecated, emits no
 * notice, and is strictly broader than anything we could ask for instead.
 */
function is_jetpack_staging( $host = null ) {

  // The site's OWN declaration, checked first and without requiring Jetpack.
  //
  // Neither filter carries a deprecation notice, but on 3.3.0+ Jetpack fires them only from the
  // deprecated method — so unless we fire them, a site already using one to force staging mode
  // silently stops being heard. They are not behind the class_exists() guard below because which
  // plugin happens to ship jetpack-status is not something a site's own declaration should hinge
  // on: WooCommerce ships it, so gating on it would mean deactivating WooCommerce flips a
  // declared staging site to production.
  //
  // Seeded false rather than with a running result: this is one detector among many and the chain
  // ORs them, so false here means "not forced", not "not staging". To force the answer in either
  // direction, use this module's own 'tangible_env_is_staging'.
  if ( apply_filters( 'jetpack_is_staging_site', false ) === true ) {
    return true;
  }

  // Cast at every step: this filter's value comes from whatever a site's own code returns, and a
  // malformed one must not turn "are we on staging?" into a warning on every page load.
  $known = (array) apply_filters( 'jetpack_known_staging', [ 'urls' => [], 'constants' => [] ] );

  if ( ! empty( $known['urls'] ) ) {
    $checked_host = $host ?? (string) wp_parse_url( home_url(), PHP_URL_HOST );

    foreach ( (array) $known['urls'] as $pattern ) {
      if ( is_string( $pattern ) && $pattern !== '' && preg_match( $pattern, $checked_host ) ) {
        return true;
      }
    }
  }

  foreach ( (array) ( $known['constants'] ?? [] ) as $constant ) {
    if ( defined( $constant ) && constant( $constant ) ) {
      return true;
    }
  }

  if ( ! class_exists( '\Automattic\Jetpack\Status' ) ) {
    return false;
  }

  $status = new \Automattic\Jetpack\Status();

  // in_safe_mode() was added in 3.3.0, the same release that deprecated is_staging_site(), so its
  // presence is the reliable marker for which one to ask.
  if ( method_exists( $status, 'in_safe_mode' ) ) {
    return (bool) $status->in_safe_mode();
  }

  return method_exists( $status, 'is_staging_site' ) && $status->is_staging_site();
}

function is_txp_staging( $host ) {
  if ( str_contains( $host, '.tangiblelaunchpad.com' ) ) {
    return true;
  }

  return false;
}

function is_kinsta_staging( $host ) {
  if ( str_contains ( $host, '.kinsta.cloud' ) ) {
    return true;
  }

  // Kinsta's older staging hostname, and the constant it sets there. Both were previously
  // reached through Jetpack's is_staging_site(); see is_jetpack_staging().
  if ( str_ends_with( $host, '.staging.kinsta.com' ) ) {
    return true;
  }

  if ( defined( 'KINSTA_DEV_ENV' ) && constant( 'KINSTA_DEV_ENV' ) ) {
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

  // Set on WP Engine's legacy staging environment. Their current platform has no such constant
  // and no common hostname, so this cannot be the whole answer there.
  if ( defined( 'IS_WPE_SNAPSHOT' ) && constant( 'IS_WPE_SNAPSHOT' ) ) {
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

  if (substr_count($host, '.') < 2) return false; // No subdomain

  foreach ( $staging_subdomains as $subdomain ) { 
    if (
      str_starts_with( $host, $subdomain . '.' )       // Prefixed
      || str_contains( $host, '.' . $subdomain . '.' ) // Nested
      || str_contains( $host, '-' . $subdomain )       // Dashed
    ) { 
      return true;
    } 
  }

  return false;
}

function is_local_domain_staging( $host ) {
  static $local_tlds = [ '.test', '.local', '.localhost' ];

  foreach ( $local_tlds as $tld ) { 
    if ( str_ends_with( $host, $tld ) ) {
      return true;
    }
  }

  return false;
}

function is_localhost_staging( $host ) {
  static $local_hosts = [ 'localhost', '127.0.0.1', '::1' ];

  if ( in_array( $host, $local_hosts, true ) ) {
    return true;
  }

  return false;
}

function is_flywheel_staging( $host ) {
  if ( getenv( 'FLYWHEEL_CONFIG_DIR' ) ) {  
    if ( str_contains( $host, 'preview' ) || str_contains( $host, 'flywheelsites' ) ) { 
      return true; 
    } 
  }

  // Staging by name, so it needs no corroborating env var. Flywheel's OTHER domain,
  // .flywheelsites.com, is deliberately left to the check above: it also serves live sites that
  // have not bound a custom domain yet, which is why this module asks for FLYWHEEL_CONFIG_DIR
  // there. (Jetpack's is_staging_site() treated both as staging outright.)
  if ( str_ends_with( $host, '.flywheelstaging.com' ) ) {
    return true;
  }

  return false;
}

/**
 * Pantheon. Their live environment is `live-<site>.pantheonsite.io`; every other prefix
 * (`dev-`, `test-`, multidev) is not. Anchored so a custom domain never matches.
 */
function is_pantheon_staging( $host ) {
  return (bool) preg_match( '#^(?!live-)([a-zA-Z0-9-]+)\.pantheonsite\.io$#i', $host );
}

function is_cloudways_staging( $host ) {
  return str_ends_with( $host, '.cloudwaysapps.com' );
}

/** DreamHost's DreamPress staging hostname. */
function is_dreampress_staging( $host ) {
  return str_ends_with( $host, '.stage.site' );
}

function is_newspack_staging( $host ) {
  return str_ends_with( $host, '.newspackstaging.com' );
}

/**
 * Azure App Service's default hostname, which a site carries until a custom domain is bound.
 */
function is_azure_staging( $host ) {
  return str_ends_with( $host, '.azurewebsites.net' );
}

function is_wpserveur_staging( $host ) {
  return str_ends_with( $host, '.wpserveur.net' );
}

function is_liquidweb_staging( $host ) {
  return str_ends_with( $host, '-liquidwebsites.com' );
}

/**
 * Host-agnostic constants that declare a non-production site.
 *
 * WP_LOCAL_DEV and JETPACK_STAGING_MODE are the generic ones people set by hand, and they are
 * the only way to say "this is staging" on a host with no recognisable domain — which is most
 * of them once a custom domain is bound. Previously reached only through Jetpack's deprecated
 * is_staging_site(); see is_jetpack_staging().
 *
 * Host-specific constants live with their host's detector (KINSTA_DEV_ENV, IS_WPE_SNAPSHOT).
 */
function is_constant_staging( $host = null ) {
  foreach ( [
    'WPSTAGECOACH_STAGING',
    'JETPACK_STAGING_MODE',
    'WP_LOCAL_DEV',
  ] as $constant ) {
    if ( defined( $constant ) && constant( $constant ) ) {
      return true;
    }
  }

  return false;
}
