<?php
namespace tangible\file_system;
use tangible\file_system;
use tangible\framework;

framework::$state->fs = null;

function instance() {
  if (empty(framework::$state->fs)) {
    framework::$state->fs = filesystem\create_instance();
  }
  return framework::$state->fs;
}

function read_file( $path ) {
  return filesystem\instance()->get_contents( $path );
}

function is_writable( $path ) {
  return filesystem\instance()->is_writable( $path );
}

function write_file( $path, $contents ) {
  return filesystem\instance()->put_contents( $path, $contents, FS_CHMOD_FILE );
}

function mkdir( $path ) {
  return filesystem\instance()->mkdir( $path );
}

function rmdir( $path, $recursive = false ) {
  return filesystem\instance()->rmdir( $path, $recursive );
}

function is_dir( $path ) {
  return filesystem\instance()->is_dir( $path );
}

/**
 * @return array [ $filename => $fileinfo, .. ]
 */
function dirlist( $path, $include_hidden = true, $recursive = false ) {
  return (array) filesystem\instance()->dirlist( $path, $include_hidden, $recursive );
}

function move( $from, $to ) {
  return filesystem\instance()->move( $from, $to );
}

function delete( $path ) {
  return filesystem\instance()->delete( $path );
}

function exists( $path ) {
  return filesystem\instance()->exists( $path );
}

function size( $path ) {
  return filesystem\instance()->size( $path );
}

function filename($url) {
  return basename(parse_url($url, PHP_URL_PATH));
}

function download_url($url, $timeout = 300) {

  $filename = filesystem\filename($url);

  if ( ! function_exists( 'wp_tempnam' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
  }

  $temporary_filepath = wp_tempnam( $filename );
  if (!$temporary_filepath) return new WP_Error('http_no_file', 'Could not create temporary file');

  $response = wp_remote_get($url,
    [ 'timeout'  => $timeout, 'stream'   => true, 'filename' => $temporary_filepath ]
  );

  if ( is_wp_error( $response ) ) {
    filesystem\delete( $temporary_filepath );
    return $response;
  }

  $response_code = wp_remote_retrieve_response_code($response);
  if ( 200 != $response_code ) {
    return new WP_Error('http_404', trim(wp_remote_retrieve_response_message($response)));
  }

  return $temporary_filepath;
}

/**
 * Download a zip file from a URL and extract to a temporary folder
 * The temporary folder must be deleted afterwards
 * @return string|WP_Error Path to extracted folder, or error
 */
function download_url_and_unzip( $url ) {

  $temporary_filepath = filesystem\download_url($url);

  if (is_wp_error($temporary_filepath)) return $temporary_filepath;

  $filename = filesystem\filename($url);
  $temporary_folder = dirname($temporary_filepath).'/'.$filename.'-'.uniqid();

  $result = unzip_file($temporary_filepath, $temporary_folder);

  filesystem\delete($temporary_filepath);

  if (is_wp_error($result)) return $result;

  /**
   * Get file list: filesystem\dirlist($temporary_folder)
   * Remove folder when finished: filesystem\rmdir($temporary_folder, true);
   */
  return $temporary_folder;
}

/**
 * Return an instance of WP_Filesystem
 * @see wp-admin/includes/class-wp-filesystem-direct.php
 */
function create_instance() {

  global $wp_filesystem;

  if ($wp_filesystem && $wp_filesystem->method==='direct') return $wp_filesystem;

  require_once ABSPATH . '/wp-admin/includes/file.php';

  $context = apply_filters( 'request_filesystem_credentials_context', false );

  add_filter( 'filesystem_method', 'tangible\filesystem\filter_filesystem_method');
  add_filter( 'request_filesystem_credentials', 'tangible\filesystem\filter_request_filesystem_credentials');

  $creds = request_filesystem_credentials( site_url(), '', true, $context, null );

  WP_Filesystem( $creds, $context );

  remove_filter( 'filesystem_method', 'tangible\filesystem\filter_filesystem_method');
  remove_filter( 'request_filesystem_credentials', 'tangible\filesystem\filter_request_filesystem_credentials');

  // Set the permission constants if not already set.
  if (!defined('FS_CHMOD_DIR')) define('FS_CHMOD_DIR', 0755);
  if (!defined('FS_CHMOD_FILE')) define('FS_CHMOD_FILE', 0644);

  return $wp_filesystem;
}

function filter_filesystem_method() {
  return 'direct';
}

function filter_request_filesystem_credentials() {
  return true;
}
