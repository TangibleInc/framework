<?php
namespace tangible;
use tangible\framework;

if (!class_exists('tangible\\framework')) {
  class framework {
    static $state;
  };
  framework::$state = (object) [];
}

// Load Date module early since it has its own loader
require_once __DIR__ . '/date/index.php';
require_once __DIR__ . '/utils/index.php';

(include __DIR__ . '/module-loader.php')(new class {

  public $name = 'tangible_framework';
  public $version = '20261015';

  function load() {

    framework::$state->version = $this->version;
    framework::$state->path = __DIR__;

    // Load these first so others can use tangible\see() and module_url()
    require_once __DIR__ . '/log/index.php';
    require_once __DIR__ . '/utils/index.php';

    framework::$state->url = framework\module_url(__FILE__);

    // The framework's own strings (onboarding, Connect) are in the
    // tangible-framework domain, translated in languages/. A site-level
    // file in wp-content/languages/plugins/ wins: it is loaded first, and
    // the earliest file loaded for a domain takes precedence. (Loading only
    // the bundled file would set the domain and skip WordPress's
    // just-in-time lookup, so the site's own file would never be read.)
    $load_translations = function () {
      $mofile = 'tangible-framework-' . determine_locale() . '.mo';
      load_textdomain('tangible-framework', WP_LANG_DIR . '/plugins/' . $mofile);
      load_textdomain('tangible-framework', __DIR__ . '/languages/' . $mofile);
    };
    did_action('init') ? $load_translations() : add_action('init', $load_translations, 0);
  
    require_once __DIR__ . '/admin/index.php';
    require_once __DIR__ . '/ajax/index.php';
    require_once __DIR__ . '/api/index.php';
    require_once __DIR__ . '/design/index.php';
    require_once __DIR__ . '/design-settings/index.php';
    require_once __DIR__ . '/env/index.php';
    require_once __DIR__ . '/file-system/index.php';
    require_once __DIR__ . '/format/index.php';
    require_once __DIR__ . '/hjson/index.php';
    require_once __DIR__ . '/interface/index.php';
    require_once __DIR__ . '/markdown/index.php';
    require_once __DIR__ . '/object/index.php';
    require_once __DIR__ . '/onboarding/index.php';
    require_once __DIR__ . '/connect/index.php';
    require_once __DIR__ . '/hub/index.php';
    require_once __DIR__ . '/plugin/index.php';
    require_once __DIR__ . '/preact/index.php';
    require_once __DIR__ . '/select/index.php';

    do_action($this->name . '_ready');
  }
});
