<?php
namespace tangible\framework;

function module_url( $path, $file = false ) {
  // Shortcut: single argument $file with default root path '/'
  if ($file===false) {
    $file = $path;
    $path = '/';
  }
  if (!empty($path) && $path[0]!=='/') $path = '/' . $path;
  $dir = dirname( $file ) . $path;
  if (strpos($dir, WP_CONTENT_DIR)!==false) {
    return untrailingslashit(
      content_url( str_replace( DIRECTORY_SEPARATOR, '/', str_replace(WP_CONTENT_DIR, '', $dir)))
    );
  }

  // Outside wp-content
  return untrailingslashit(
    home_url( '/' . str_replace( DIRECTORY_SEPARATOR, '/', str_replace(ABSPATH, '', $dir)))
  );
}
