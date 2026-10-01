<?php
/**
 * The grant, wp-admin side (spec §1, §3, §4, §5).
 *
 *   start     state + PKCE verifier in a transient keyed to the initiating WP
 *             user, then TOP-LEVEL navigation to tangible.one/connect/authorize
 *   return    nonce, state, and same-user checks; server-to-server exchange of
 *             code + verifier for the site token; store it; ask the platform
 *             to verify the site (it calls our REST verify endpoint back)
 *   disconnect revoke server-side, then drop the local token
 */
namespace tangible\connect;

function flow_key($user_id) {
  return 'tangible_connect_flow_' . (int) $user_id;
}

function random_token($length) {
  // wp_generate_password without specials: [A-Za-z0-9], CSPRNG-backed.
  return wp_generate_password($length, false, false);
}

function return_uri() {
  return add_query_arg(
    [ 'action' => 'tangible_connect_return', '_wpnonce' => wp_create_nonce('tangible_connect_return') ],
    admin_url('admin-post.php')
  );
}

/**
 * Begin a grant for $user_id. Returns the authorize URL to send the browser
 * to. $return_to is where the admin lands afterwards; $plugin_name (optional)
 * is the wizard whose Connect step started this.
 */
function start_flow($user_id, $return_to, $plugin_name = '') {
  $state = random_token(32);
  $verifier = random_token(64);
  $redirect_uri = return_uri();
  set_transient(flow_key($user_id), [
    'state'        => $state,
    'verifier'     => $verifier,
    'redirect_uri' => $redirect_uri,
    'return_to'    => $return_to,
    'plugin'       => $plugin_name,
  ], FLOW_TTL);

  return add_query_arg(array_map('rawurlencode', [
    'site_url'              => site_address(),
    'install_id'            => get_site_id(),
    'state'                 => $state,
    'code_challenge'        => pkce_challenge($verifier),
    'code_challenge_method' => 'S256',
    'redirect_uri'          => $redirect_uri,
  ]), app_base() . '/connect/authorize');
}

/**
 * Ask the platform to verify the site. Returns 'active' | 'pending' |
 * 'revoked'; a revoked or host-mismatched token is dropped locally.
 */
function confirm($context_slug = '') {
  $token = get_token();
  if ($token === '') return 'revoked';
  $result = api_post('/api/v1/connect/confirm', array_filter([
    'site_url' => site_address(),
    'slug'     => $context_slug,
  ]), $token);
  if (is_wp_error($result)) return 'pending';
  [$code, $body] = $result;
  if ($code === 401 || $code === 403) {
    clear();
    return 'revoked';
  }
  $state = get_state();
  if ($code === 200 && ($body['status'] ?? '') === 'active') {
    $state['status'] = 'active';
    $state['verified_at'] = time();
    unset($state['verify_error']);
    save_state($state);
    return 'active';
  }
  $state['verify_error'] = sanitize_key($body['reason'] ?? $body['error'] ?? 'unknown');
  save_state($state);
  return 'pending';
}

const REFRESH_EVENT = 'tangible_connect_refresh_updates';

add_action(REFRESH_EVENT, function () {
  if (!class_exists('tangible\\updater') || !isset(\tangible\updater::$instance->update_checkers)) return;
  foreach ((array) \tangible\updater::$instance->update_checkers as $checker) {
    if (is_object($checker) && method_exists($checker, 'checkForUpdates')) $checker->checkForUpdates();
  }
});

/** Everything that should happen once a site is connected. */
function after_connected($plugin_name) {
  if ($plugin_name !== '' && function_exists('tangible\\onboarding\\mark')) {
    \tangible\onboarding\mark($plugin_name, STEP_ID, 'done');
  }
  // The update check is cached ~12h; refresh soon so the onboarding block
  // arrives — in cron, not in this request (which is already waiting on the
  // platform's callback into this site).
  if (!wp_next_scheduled(REFRESH_EVENT)) wp_schedule_single_event(time() + 5, REFRESH_EVENT);
  do_action('tangible_connect_connected');
}

/**
 * The return leg, without HTTP. $query is the redirect's query args. The
 * caller has checked the capability and the nonce. Returns
 * [ 'status' => connected|pending|denied|error, 'return_to' => url ].
 */
