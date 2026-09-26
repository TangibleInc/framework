<?php
/**
 * Connect's seams into the rest of the site:
 *
 *   REST  POST tangible/v1/connect/verify — the platform's site-verification
 *         callback. Public by necessity (tangible.one calls it); it answers
 *         only while a connection is PENDING, and only with an HMAC proof
 *         derived from the token, never the token.
 *   PUC   the updater's get_metadata carries the token in a request header on
 *         keyless checks, and the connected `onboarding` block is cached from
 *         the response as the wizard's facts. A `connection: revoked` answer
 *         drops the token (fall back to Anonymous).
 *   Hub   the round-trip notice copy; hub/page.php draws the band and the
 *         site block and posts to flow.php's actions.
 */
namespace tangible\connect;

use tangible\framework;

// ── REST verify ────────────────────────────────────────────────────────────

function rest_verify($request) {
  $challenge = (string) ($request->get_param('challenge') ?? '');
  if (!preg_match('/^[A-Za-z0-9]{16,128}$/', $challenge)) {
    return new \WP_REST_Response([ 'error' => 'invalid_challenge' ], 400);
  }
  $token = get_token();
  if ($token === '' || (get_state()['status'] ?? '') !== 'pending') {
    return new \WP_REST_Response([ 'error' => 'not_pending' ], 404);
  }
  return new \WP_REST_Response([ 'proof' => verification_proof($token, $challenge) ], 200);
}

add_action('rest_api_init', function () {
  register_rest_route('tangible/v1', '/connect/verify', [
    'methods'             => 'POST',
    'callback'            => __NAMESPACE__ . '\\rest_verify',
    // The platform is the caller and has no WP identity; the proof is the auth.
    'permission_callback' => '__return_true',
  ]);
});

// ── updater (PUC) ─────────────────────────────────────────────────────────

/** Keyless plugins only: a key always wins, paid plugins never use Connect. */
function plugin_has_license_key($name) {
  if (!function_exists('tangible\\updater\\get_license_key')) return false;
  $plugin = function_exists('tangible\\framework\\get_plugin') ? framework\get_plugin($name) : null;
  return $plugin ? !empty(\tangible\updater\get_license_key($plugin)) : false;
}

/**
 * Only a plugin whose update server IS the Connect API host may carry the
 * token — never the legacy updater host or a plugin's own updater_url.
 * (Staging: point TANGIBLE_CLOUD_URL and TANGIBLE_CLOUD_API at the same host.)
 */
function plugin_talks_to_connect_api($name) {
  $plugin = function_exists('tangible\\framework\\get_plugin') ? framework\get_plugin($name) : null;
  if (!$plugin || empty($plugin->cloud_id) || empty($plugin->updater_url)) return false;
  $host = strtolower((string) wp_parse_url($plugin->updater_url, PHP_URL_HOST));
  return $host !== '' && $host === strtolower((string) wp_parse_url(api_base(), PHP_URL_HOST));
}

/** Add the token header to one plugin's metadata request options. */
function with_token_header($options, $name) {
  $token = get_active_token();
  if ($token === '' || plugin_has_license_key($name) || !plugin_talks_to_connect_api($name)) return $options;
  $options['headers'] = (array) ($options['headers'] ?? []);
  $options['headers'][HEADER] = $token;
  return $options;
}

/** Read the connected state back from one metadata response. */
function read_metadata_result($name, $result) {
  if (!$result || is_wp_error($result) || get_token() === '' || plugin_has_license_key($name)) return;
  $body = json_decode(wp_remote_retrieve_body($result), true);
  if (!is_array($body)) return;
  $connection = $body['connection'] ?? null;
  if ($connection === 'revoked' || $connection === 'host_mismatch') {
    clear();
    return;
  }
  if ($connection === 'active' && isset($body['onboarding']) && is_array($body['onboarding'])) {
    update_option('tangible_onboarding_facts_cache__' . $name,
      [ 'at' => time(), 'data' => $body['onboarding'], 'source' => 'connect' ], false);
  }
}

/** Wire every plugin the updater registered. Idempotent per name. */
function hook_update_checkers() {
  static $hooked = [];
  if (!class_exists('tangible\\updater') || !isset(\tangible\updater::$instance->update_checkers)) return;
  foreach ((array) \tangible\updater::$instance->update_checkers as $name => $checker) {
    if (isset($hooked[$name])) continue;
    $hooked[$name] = true;
    add_filter('puc_request_info_options-' . $name, function ($options) use ($name) {
      return with_token_header($options, $name);
    });
    add_filter('puc_request_info_result-' . $name, function ($info, $result = null) use ($name) {
      read_metadata_result($name, $result);
      return $info;
    }, 10, 2);
  }
}
add_action('init', __NAMESPACE__ . '\\hook_update_checkers', 20);

/** Connected-account facts must not outlive the connection. */
add_action('tangible_connect_cleared', function () {
  if (!class_exists('tangible\\updater') || !isset(\tangible\updater::$instance->update_checkers)) return;
  foreach (array_keys((array) \tangible\updater::$instance->update_checkers) as $name) {
    $cache = get_option('tangible_onboarding_facts_cache__' . $name, null);
    if (is_array($cache) && ($cache['source'] ?? '') === 'connect') {
      delete_option('tangible_onboarding_facts_cache__' . $name);
    }
  }
});

// ── Hub pieces (the page itself is hub/page.php) ─────────────────────

/** What the Hub says after a Connect round trip (the flow sets the key). */
const NOTICE_COPY = [
  'connected'    => 'This site is connected to tangible.one.',
  'pending'      => 'Connected, but tangible.one could not verify this site yet. Check that the REST API is reachable, then try again.',
  'denied'       => 'Connection cancelled.',
  'error'        => 'Connecting did not work. Start again.',
  'revoked'      => 'The connection was revoked on tangible.one.',
  'disconnected' => 'This site is no longer connected.',
];
