<?php
/**
 * Update traps: open the wizard after an update, when a plugin asks for it.
 *
 * The wizard opens on activation only, and an update never activates
 * anything. A plugin that wants setup shown after a particular update
 * registers a trap, the way it registers steps:
 *
 *   add_filter('tangible_onboarding_traps', function ($traps, $plugin_name) {
 *     if ($plugin_name !== 'example-plugin') return $traps;
 *     $traps[] = [
 *       'id'        => 'first-onboarder',        // required, unique
 *       'to'        => ['at_least', '2.13'],     // or ['exact', V], 'always'
 *       'from'      => ['below', '2.13'],        // or 'unrecorded', 'any'
 *       'onboarded' => 'never_this_plugin',      // or 'any', 'never_any_tangible',
 *                                                //    'already_this_plugin'
 *       'action'    => 'wizard',
 *     ];
 *     return $traps;
 *   }, 10, 2);
 *
 * `to` is the version the site just updated to; `at_least` fires on the
 * first update that lands on V or later. `from` is the version it updated
 * from: 'unrecorded' means a release older than this record (one without
 * this framework), and ['below', V] matches that too. A plugin with no traps
 * gets no update behaviour at all.
 *
 * An update is noticed by version, not by WordPress's upgrade hook, so every
 * path counts: auto-updates, WP-CLI, bulk updates, zip uploads. Fresh
 * installs never fire traps; the activation redirect is theirs.
 */
namespace tangible\onboarding;

const TRAP_FILTER = 'tangible_onboarding_traps';

/** Last version seen per plugin: name => version. */
const VERSIONS_OPTION = 'tangible_onboarding_versions';

/** Traps that fired: "name:id@version" => time. */
const TRAPS_FIRED_OPTION = 'tangible_onboarding_traps_fired';

/** Collect and validate one plugin's traps. Malformed rows throw, like steps. */
function get_traps($plugin_name) {
  $traps = [];
  foreach ((array) apply_filters(TRAP_FILTER, [], $plugin_name) as $trap) {
    if (empty($trap['id']) || !is_string($trap['id'])) {
      throw new \Exception('Onboarding trap needs a string id');
    }
    if (isset($traps[ $trap['id'] ])) {
      throw new \Exception("Onboarding trap id \"{$trap['id']}\" registered twice");
    }
    $trap += [ 'to' => 'always', 'from' => 'any', 'onboarded' => 'any', 'action' => 'wizard' ];

    $to = $trap['to'];
    if ($to !== 'always' && !(is_array($to) && in_array($to[0] ?? null, ['exact', 'at_least'], true) && !empty($to[1]))) {
      throw new \Exception("Onboarding trap \"{$trap['id']}\": `to` is 'always', ['exact', V] or ['at_least', V]");
    }
    $from = $trap['from'];
    if (!in_array($from, ['any', 'unrecorded'], true) && !(is_array($from) && ($from[0] ?? null) === 'below' && !empty($from[1]))) {
      throw new \Exception("Onboarding trap \"{$trap['id']}\": `from` is 'any', 'unrecorded' or ['below', V]");
    }
    if (!in_array($trap['onboarded'], ['any', 'never_this_plugin', 'never_any_tangible', 'already_this_plugin'], true)) {
      throw new \Exception("Onboarding trap \"{$trap['id']}\": unknown `onboarded` \"{$trap['onboarded']}\"");
    }
    if ($trap['action'] !== 'wizard') {
      throw new \Exception("Onboarding trap \"{$trap['id']}\": the only action is 'wizard'");
    }
    $traps[ $trap['id'] ] = $trap;
  }
  return array_values($traps);
}

/**
 * Compare the plugin's version with the last one seen, then record it.
 *
 * Returns null when this load is not an update (same version, a downgrade,
 * or a fresh install), otherwise [ 'from' => previous version, or null when
 * the previous release predates the record ].
 */
