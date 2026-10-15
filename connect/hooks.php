<?php
/**
 * Connect's seams into the rest of the site:
 *
 *   REST  POST tangible/v1/connect/verify — the platform's site-verification
 *         callback. Public by necessity (tangible.one calls it); it answers
 *         only while a connection is UNVERIFIED, and only with an HMAC proof
 *         derived from the token, never the token.
 *   PUC   the updater's get_metadata carries the token in a request header on
 *         keyless checks, and the connected `onboarding` block is cached from
 *         the response as the wizard's facts. The response's `connection`
 *         keeps the stored status current, which is how a site verified in
 *         the background (the platform retries) learns it — no cron of our
 *         own. A `connection: revoked` answer drops the token (fall back to
 *         Anonymous). The exchange and confirm answers carry the same block,
 *         cached for every free plugin on the site (cache_onboarding_block).
 *   Hub  the round-trip notice copy; hub/page.php draws the band and the
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
  if ($token === '' || normalize_status(get_state()['status'] ?? '') !== 'unverified') {
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

/**
 * Tangible Site Gate closes the REST API to logged-out visitors on sites that
 * have not launched — which includes the platform's verification call, so a
 * gated site could approve a connection but never finish it. Let exactly this
 * route through: it answers only while a connection is pending, and the proof
 * is its own authentication.
 */
function is_verify_request() {
  $route = (string) ($GLOBALS['wp']->query_vars['rest_route'] ?? ($_GET['rest_route'] ?? ''));
  if ($route === '') $route = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
  return (bool) preg_match('#/tangible/v1/connect/verify/?$#', $route);
}

add_filter('tangible_site_gate_bypass', function ($bypass) {
  return $bypass || is_verify_request();
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
  $status = normalize_status($connection);
  // The server answers `connection: active` for every working token (older
  // plugins only cache onboarding data on 'active') and says whether it is
  // verified in a separate `verified` flag.
  if ($status === 'active' && ($body['verified'] ?? true) === false) $status = 'unverified';
  if (!in_array($status, ['unverified', 'active'], true)) return;
  if ($status !== normalize_status(get_state()['status'] ?? '')) set_status($status);
  if (isset($body['onboarding'])) cache_onboarding_block($body['onboarding'], [ $name ]);
}

/**
 * Cache the account's `onboarding` block (the same shape get_metadata,
 * exchange and confirm send) as the wizard's facts for each named plugin —
 * what onboarding\build_facts() reads. Deep-converted to arrays: nested
 * stdClass once made the reader ignore a good block. Not an array → nothing
 * is written. Returns whether it wrote.
 */
function cache_onboarding_block($block, $plugin_names) {
  $data = json_decode(wp_json_encode($block), true);
  if (!is_array($data)) return false;
  foreach ((array) $plugin_names as $name) {
    if (!is_string($name) || $name === '') continue;
    update_option('tangible_onboarding_facts_cache__' . $name,
      [ 'at' => time(), 'data' => $data, 'source' => 'connect' ], false);
  }
  return true;
}

/**
 * Every registered plugin the site's connection speaks for: free and holding
 * no licence key. Connect is site-wide, so these share one account's answers.
 */
function covered_plugin_names() {
  if (!class_exists('tangible\\framework') || !function_exists('tangible\\onboarding\\is_free_distribution')) return [];
  $names = [];
  foreach ((array) (framework::$state->plugins ?? []) as $plugin) {
    $name = $plugin->name ?? '';
    if (!is_string($name) || $name === '' || !empty($plugin->is_theme)) continue;
    if (\tangible\onboarding\is_free_distribution($plugin) && !plugin_has_license_key($name)) $names[] = $name;
  }
  return array_values(array_unique($names));
}

/** An exchange or confirm answer's `onboarding` block, cached for every covered plugin. */
function cache_onboarding_from_response($body) {
  if (!is_array($body) || !isset($body['onboarding'])) return;
  cache_onboarding_block($body['onboarding'], covered_plugin_names());
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
  $names = covered_plugin_names();
  if (class_exists('tangible\\updater') && isset(\tangible\updater::$instance->update_checkers)) {
    $names = array_merge($names, array_keys((array) \tangible\updater::$instance->update_checkers));
  }
  foreach (array_unique($names) as $name) {
    $cache = get_option('tangible_onboarding_facts_cache__' . $name, null);
    if (is_array($cache) && ($cache['source'] ?? '') === 'connect') {
      delete_option('tangible_onboarding_facts_cache__' . $name);
    }
  }
});

// ── Hub pieces (the page itself is hub/page.php) ─────────────────────

/**
 * What the Hub says after a Connect round trip (the flow sets the key).
 * NOTICE_COPY is the English source, kept for code that read it; the Hub
 * shows notice_copy(), translated.
 */
function notice_copy($key) {
  $copy = [
    'connected'    => __('This site is connected to tangible.one.', 'tangible-framework'),
    'verified'     => __('tangible.one has verified this site.', 'tangible-framework'),
    'unverified'   => __('tangible.one still could not reach this site. It keeps trying, and the connection works in the meantime.', 'tangible-framework'),
    'denied'       => __('Connection cancelled.', 'tangible-framework'),
    'error'        => __('Connecting did not work. Start again.', 'tangible-framework'),
    'revoked'      => __('The connection was revoked on tangible.one.', 'tangible-framework'),
    'disconnected' => __('This site is no longer connected.', 'tangible-framework'),
  ];
  return $copy[$key] ?? '';
}

const NOTICE_COPY = [
  'connected'    => 'This site is connected to tangible.one.',
  'verified'     => 'tangible.one has verified this site.',
  'unverified'   => 'tangible.one still could not reach this site. It keeps trying, and the connection works in the meantime.',
  'denied'       => 'Connection cancelled.',
  'error'        => 'Connecting did not work. Start again.',
  'revoked'      => 'The connection was revoked on tangible.one.',
  'disconnected' => 'This site is no longer connected.',
];

/**
 * The one plain line a connected-but-unverified site adds under
 * "Connected as …", in the wizard and the Hub alike; '' once verified.
 */
function unverified_note() {
  if (!is_connected() || is_verified()) return '';
  return is_local_site()
    ? __('This is a local site, so it\'s linked to your account without the online check.', 'tangible-framework')
    : __('We\'ll finish linking this site once it\'s online.', 'tangible-framework');
}

/** The Hub's "Why?": the technical reason, never shown in the wizard. */
function unverified_reason() {
  $host = strtolower((string) wp_parse_url(site_address(), PHP_URL_HOST));
  if (is_local_site()) {
    /* translators: %s: this site's host name */
    return sprintf(__('%s is a local site, which tangible.one cannot reach from the internet, so it skips the online check. Everything the connection does for your free plugins works without it.', 'tangible-framework'), $host);
  }
  /* translators: %s: this site's host name */
  return sprintf(__('tangible.one could not reach %s/wp-json/ to verify that this site is yours. Check that the site is public and its REST API is not blocked (Tangible Site Gate lets the check through automatically). tangible.one keeps trying; you can also check again now.', 'tangible-framework'), $host);
}
