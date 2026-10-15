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
 *   step.php    the Connect card inside the onboarder's Welcome step (free
 *               plugins only)
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

/**
 * The origin of TANGIBLE_CLOUD_URL — the updater's switch, e.g.
 * `https://api.staging.tangible.one/api/edd` → `https://api.staging.tangible.one`.
 * A site pointed at staging for licences must not Connect to prod by default.
 */
function cloud_url_origin() {
  return defined('TANGIBLE_CLOUD_URL') ? url_origin((string) TANGIBLE_CLOUD_URL) : '';
}

/** scheme://host of a URL, or '' when it has neither. */
function url_origin($url) {
  $parts = wp_parse_url($url);
  if (empty($parts['scheme']) || empty($parts['host'])) return '';
  return $parts['scheme'] . '://' . $parts['host'];
}

/** The app for an API origin: its `api.` label dropped. */
function app_for_api_origin($origin) {
  return preg_replace('#^(https?://)api\.#', '$1', $origin);
}

/**
 * API host. Override per site with TANGIBLE_CLOUD_API in wp-config.php; else
 * the origin of TANGIBLE_CLOUD_URL; else production.
 */
function api_base() {
  if (defined('TANGIBLE_CLOUD_API')) return rtrim((string) TANGIBLE_CLOUD_API, '/');
  return cloud_url_origin() ?: 'https://api.tangible.one';
}

/**
 * The tangible.one app (consent screen). Override with TANGIBLE_CONNECT_APP_URL;
 * else TANGIBLE_CLOUD_URL's host with its `api.` label dropped
 * (api.staging.tangible.one → staging.tangible.one); else production.
 */
function app_base() {
  if (defined('TANGIBLE_CONNECT_APP_URL')) return rtrim((string) TANGIBLE_CONNECT_APP_URL, '/');
  $origin = cloud_url_origin();
  return $origin ? app_for_api_origin($origin) : 'https://tangible.one';
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

/**
 * The platform's word for a connection, as this site stores it:
 * `unverified` (usable, the address not proven yet), `active` (verified) or
 * `revoked`; '' for anything else. Servers before 2026-10-14 said `pending`
 * for what is now `unverified`.
 */
function normalize_status($status) {
  $status = (string) $status;
  if ($status === 'pending') return 'unverified';
  return in_array($status, ['unverified', 'active', 'revoked'], true) ? $status : '';
}

/**
 * Connected: a token whose connection is unverified or active (spec §4,
 * amended 2026-10-14 — a connection works before it is verified). A state
 * stored as `pending` by an older framework counts as unverified.
 */
function is_connected() {
  return get_token() !== ''
    && in_array(normalize_status(get_state()['status'] ?? ''), ['unverified', 'active'], true);
}

/** Connected AND tangible.one has verified this site's address. */
function is_verified() {
  return get_token() !== '' && (get_state()['status'] ?? '') === 'active';
}

/** The token while connected (verified or not) — what plugin requests send. */
function get_active_token() {
  return is_connected() ? get_token() : '';
}

/**
 * A host only a developer's machine or a private network resolves:
 * localhost, the reserved local suffixes, the common local-dev domains, or a
 * private, loopback or link-local IP literal. The one host rule — the
 * onboarder's is_dev_site() uses it too.
 */
function is_local_host($host) {
  $host = strtolower(trim((string) $host, '[] .'));
  if ($host === '') return false;
  if ($host === 'localhost') return true;
  if (preg_match('/\.(local|test|localhost|internal|lan|home\.arpa|ddev\.site|lndo\.site)$/', $host)) return true;
  if (filter_var($host, FILTER_VALIDATE_IP) === false) return false;
  // Older PHP misses some IPv6 reserved ranges, so loopback and link-local are named.
  if ($host === '::1' || strpos($host, 'fe80:') === 0) return true;
  return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * A site tangible.one can never reach to verify: a local host (above), or
 * WordPress's environment type says local or development. Such a site is
 * never asked to verify; it stays `unverified` and works anyway.
 */
function is_local_site() {
  $local = in_array(wp_get_environment_type(), ['local', 'development'], true)
    || is_local_host(wp_parse_url(site_address(), PHP_URL_HOST));
  return (bool) apply_filters('tangible_connect_is_local_site', $local);
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
