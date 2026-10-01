<?php
namespace tests\framework;

use tangible\connect;
use tangible\onboarding;

/*
 * The updater is not in the framework's wp-env. Stand in the one function the
 * Connect step reads, with the real storage contract (the server's verdict
 * cached as `{prefix}_distribution`). Other suites never set it, so for them
 * nothing changes.
 */
if (!function_exists('tangible\\updater\\is_free_distribution')) {
  eval('namespace tangible\\updater;
    function is_free_distribution($plugin) {
      return get_option((($plugin->setting_prefix ?? $plugin->name) ?? "") . "_distribution") === "free";
    }');
}
if (!function_exists('tangible\\updater\\is_distribution_known')) {
  eval('namespace tangible\\updater;
    function is_distribution_known($plugin) {
      return in_array(get_option((($plugin->setting_prefix ?? $plugin->name) ?? "") . "_distribution"), ["free", "licensed"], true);
    }');
}

/**
 * Tangible Connect, wp-admin side: start → return (exchange + confirm) →
 * verify endpoint → token on update checks and consent sync → disconnect.
 * The platform is faked at the HTTP layer (pre_http_request).
 */
class Connect_TestCase extends \WP_UnitTestCase {

  const TOKEN = 'tgo_site_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
  const CODE  = 'tgo_code_BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

  private $requests = [];
  private $admin;

  function setUp(): void {
    parent::setUp();
    $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
    wp_set_current_user($this->admin);
  }

  function tearDown(): void {
    connect\clear();
    delete_option(connect\SITE_ID_OPTION);
    remove_all_filters('pre_http_request');
    $this->requests = [];
    parent::tearDown();
  }

  /** $routes: path suffix => [code, body]. Records url, args. */
  private function fake_platform($routes) {
    add_filter('pre_http_request', function ($pre, $args, $url) use ($routes) {
      $this->requests[] = [ 'url' => $url, 'args' => $args ];
      foreach ($routes as $suffix => [$code, $body]) {
        if (substr(strtok($url, '?'), -strlen($suffix)) === $suffix) {
          return [ 'response' => [ 'code' => $code ], 'body' => wp_json_encode($body), 'headers' => [] ];
        }
      }
      return new \WP_Error('http', 'no route ' . $url);
    }, 10, 3);
  }

  private function start() {
    $url = connect\start_flow($this->admin, admin_url('admin.php?page=x'), '');
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
    return [$url, $q];
  }

  function test_pkce_challenge_matches_rfc_7636() {
    $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
      connect\pkce_challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
  }

  function test_start_sends_a_top_level_authorize_url_bound_to_this_site_and_user() {
    [$url, $q] = $this->start();
    $this->assertStringStartsWith(connect\app_base() . '/connect/authorize?', $url);
    $this->assertSame(site_url(), $q['site_url']);
    $this->assertSame('S256', $q['code_challenge_method']);
    $this->assertSame(wp_parse_url(site_url(), PHP_URL_HOST), wp_parse_url($q['redirect_uri'], PHP_URL_HOST));
    $this->assertStringStartsWith(site_url('/wp-admin/admin-post.php'), $q['redirect_uri']);
    // The verifier never leaves the site; only its challenge does.
    $flow = get_transient(connect\flow_key($this->admin));
    $this->assertSame(connect\pkce_challenge($flow['verifier']), $q['code_challenge']);
    $this->assertStringNotContainsString($flow['verifier'], $url);
  }

  function test_return_refuses_a_wrong_state_and_another_user() {
    [, $q] = $this->start();
    $this->assertSame('error', connect\handle_return($this->admin, [ 'state' => 'nope', 'code' => self::CODE ])['status']);
    $other = self::factory()->user->create([ 'role' => 'administrator' ]);
    $this->assertSame('error', connect\handle_return($other, [ 'state' => $q['state'], 'code' => self::CODE ])['status']);
    $this->assertCount(0, $this->requests);
  }

  function test_a_full_return_exchanges_server_side_stores_the_token_and_confirms() {
    [, $q] = $this->start();
    $this->fake_platform([
      '/api/v1/connect/exchange' => [200, [ 'status' => 'pending', 'token' => self::TOKEN,
        'account' => [ 'name' => 'Acme', 'owner_email_masked' => 'o***@acme.example' ] ]],
      '/api/v1/connect/confirm'  => [200, [ 'status' => 'active' ]],
    ]);
    $result = connect\handle_return($this->admin, [ 'state' => $q['state'], 'code' => self::CODE ]);
    $this->assertSame('connected', $result['status']);
    $this->assertTrue(connect\is_connected());
    $this->assertSame('Acme', connect\get_state()['account_name']);

    [$exchange, $confirm] = $this->requests;
    $sent = json_decode($exchange['args']['body'], true);
    $this->assertSame(self::CODE, $sent['code']);
    $this->assertSame($q['redirect_uri'], $sent['redirect_uri']);
    $this->assertSame(connect\pkce_challenge($sent['code_verifier']), $q['code_challenge']);
    // Verified TLS, and the token only ever in a header.
    foreach ($this->requests as $r) {
      $this->assertNotSame(false, $r['args']['sslverify'] ?? true);
      $this->assertStringNotContainsString(self::TOKEN, $r['url']);
    }
    $this->assertSame(self::TOKEN, $confirm['args']['headers'][connect\HEADER]);
    // Single use: replaying the redirect does nothing.
    $this->assertSame('error', connect\handle_return($this->admin, [ 'state' => $q['state'], 'code' => self::CODE ])['status']);
  }

  function test_an_unverified_site_stays_pending_and_a_denial_stores_nothing() {
    [, $q] = $this->start();
    $this->fake_platform([
      '/api/v1/connect/exchange' => [200, [ 'status' => 'pending', 'token' => self::TOKEN ]],
      '/api/v1/connect/confirm'  => [200, [ 'status' => 'pending', 'error' => 'verification_failed', 'reason' => 'unreachable' ]],
    ]);
    $this->assertSame('pending', connect\handle_return($this->admin, [ 'state' => $q['state'], 'code' => self::CODE ])['status']);
    $this->assertFalse(connect\is_connected());
    $this->assertSame('', connect\get_active_token());

    connect\clear();
    [, $q2] = $this->start();
    $this->assertSame('denied', connect\handle_return($this->admin, [ 'state' => $q2['state'], 'error' => 'access_denied' ])['status']);
    $this->assertSame('', connect\get_token());
  }

  function test_verify_endpoint_answers_only_while_pending_with_the_hmac_proof() {
    connect\save_state([ 'token' => self::TOKEN, 'status' => 'pending' ]);
    $request = new \WP_REST_Request('POST', '/tangible/v1/connect/verify');
    $request->set_param('challenge', 'ChallengeChallenge42');
    $response = connect\rest_verify($request);
    $this->assertSame(200, $response->get_status());
    $this->assertSame(hash_hmac('sha256', 'ChallengeChallenge42', hash('sha256', self::TOKEN)), $response->get_data()['proof']);
    $this->assertStringNotContainsString(self::TOKEN, wp_json_encode($response->get_data()));

    connect\save_state([ 'token' => self::TOKEN, 'status' => 'active' ]);
    $this->assertSame(404, connect\rest_verify($request)->get_status());
    $request->set_param('challenge', 'x');
    $this->assertSame(400, connect\rest_verify($request)->get_status());
  }

  function test_the_verify_route_is_registered_publicly() {
    do_action('rest_api_init');
    $this->assertArrayHasKey('/tangible/v1/connect/verify', rest_get_server()->get_routes());
  }

  function test_update_checks_carry_the_header_only_when_active_keyless_and_to_the_api_host() {
    $plugin = \tangible\framework\register_plugin([ 'name' => 'freeplug', 'title' => 'F', 'cloud_id' => 'freeplug', 'setting_prefix' => 'freeplug' ]);
    $plugin->updater_url = connect\api_base() . '/api/edd';
    $legacy = \tangible\framework\register_plugin([ 'name' => 'legacyplug', 'title' => 'L', 'setting_prefix' => 'legacyplug' ]);
    $legacy->updater_url = 'https://updater.tangible.one';
    $this->assertArrayNotHasKey('headers', connect\with_token_header([], 'freeplug'));
    connect\save_state([ 'token' => self::TOKEN, 'status' => 'pending' ]);
    $this->assertArrayNotHasKey(connect\HEADER, connect\with_token_header([ 'headers' => [] ], 'freeplug')['headers']);
    connect\save_state([ 'token' => self::TOKEN, 'status' => 'active' ]);
    $opts = connect\with_token_header([ 'headers' => [ 'Accept' => 'application/json' ] ], 'freeplug');
    $this->assertSame(self::TOKEN, $opts['headers'][connect\HEADER]);
    $this->assertSame('application/json', $opts['headers']['Accept']);
    // Never to another update server.
    $this->assertArrayNotHasKey('headers', connect\with_token_header([], 'legacyplug'));
  }

  function test_metadata_results_cache_the_block_and_a_revoke_drops_the_token() {
    connect\save_state([ 'token' => self::TOKEN, 'status' => 'active' ]);
    $block = [ 'accountId' => 'a1', 'accountName' => 'Acme', 'ask' => [ 'telemetry_extended' => 'skip', 'marketing' => 'ask' ] ];
    connect\read_metadata_result('freeplug', [ 'body' => wp_json_encode([ 'connection' => 'active', 'onboarding' => $block ]) ]);
    $this->assertSame('skip', onboarding\build_facts('freeplug')->ask['telemetry_extended']);

    connect\read_metadata_result('freeplug', [ 'body' => wp_json_encode([ 'connection' => 'revoked' ]) ]);
    $this->assertSame('', connect\get_token());
    delete_option('tangible_onboarding_facts_cache__freeplug');
  }

  function test_a_staging_cloud_url_points_connect_at_staging() {
    $origin = connect\url_origin('https://api.staging.tangible.one/api/edd');
    $this->assertSame('https://api.staging.tangible.one', $origin);
    $this->assertSame('https://staging.tangible.one', connect\app_for_api_origin($origin));
    $this->assertSame('https://dev-site.tangible.one', connect\app_for_api_origin('https://dev-site.tangible.one'));
    $this->assertSame('', connect\url_origin('not a url'));
  }

  function test_disconnect_revokes_server_side_then_clears_even_if_that_fails() {
    connect\save_state([ 'token' => self::TOKEN, 'status' => 'active' ]);
    $this->fake_platform([]);   // every call fails
    connect\disconnect();
    $this->assertSame('', connect\get_token());
    $this->assertStringEndsWith('/api/v1/connect/disconnect', $this->requests[0]['url']);
    $this->assertSame(self::TOKEN, $this->requests[0]['args']['headers'][connect\HEADER]);
  }
}

/**
 * The `connect` onboarder step and connected consent sync.
 */
class Connect_Step_TestCase extends \WP_UnitTestCase {

  private $plugin;
  private $requests = [];

  function setUp(): void {
    parent::setUp();
    $this->plugin = \tangible\framework\register_plugin([
      'name' => 'freeconnect', 'title' => 'Free Connect', 'cloud_id' => 'freeconnect',
      'setting_prefix' => 'freeconnect',
    ]);
    $this->plugin->activation_url = 'https://api.example.test/api/edd';
    onboarding\register_wizard($this->plugin);
    update_option('freeconnect_distribution', 'free', false);
  }

  function tearDown(): void {
    connect\clear();
    delete_option('freeconnect_distribution');
    delete_option('tangible_onboarding_state__site');
    delete_option('tangible_onboarding_state__freeconnect');
    delete_option(onboarding\CONSENT_OUTBOX);
    remove_all_filters('pre_http_request');
    parent::tearDown();
  }

  private function ids() {
    return array_column(onboarding\resolve_plan('freeconnect', onboarding\build_facts('freeconnect'))['steps'], 'id');
  }

  function test_free_and_not_connected_shows_connect_before_consent() {
    $ids = $this->ids();
    $this->assertContains(connect\STEP_ID, $ids);
    $this->assertLessThan(array_search('consent', $ids, true), array_search(connect\STEP_ID, $ids, true));
  }

  function test_connected_or_licensed_hides_it() {
    connect\save_state([ 'token' => Connect_TestCase::TOKEN, 'status' => 'active' ]);
    $this->assertNotContains(connect\STEP_ID, $this->ids());
    connect\clear();
    update_option('freeconnect_distribution', 'licensed', false);
    $this->assertNotContains(connect\STEP_ID, $this->ids());
  }

  function test_continue_starts_the_grant_and_records_nothing_yet() {
    wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    $this->assertNull(onboarding\handle_step_submission('freeconnect', [ 'step' => connect\STEP_ID, 'do' => 'continue' ]));
    $this->assertStringStartsWith(connect\app_base() . '/connect/authorize?', onboarding\pending_redirect());
    $this->assertContains(connect\STEP_ID, $this->ids());
    // tangible.one is an allowed redirect host only inside the step submission.
    $this->assertSame('nope', wp_validate_redirect(onboarding\pending_redirect(), 'nope'));
  }

  function test_connected_consent_sync_sends_the_token_header_and_no_key() {
    onboarding\record_consent_answer('telemetry_extended', 'granted', 'wording v1');
    connect\save_state([ 'token' => Connect_TestCase::TOKEN, 'status' => 'active' ]);
    add_filter('pre_http_request', function ($pre, $args, $url) {
      $this->requests[] = $args;
      return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode([ 'success' => true, 'synced' => 1 ]) ];
    }, 10, 3);
    onboarding\attempt_consent_sync($this->plugin);
    $this->assertCount(1, $this->requests);
    $this->assertSame(Connect_TestCase::TOKEN, $this->requests[0]['headers'][connect\HEADER]);
    $this->assertSame('', (string) $this->requests[0]['body']['license']);
    $this->assertNotFalse($this->requests[0]['sslverify'] ?? true);   // WP's verified default
    $this->assertTrue(get_option(onboarding\CONSENT_OUTBOX)['telemetry_extended']['synced']);
  }

  private function consent_needed() {
    // Marketing answered elsewhere, so `needed` is the telemetry question alone.
    $facts = onboarding\build_facts('freeconnect');
    $facts->ask['marketing'] = 'skip';
    foreach (onboarding\resolve_plan('freeconnect', $facts)['steps'] as $s) {
      if ($s['id'] === 'consent') return ($s['needed'])($facts);
    }
    return false;
  }

  function test_only_an_unconnected_free_site_is_asked_about_usage_data() {
    // ADR-011 §4: licensed and connected accounts accepted the Terms.
    $this->assertTrue($this->consent_needed());
    connect\save_state([ 'token' => Connect_TestCase::TOKEN, 'status' => 'active' ]);
    $this->assertFalse($this->consent_needed());
    connect\clear();
    update_option('freeconnect_distribution', 'licensed', false);
    $this->assertFalse($this->consent_needed());
  }

  function test_the_site_setting_is_one_answer_for_the_site() {
    add_filter('pre_http_request', function () { return new \WP_Error('offline', 'no network in tests'); });
    onboarding\set_site_telemetry(false);
    $this->assertTrue(onboarding\site_telemetry_refused());
    $entry = get_option(onboarding\CONSENT_OUTBOX)['telemetry_extended'];
    $this->assertSame('declined', $entry['answer']);
    $this->assertSame(onboarding\SITE_TELEMETRY_OFF_TEXT, $entry['consent_text']);
    // Answered, so the wizard never asks it again.
    $this->assertFalse($this->consent_needed());
    onboarding\set_site_telemetry(true);
    $this->assertFalse(onboarding\site_telemetry_refused());
  }

  function test_coexists_with_a_plugin_step_named_connect() {
    // SearchSync's backend step is `connect`; the framework's must not collide.
    add_filter('tangible_onboarding_steps', function ($steps, $facts, $name = '') {
      if ($name === 'freeconnect') $steps[] = [ 'id' => 'connect', 'label' => 'Backend', 'weight' => 50 ];
      return $steps;
    }, 10, 3);
    $ids = $this->ids();
    $this->assertContains('connect', $ids);
    $this->assertContains(connect\STEP_ID, $ids);
  }

  function test_pending_shows_why_and_check_again_stays_open_until_verified() {
    wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    connect\save_state([ 'token' => Connect_TestCase::TOKEN, 'status' => 'pending', 'account_name' => 'Acme' ]);
    $step = null;
    foreach (onboarding\resolve_plan('freeconnect', onboarding\build_facts('freeconnect'))['steps'] as $s) {
      if ($s['id'] === connect\STEP_ID) $step = $s;
    }
    ob_start(); ($step['render'])($this->plugin); $html = ob_get_clean();
    $this->assertStringContainsString('waiting for tangible.one to verify', $html);
    $this->assertStringContainsString('Check again', $html);

    add_filter('pre_http_request', function () {
      return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode([ 'status' => 'pending' ]) ];
    });
    $_POST['connect_check'] = '1';
    $this->assertInstanceOf(\WP_Error::class, ($step['handle'])($this->plugin));
    remove_all_filters('pre_http_request');
    add_filter('pre_http_request', function () {
      return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode([ 'status' => 'active' ]) ];
    });
    $this->assertTrue(($step['handle'])($this->plugin));
    $this->assertTrue(connect\is_connected());
    unset($_POST['connect_check']);
  }

  function test_a_revoked_token_on_consent_sync_falls_back_to_anonymous() {
    onboarding\record_consent_answer('telemetry_extended', 'granted', 'wording v1');
    connect\save_state([ 'token' => Connect_TestCase::TOKEN, 'status' => 'active' ]);
    add_filter('pre_http_request', function () {
      return [ 'response' => [ 'code' => 401 ], 'body' => wp_json_encode([ 'success' => false, 'error' => 'connection_revoked' ]) ];
    });
    onboarding\attempt_consent_sync($this->plugin);
    $this->assertSame('', connect\get_token());
    $this->assertFalse(get_option(onboarding\CONSENT_OUTBOX)['telemetry_extended']['synced']);
  }
}
