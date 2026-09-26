<?php
/**
 * Tangible Connect — link this SITE to a tangible.one account without a
 * licence key, for free Tangible plugins (tangible-one
 * .docs/specs/tangible-connect.md).
 *
 * One connection per site, shared by every Tangible plugin: the token lives
 * in ONE framework-owned site option, so uninstalling any single plugin does
 * not drop it. Paid plugins keep their licence keys and never use Connect.
 *
 *   index.php   state, endpoints, the site token on the wire
 *   flow.php    Connect (start), the return handler (exchange + confirm),
 *               Disconnect
 *   hooks.php   the REST verify endpoint, the updater's get_metadata header,
 *               the notice copy the Hub shows (hub/page.php draws the band)
 *   step.php    the `connect` core onboarder step (free plugins only)
 *
 * Security notes
 *   - Every call to tangible.one here uses VERIFIED TLS (WordPress's default).
 *     Nothing in this module may pass `sslverify => false`.
 *   - The token travels only in the `X-Tangible-Site-Token` request header,
 *     server to server; never in a URL, never through the browser, never
 *     logged. At rest it is readable by any admin of this site by design —
 *     it grants nothing beyond this one site's connection.
 *   - Only `manage_options` users see or drive Connect.
 */
namespace tangible\connect;

const OPTION = 'tangible_connect_site';
const SITE_ID_OPTION = 'tangible_connect_site_id';
const HEADER = 'X-Tangible-Site-Token';
const FLOW_TTL = 15 * MINUTE_IN_SECONDS;

/** API host. Override per site with TANGIBLE_CLOUD_API in wp-config.php. */
function api_base() {
  $base = defined('TANGIBLE_CLOUD_API') ? TANGIBLE_CLOUD_API : 'https://api.tangible.one/';
  return rtrim((string) $base, '/');
}

/** The tangible.one app (consent screen). Override with TANGIBLE_CONNECT_APP_URL. */
function app_base() {
  $base = defined('TANGIBLE_CONNECT_APP_URL') ? TANGIBLE_CONNECT_APP_URL : 'https://tangible.one';
  return rtrim((string) $base, '/');
}

function get_state() {
  $state = get_option(OPTION, []);
  return is_array($state) ? $state : [];
}

/** The stored token whatever its status, or ''. */
function get_token() {
  $token = get_state()['token'] ?? '';
  return is_string($token) && preg_match('/^tgo_site_[A-Za-z0-9]{43}$/', $token) ? $token : '';
}

function is_connected() {
  return get_token() !== '' && (get_state()['status'] ?? '') === 'active';
}

/** The token only while the connection is active — what plugin requests send. */
function get_active_token() {
  return is_connected() ? get_token() : '';
}

function save_state($state) {
  update_option(OPTION, $state, false);   // never autoloaded
}

function clear() {
  delete_option(OPTION);
  do_action('tangible_connect_cleared');
}

/**
 * A per-site identity for the grant. The updater's install id is per plugin,
 * which cannot name a site; this one is Connect's own.
 */
function get_site_id() {
  $id = get_option(SITE_ID_OPTION, '');
  if (is_string($id) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $id)) return $id;
  $id = wp_generate_uuid4();
  update_option(SITE_ID_OPTION, $id, false);
  return $id;
}

/**
 * The URL this site's grant is bound to: `site_url()`, where wp-admin lives
 * (so the return address is always on the same host) and what the updater's
 * get_metadata already sends as `url`. Every Connect request uses it.
 */
function site_address() {
  return site_url();
}

/** RFC 7636 S256: base64url(sha256(verifier)), unpadded. */
function pkce_challenge($verifier) {
  return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

/** HMAC over the platform's challenge, keyed by sha256(token) — the proof. */
function verification_proof($token, $challenge) {
  return hash_hmac('sha256', (string) $challenge, hash('sha256', (string) $token));
}

/**
 * POST JSON to a Connect endpoint. Verified TLS (the WordPress default is
 * kept on purpose). Returns [code, decoded body array] or WP_Error.
 */
function api_post($path, $body, $token = '') {
  $headers = [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ];
  if ($token !== '') $headers[HEADER] = $token;
  $response = wp_remote_post(api_base() . $path, [
    'timeout' => 20,
    'headers' => $headers,
    'body'    => wp_json_encode($body),
  ]);
  if (is_wp_error($response)) return $response;
  $decoded = json_decode(wp_remote_retrieve_body($response), true);
  return [ (int) wp_remote_retrieve_response_code($response), is_array($decoded) ? $decoded : [] ];
}

require_once __DIR__ . '/flow.php';
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/step.php';
