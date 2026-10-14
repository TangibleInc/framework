<?php
/**
 * What each Hub row says, and why — the one place the chips are decided.
 *
 * Two halves on purpose:
 *
 *   gather_facts($plugin)  reads what OTHER modules already know (updater
 *                          licence status and distribution verdict, the
 *                          Connect token, the onboarder's plan, the consent
 *                          outbox, core's update transient). Reads only.
 *   row_state($facts)      a pure function from those facts to what the row
 *                          shows. No WordPress calls, so every chip is
 *                          testable from a plain array (tests/phpunit/hub-rows.php).
 *
 * The Hub never decides state and stores nothing of its own; a chip that
 * cannot be sourced says so ("Not asked yet") rather than guessing.
 *
 * Rules (decided with Titus, 2026-09-26):
 *   - Connect is per SITE and for FREE plugins only. A paid plugin never shows
 *     a Connect chip; a key always wins, even on a free build.
 *   - Usage data is per SITE, not per plugin (ADR-011): one answer covers
 *     every Tangible plugin here, so rows carry no usage cell. The "This site"
 *     block says what the site sends and why (onboarding\site_telemetry_state).
 *   - Setup: resolved from the onboarder's plan on every load — "✓ Set up" or
 *     "N steps left". The permanent way back into the wizard.
 */
namespace tangible\hub;

use tangible\framework;

const LICENCE_OK      = ['valid', 'active'];
const LICENCE_EXPIRED = ['expired'];
const LICENCE_INVALID = ['invalid', 'disabled', 'revoked', 'missing', 'key_mismatch', 'item_name_mismatch'];

/**
 * Facts for one registered plugin. Every key is always present so row_state()
 * can be fed a hand-written array in tests.
 */
function gather_facts($plugin) {
  $name = $plugin->name ?? '';
  $f = default_facts();
  $f['name']    = $name;
  $f['title']   = $plugin->title ?? $name;
  $f['version'] = (string) ($plugin->version ?? '');
  $f['cloud']   = !empty($plugin->cloud_id);
  // Optional plugin config: a 24px icon (URL) for the row's tile, and where
  // its release notes live. Without them the tile shows a generic plugin
  // glyph and the row links to Settings only.
  $f['icon']          = (string) ($plugin->icon ?? '');
  $f['release_notes'] = (string) ($plugin->release_notes_url ?? '');

  // Licence — the updater's, when present and managing this plugin.
  $has_updater = class_exists('tangible\\updater') && isset(\tangible\updater::$instance->update_checkers);
  $f['licence_managed'] = $has_updater
    && isset(((array) \tangible\updater::$instance->update_checkers)[$name]);

  if ($f['licence_managed']) {
    if (function_exists('tangible\\updater\\get_license_key')) {
      $f['has_key'] = !empty(\tangible\updater\get_license_key($plugin));
    }
    if (function_exists('tangible\\updater\\get_license_status')) {
      $f['licence_status'] = (string) \tangible\updater\get_license_status($plugin);
    }
  }
  // Expiry, as the last activation reported it (the onboarder keeps it).
  if (function_exists('tangible\\onboarding\\licence_info')) {
    $f['expires'] = (string) (\tangible\onboarding\licence_info($plugin)['expires'] ?? '');
  }

  // Distribution — the onboarder's rule for plugins on the tangible.one platform
  // (a cloud_id or a wizard): the server's verdict when cached, else free by
  // construction for a build without the updater (wordpress.org), else unknown
  // (null) while a registered plugin waits for its first answer. Legacy builds
  // keep the updater-only read below, so they never turn into "Free".
  $platform = !empty($plugin->cloud_id) || (function_exists('tangible\\onboarding\\registered_wizards')
    && !empty(\tangible\onboarding\registered_wizards()[$name]));
  if ($platform && function_exists('tangible\\onboarding\\is_free_distribution')) {
    if (\tangible\onboarding\is_free_distribution($plugin))         $f['free'] = true;
    elseif (\tangible\onboarding\is_licensed_distribution($plugin)) $f['free'] = false;
    else                                                             $f['free'] = null;
  } elseif (function_exists('tangible\\updater\\is_free_distribution')) {
    $known = function_exists('tangible\\updater\\is_distribution_known')
      ? \tangible\updater\is_distribution_known($plugin) : true;
    $f['free'] = $known ? (bool) \tangible\updater\is_free_distribution($plugin) : null;
  }

  // Site connection — one per site, shared by every free plugin.
  $f['connect'] = connect_status();
  $f['connect_account'] = connect_account_name();

  // Account name from the last licence check that carried one (paid).
  $cache = get_option('tangible_onboarding_facts_cache__' . $name, null);
  $cached = is_array($cache) ? ($cache['data'] ?? null) : null;
  if (is_object($cached)) $cached = (array) $cached;
  if (is_array($cached)) {
    $f['account_name'] = (string) ($cached['accountName'] ?? '');
  }

  // Setup — only plugins that registered a wizard have one.
  if (function_exists('tangible\\onboarding\\registered_wizards')
    && !empty(\tangible\onboarding\registered_wizards()[$name])) {
    $f['has_wizard'] = true;
    $plan = \tangible\onboarding\resolve_plan($name, \tangible\onboarding\build_facts($name));
    $asking = array_values(array_filter($plan['rail'], function ($r) use ($plan) {
      foreach ($plan['steps'] as $s) {
        if ($s['id'] === $r['id'] && !empty($s['terminal'])) return false;
      }
      return $r['id'] !== 'done';
    }));
    $f['steps_total'] = count($asking);
    $f['steps_left']  = count(\tangible\onboarding\pending_questions($plan));
    foreach ($plan['rail'] as $r) {
      if ($r['id'] === ($plan['current'] ?? null)) $f['next_step'] = (string) $r['label'];
    }
  }

  // Pending update — core's transient, which the updater's checker hydrates.
  if (!empty($plugin->file_path)) {
    $updates = get_site_transient('update_plugins');
    $basename = plugin_basename($plugin->file_path);
    if (!empty($updates->response[$basename]->new_version)) {
      $f['update'] = (string) $updates->response[$basename]->new_version;
    }
  }

  return $f;
}

