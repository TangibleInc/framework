<?php
namespace tangible\framework;

if (!function_exists('tangible\\framework\\module_url')):
/**
 * Get module folder URL from given file path
 * Similar to `plugins_url()` but not limited to within plugins folder
 */
function module_url( $path, $file = false ) {
  // Shortcut: single argument $file with default root path '/'
  if ($file===false) {
    $file = $path;
    $path = '/';
  }
  if (!empty($path) && $path[0]!=='/') $path = '/' . $path;

  $file = wp_normalize_path( $file );
  $dir = dirname( $file ) . $path;
  $content_dir = wp_normalize_path(
    defined('WP_CONTENT_DIR_OVERRIDE')
      ? WP_CONTENT_DIR_OVERRIDE
      : WP_CONTENT_DIR
  );
  if (strpos($dir, $content_dir)!==false) {
    return untrailingslashit(
      content_url( str_replace($content_dir, '', $dir))
    );
  }

  // Outside wp-content
  return untrailingslashit(
    home_url( '/' . str_replace(wp_normalize_path(
      defined('ABSPATH_OVERRIDE')
      ? ABSPATH_OVERRIDE
      : ABSPATH
    ), '', $dir))
  );
}
endif;