function detect_update($plugin_name, $version, $just_activated) {
  $versions = get_option(VERSIONS_OPTION, []);
  if (!is_array($versions)) $versions = [];
  $seen = isset($versions[ $plugin_name ]) ? (string) $versions[ $plugin_name ] : null;

  if ($seen !== $version) {
    $versions[ $plugin_name ] = $version;
    update_option(VERSIONS_OPTION, $versions, false);
  }

  if ($seen === null) return $just_activated ? null : [ 'from' => null ];
  return version_compare($version, $seen, '>') ? [ 'from' => $seen ] : null;
}

/** Whether one trap applies to an update from $from (null: unrecorded) to $to. */
function trap_matches($trap, $plugin_name, $from, $to) {
  $want = $trap['to'];
  if (is_array($want)) {
    if ($want[0] === 'exact' && version_compare($to, $want[1], '!=')) return false;
    if ($want[0] === 'at_least') {
      if (version_compare($to, $want[1], '<')) return false;
      // The first update onto V or later, not every update after it.
      if ($from !== null && version_compare($from, $want[1], '>=')) return false;
    }
  }

  $was = $trap['from'];
  if ($was === 'unrecorded' && $from !== null) return false;
  if (is_array($was) && $from !== null && version_compare($from, $was[1], '>=')) return false;

  switch ($trap['onboarded']) {
    case 'never_this_plugin':   return !was_onboarded($plugin_name);
    case 'already_this_plugin': return was_onboarded($plugin_name);
    case 'never_any_tangible':
      $map = get_option(ONBOARDED_OPTION, []);
      return empty($map) || !is_array($map);
  }
  return true;
}

function trap_redirect_flag($plugin_name) {
  return 'tangible_onboarding_trap_redirect__' . $plugin_name;
}

/**
 * Fire the traps an update matches: record each (once per site and version)
 * and raise the plugin's trap-redirect flag. Returns the ids that fired.
 */
function fire_traps($plugin_name, $from, $to) {
  $fired = get_option(TRAPS_FIRED_OPTION, []);
  if (!is_array($fired)) $fired = [];
  $ids = [];
  foreach (get_traps($plugin_name) as $trap) {
    $key = $plugin_name . ':' . $trap['id'] . '@' . $to;
    if (isset($fired[ $key ]) || !trap_matches($trap, $plugin_name, $from, $to)) continue;
    $fired[ $key ] = time();
    $ids[] = $trap['id'];
  }
  if ($ids) {
    update_option(TRAPS_FIRED_OPTION, $fired, false);
    update_option(trap_redirect_flag($plugin_name), 1, false);
  }
  return $ids;
}

/**
 * Whether this request may perform a trap redirect for the plugin. A request
 * that can't (AJAX, cron, a non-admin, a bulk activation, network admin)
 * leaves the flag for the next one that can.
 */
function trap_redirect_due($plugin_name) {
  if (!get_option(trap_redirect_flag($plugin_name))) return false;
  if (is_disabled()) {
    delete_option(trap_redirect_flag($plugin_name));
    return false;
  }
  if (wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return false;
  if (!current_user_can('manage_options') || isset($_GET['activate-multi']) || is_network_admin()) return false;
  return true;
}

/**
 * Wire traps for one plugin; called by register_wizard() with whether this
 * load consumed the plugin's activation.
 */
function register_traps($plugin, $just_activated) {
  $name = $plugin->name;
  if (empty($plugin->version)) return;

  // Before the redirect checks on the same hook (priority 10 and 11).
  add_action('admin_init', function () use ($name, $plugin, $just_activated) {
    $update = detect_update($name, (string) $plugin->version, $just_activated);
    if ($update) fire_traps($name, $update['from'], (string) $plugin->version);
  }, 5);

  // After every activation redirect (priority 10): activation wins a tie, and
  // the trap's flag waits for the next load.
  add_action('admin_init', function () use ($name, $plugin) {
    if (!trap_redirect_due($name)) return;
    delete_option(trap_redirect_flag($name));
    if (!pending_questions(\tangible\onboarding\resolve_plan($name, build_facts($name)))) return;
    mark_onboarded($name);
    wp_safe_redirect(get_setup_url($plugin));
    exit;
  }, 11);
}