function default_facts() {
  return [
    'name' => '', 'title' => '', 'version' => '',
    'licence_managed'   => false,   // the updater manages this plugin
    'cloud'             => false,   // it is a tangible.one asset (has a cloud_id)
    'has_key'           => false,
    'licence_status'    => '',
    'free'              => false,   // true | false | null (server has not said yet)
    'connect'           => 'none',  // none | pending | active — the SITE's connection
    'connect_account'   => '',
    'account_name'      => '',
    'has_wizard'        => false,
    'steps_left'        => 0,
    'steps_total'       => 0,
    'next_step'         => '',      // the current step's label, while setup is pending
    'expires'           => '',      // licence expiry: a date, 'lifetime', or unknown
    'icon'              => '',
    'release_notes'     => '',
    'update'            => null,
  ];
}

/**
 * The row, decided. Returns:
 *
 *   account  [ key: 'License'|'Account' (translated), chip, text, sub, action ]
 *   setup    null (no wizard) | [ done, left, pct ]
 *   primary  resume | enter_key | renew | update | open
 *   action   the row's own action, setup aside (setup has its own card):
 *            enter_key | renew | update | null
 *
 * chip is one of ok | info | free | need | bad | off.
 * action (account) is null | connect | enter_key | renew.
 */
function row_state($f) {
  $f += default_facts();
  $keyless_free = $f['free'] === true && !$f['has_key'];

  // ── account / licence ──────────────────────────────────────────────────
  if ($keyless_free) {
    $key = __('Account', 'tangible-framework');
    if ($f['connect'] === 'active') {
      $account = chip('info', __('Free', 'tangible-framework'), __('Via your tangible.one connection', 'tangible-framework'));
    } elseif ($f['connect'] === 'pending') {
      $account = chip('need', __('Connecting', 'tangible-framework'), __('Waiting for tangible.one to verify this site', 'tangible-framework'));
    } else {
      $account = chip('off', __('Not connected', 'tangible-framework'), __('Free', 'tangible-framework'), 'connect');
    }
  } else {
    $key = __('License', 'tangible-framework');
    $status = strtolower($f['licence_status']);
    if (!$f['licence_managed'] || ($f['free'] === null && !$f['cloud'] && !$f['has_key'])) {
      // Not something this site's updater reports on (or a legacy build that
      // never talks to tangible.one) — say nothing rather than "no licence needed".
      $account = chip('off', __('No license info', 'tangible-framework'), '');
    } elseif ($f['free'] === null && !$f['has_key']) {
      // Neither free nor paid is known yet — a nag here would be wrong for
      // every free plugin (same rule the updater's own notices follow).
      $account = chip('off', __('Checking', 'tangible-framework'), __('Known after the first update check', 'tangible-framework'));
    } elseif (!$f['has_key']) {
      $account = chip('need', __('Key not entered', 'tangible-framework'), __('Paid features stay locked until it is activated', 'tangible-framework'), 'enter_key');
    } elseif (in_array($status, LICENCE_OK, true)) {
      $account = chip('ok', __('Active', 'tangible-framework'), renews_line($f['expires'], $f['account_name']));
    } elseif (in_array($status, LICENCE_EXPIRED, true)) {
      $account = chip('bad', __('Expired', 'tangible-framework'), expired_line($f['expires']), 'renew');
    } elseif (in_array($status, LICENCE_INVALID, true)) {
      $account = chip('bad', __('Invalid key', 'tangible-framework'), __('Check the key on the license page', 'tangible-framework'), 'enter_key');
    } else {
      $account = chip('need', __('Not activated', 'tangible-framework'), __('Key entered, not active on this site', 'tangible-framework'), 'enter_key');
    }
  }
  $account['key'] = $key;

  // ── setup ──────────────────────────────────────────────────────────────
  $setup = null;
  if ($f['has_wizard']) {
    $left = max(0, (int) $f['steps_left']);
    $total = max($left, (int) $f['steps_total']);
    $setup = [
      'done' => $left === 0,
      'left' => $left,
      'pct'  => $total > 0 ? (int) round(100 * ($total - $left) / $total) : 100,
    ];
  }

  // ── the one primary action ─────────────────────────────────────────────
  if ($setup && !$setup['done'])                  $primary = 'resume';
  elseif ($account['action'] === 'enter_key')     $primary = 'enter_key';
  elseif ($account['action'] === 'renew')         $primary = 'renew';
  elseif ($f['update'])                           $primary = 'update';
  else                                            $primary = 'open';

  if (in_array($account['action'], ['enter_key', 'renew'], true)) $action = $account['action'];
  elseif ($f['update'])                                           $action = 'update';
  else                                                            $action = null;

  return [ 'account' => $account, 'setup' => $setup, 'primary' => $primary, 'action' => $action ];
}