function handle_return($user_id, $query) {
  $flow = get_transient(flow_key($user_id));
  $fallback = [ 'status' => 'error', 'return_to' => admin_url('admin.php?page=tangible-home') ];
  // No flow for THIS user: expired, already used, or someone else started it.
  if (!is_array($flow) || empty($flow['state'])) return $fallback;
  $out = [ 'return_to' => $flow['return_to'] ?: $fallback['return_to'] ];
  if (!hash_equals((string) $flow['state'], (string) ($query['state'] ?? ''))) {
    return $out + [ 'status' => 'error' ];
  }
  delete_transient(flow_key($user_id));   // single use, whatever happens next

  if (!empty($query['error'])) return $out + [ 'status' => 'denied' ];
  $code = (string) ($query['code'] ?? '');
  if (!preg_match('/^tgo_code_[A-Za-z0-9]{32}$/', $code)) return $out + [ 'status' => 'error' ];

  $result = api_post('/api/v1/connect/exchange', array_filter([
    'code'          => $code,
    'code_verifier' => $flow['verifier'],
    'site_url'      => site_address(),
    'install_id'    => get_site_id(),
    'redirect_uri'  => $flow['redirect_uri'],
    'slug'          => $flow['plugin'] ?? '',
  ]));
  if (is_wp_error($result)) return $out + [ 'status' => 'error' ];
  [$status, $body] = $result;
  $token = (string) ($body['token'] ?? '');
  if ($status !== 200 || !preg_match('/^tgo_site_[A-Za-z0-9]{43}$/', $token)) {
    return $out + [ 'status' => 'error' ];
  }

  // Stored before confirm: the platform's verify callback needs it.
  save_state([
    'token'              => $token,
    'status'             => 'pending',
    'site_url'           => site_address(),
    'account_name'       => sanitize_text_field($body['account']['name'] ?? ''),
    'owner_email_masked' => sanitize_text_field($body['account']['owner_email_masked'] ?? ''),
    'connected_at'       => time(),
    'connected_by'       => (int) $user_id,
  ]);

  if (confirm($flow['plugin'] ?? '') !== 'active') return $out + [ 'status' => 'pending' ];
  after_connected((string) ($flow['plugin'] ?? ''));
  return $out + [ 'status' => 'connected' ];
}

/** Revoke server-side (best-effort), then drop the local token regardless. */
function disconnect() {
  $token = get_token();
  if ($token !== '') {
    api_post('/api/v1/connect/disconnect', [ 'site_url' => site_address() ], $token);
  }
  clear();
}

function notice_key($user_id) {
  return 'tangible_connect_notice_' . (int) $user_id;
}

// ── admin-post endpoints ─────────────────────────────────────────────────

add_action('admin_post_tangible_connect_start', function () {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);
  check_admin_referer('tangible_connect_start');
  $return_to = wp_validate_redirect(
    wp_unslash($_POST['return_to'] ?? ''),
    admin_url('admin.php?page=tangible-home')
  );
  wp_redirect(start_flow(get_current_user_id(), $return_to));   // external, deliberate
  exit;
});

add_action('admin_post_tangible_connect_return', function () {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);
  check_admin_referer('tangible_connect_return');
  $result = handle_return(get_current_user_id(), wp_unslash($_GET));
  set_transient(notice_key(get_current_user_id()), $result['status'], 5 * MINUTE_IN_SECONDS);
  wp_safe_redirect($result['return_to']);
  exit;
});

add_action('admin_post_tangible_connect_confirm', function () {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);
  check_admin_referer('tangible_connect_confirm');
  $status = confirm();
  if ($status === 'active') after_connected('');
  set_transient(notice_key(get_current_user_id()), $status === 'active' ? 'connected' : $status, 5 * MINUTE_IN_SECONDS);
  wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=tangible-home'));
  exit;
});

add_action('admin_post_tangible_connect_disconnect', function () {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);
  check_admin_referer('tangible_connect_disconnect');
  disconnect();
  set_transient(notice_key(get_current_user_id()), 'disconnected', 5 * MINUTE_IN_SECONDS);
  wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=tangible-home'));
  exit;
});

// The wizard's Connect step leaves wp-admin through the shell's
// wp_safe_redirect. Allow tangible.one for that one request only — never
// site-wide (wp-login.php?redirect_to= and friends stay closed).
add_filter('allowed_redirect_hosts', function ($hosts) {
  if (!doing_action('admin_post_tangible_onboarding_step')) return $hosts;
  $host = wp_parse_url(app_base(), PHP_URL_HOST);
  if ($host) $hosts[] = $host;
  return $hosts;
});
