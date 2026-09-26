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
 *   - Usage data: a connected (or keyed) plugin's answer lives on the account.
 *     The licence-check `telemetry` block that would carry it is not built, so
 *     the row shows only what is locally provable: an answer still waiting to
 *     sync, or "on file" when the server said it needs no asking.
 *     A free unconnected plugin shows the site's own answer.
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

  // Distribution — the server's verdict, cached by the updater from its update
  // check. Unknown until that first answer (null).
  if (function_exists('tangible\\updater\\is_free_distribution')) {
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
    $ask = (array) ($cached['ask'] ?? []);
    $f['telemetry_on_file'] = ($ask['telemetry_extended'] ?? null) === 'skip';
  }

  // The site's own consent answer (the onboarder's outbox, site-wide).
  $outbox = get_option('tangible_consent_outbox', []);
  if (is_array($outbox) && isset($outbox['telemetry_extended']['answer'])) {
    $f['telemetry_local']  = (string) $outbox['telemetry_extended']['answer'];
    $f['telemetry_synced'] = !empty($outbox['telemetry_extended']['synced']);
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
    'telemetry_local'   => null,    // granted | declined | null — the site's outbox answer
    'telemetry_synced'  => false,
    'telemetry_on_file' => false,   // the server said "don't ask", i.e. an account answer exists
    'has_wizard'        => false,
    'steps_left'        => 0,
    'steps_total'       => 0,
    'update'            => null,
  ];
}

/**
 * The row, decided. Returns:
 *
 *   account  [ key: 'Licence'|'Account', chip, text, sub, action ]
 *   usage    [ chip, text, sub ] or null (not a Tangible-licensed plugin)
 *   setup    null (no wizard) | [ done, left, pct ]
 *   primary  resume | enter_key | renew | update | open
 *
 * chip is one of ok | free | need | bad | share | off.
 * action (account) is null | connect | enter_key | renew.
 */
function row_state($f) {
  $f += default_facts();
  $keyless_free = $f['free'] === true && !$f['has_key'];

  // ── account / licence ──────────────────────────────────────────────────
  if ($keyless_free) {
    $key = 'Account';
    if ($f['connect'] === 'active') {
      $account = chip('ok', 'Connected', $f['connect_account'] !== '' ? $f['connect_account'] : 'Updates through this site\'s connection');
    } elseif ($f['connect'] === 'pending') {
      $account = chip('need', 'Connecting', 'Waiting for tangible.one to verify this site');
    } else {
      $account = chip('free', 'Free · not connected', 'Works without an account', 'connect');
    }
  } else {
    $key = 'Licence';
    $status = strtolower($f['licence_status']);
    if (!$f['licence_managed'] || ($f['free'] === null && !$f['cloud'] && !$f['has_key'])) {
      // Not something this site's updater reports on (or a legacy build that
      // never talks to tangible.one) — say nothing rather than "no licence needed".
      $account = chip('off', 'No licence info', '');
    } elseif ($f['free'] === null && !$f['has_key']) {
      // Neither free nor paid is known yet — a nag here would be wrong for
      // every free plugin (same rule the updater's own notices follow).
      $account = chip('off', 'Checking', 'Known after the first update check');
    } elseif (!$f['has_key']) {
      $account = chip('need', 'Key not entered', 'Paid features stay locked until it is activated', 'enter_key');
    } elseif (in_array($status, LICENCE_OK, true)) {
      $account = chip('ok', 'Active', $f['account_name']);
    } elseif (in_array($status, LICENCE_EXPIRED, true)) {
      $account = chip('bad', 'Expired', 'Keeps working · no updates', 'renew');
    } elseif (in_array($status, LICENCE_INVALID, true)) {
      $account = chip('bad', 'Key not valid', 'Check the key on the licence page', 'enter_key');
    } else {
      $account = chip('need', 'Not activated', 'Key entered, not active on this site', 'enter_key');
    }
  }
  $account['key'] = $key;

  // ── usage data ─────────────────────────────────────────────────────────
  $usage = null;
  $server_side = ($keyless_free && $f['connect'] === 'active')
    || (!$keyless_free && $f['has_key'] && in_array(strtolower($f['licence_status']), LICENCE_OK, true));

  if ($keyless_free && !$server_side) {
    // Anonymous: the site's own answer is the only answer.
    if ($f['telemetry_local'] === 'granted')      $usage = chip('share', 'On · this site', '');
    elseif ($f['telemetry_local'] === 'declined') $usage = chip('off', 'Off · this site', '');
    else                                          $usage = chip('off', 'Not asked yet', '');
  } elseif ($server_side) {
    if ($f['telemetry_local'] !== null && !$f['telemetry_synced']) {
      $usage = chip($f['telemetry_local'] === 'granted' ? 'share' : 'off',
        ($f['telemetry_local'] === 'granted' ? 'On' : 'Off') . ' · sync pending',
        'Answered here, not yet on the account');
    } elseif ($f['telemetry_on_file']) {
      $usage = chip('off', 'On file · account setting', 'Change it at tangible.one');
    } else {
      $usage = chip('off', 'Not asked yet', '');
    }
  } elseif ($f['licence_managed'] && $f['free'] !== null && $account['action'] === 'enter_key') {
    $usage = chip('off', 'Not asked yet', 'Asked after the key is activated');
  }

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

  return [ 'account' => $account, 'usage' => $usage, 'setup' => $setup, 'primary' => $primary ];
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