/** "Renews 12 Mar 2027", "Lifetime license", or the account name when the date is not known. */
function renews_line($expires, $fallback = '') {
  if ($expires === 'lifetime') return __('Lifetime license', 'tangible-framework');
  $time = $expires !== '' ? strtotime($expires) : false;
  /* translators: %s: renewal date */
  return $time ? sprintf(__('Renews %s', 'tangible-framework'), date_i18n('j M Y', $time)) : (string) $fallback;
}

function expired_line($expires) {
  $time = $expires !== '' && $expires !== 'lifetime' ? strtotime($expires) : false;
  /* translators: %s: expiry date */
  return $time ? sprintf(__('Expired %s. Updates are paused', 'tangible-framework'), date_i18n('j M Y', $time))
    : __('Updates are paused', 'tangible-framework');
}

function chip($chip, $text, $sub, $action = null) {
  return [ 'chip' => $chip, 'text' => $text, 'sub' => $sub, 'action' => $action ];
}

// ── the site's connection (Connect module, when loaded) ──────────────────

function connect_status() {
  if (!function_exists('tangible\\connect\\get_token')) return 'none';
  if (\tangible\connect\get_token() === '') return 'none';
  return (\tangible\connect\get_state()['status'] ?? '') === 'active' ? 'active' : 'pending';
}

function connect_account_name() {
  if (!function_exists('tangible\\connect\\get_state')) return '';
  return (string) (\tangible\connect\get_state()['account_name'] ?? '');
}

/**
 * The Connect band: shown while the site is not connected — with or without
 * a free plugin installed (the pitch changes) — unless this admin said
 * "Not now" in the last 30 days.
 *
 *   null | [ status: none|pending, has_free: bool ]
 */
function band_state($connect, $has_free, $snoozed_until, $now) {
  if ($connect === 'active') return null;
  if ($connect === 'none' && $snoozed_until > $now) return null;
  return [ 'status' => $connect, 'has_free' => (bool) $has_free ];
}

const BAND_SNOOZE_META = 'tangible_hub_connect_snoozed_until';
const BAND_SNOOZE_DAYS = 30;
