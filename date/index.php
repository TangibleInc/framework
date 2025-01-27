<?php
/**
 * Date library with internationalization
 *
 * @see https://github.com/jenssegers/date
 * @see https://github.com/briannesbitt/Carbon, https://carbon.nesbot.com/docs/
 * @see https://www.php.net/manual/en/datetime.format.php
 */
namespace tangible;

if ( ! function_exists( 'tangible\\date' ) ) {
  function date( $arg = false ) {
    static $date;
    if (!$date) {
      // In case this is called before latest version loaded
      if (!class_exists('Tangible\\DateCreator')) {
        require_once __DIR__ . '/DateCreator.php';
      }
      $date = new DateCreator;
    }
    return $arg !== false
      ? call_user_func_array($date, func_get_args())
      : $date
    ;
  }
}

require_once __DIR__.'/legacy.php';

(include __DIR__ . '/module-loader.php')(new class {

  public $name = 'tangible_date';
  public $version = '20250127';

  function load() {
    // Compatibility with older version of Date module
    if (!class_exists('Tangible\\DateCreator')) {
      require_once __DIR__ . '/DateCreator.php';
    }
    do_action($this->name . '_ready');
  }
});
