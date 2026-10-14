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

  function test_approval_and_exchange_use_the_same_platform() {
    // Approval happens on app_base(), the code is exchanged on api_base(): one
    // platform, whatever constant set the API host.
    $this->assertSame(connect\app_for_api_origin(connect\url_origin(connect\api_base())), connect\app_base());
    $this->assertSame('https://staging.tangible.one', connect\app_for_api_origin('https://api.staging.tangible.one'));
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
    delete_option(onboarding\STEWARD_OPTION);
    remove_all_filters('pre_http_request');
    $_POST = [];
    parent::tearDown();
  }

  private function ids() {
    return array_column(onboarding\resolve_plan('freeconnect', onboarding\build_facts('freeconnect'))['steps'], 'id');
  }

  private function rail() {
    return array_column(onboarding\resolve_plan('freeconnect', onboarding\build_facts('freeconnect'))['rail'], 'id');
  }

  private function welcome_html() {
    ob_start(); onboarding\render_welcome($this->plugin); return ob_get_clean();
  }

  function test_anonymous_free_is_welcome_usage_data_done() {
    // Figma "[Core] Free 01": Welcome · Usage data · Done.
    $this->assertSame(['welcome', 'usage', 'done'], $this->ids());
    $this->assertSame(['welcome', 'usage', 'done'], $this->rail());
    $this->assertStringContainsString('Connect to tangible.one', $this->welcome_html());
  }

  function test_connected_free_is_welcome_then_done() {
    // Figma "[Core] Free 01b": the usage step leaves the rail entirely.
    connect\save_state([ 'token' => Connect_TestCase::TOKEN, 'status' => 'active', 'account_name' => 'Oakhill Learning' ]);
    $this->assertSame(['welcome', 'done'], $this->ids());
    $this->assertSame(['welcome', 'done'], $this->rail());
    $html = $this->welcome_html();
    $this->assertStringContainsString('Connected as Oakhill Learning', $html);
    $this->assertStringContainsString('answer:disconnect', $html);
    $this->assertStringNotContainsString('answer:connect"', $html);
  }

  function test_connect_starts_the_grant_and_records_nothing_yet() {
    wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    $_POST = [ 'steward_client' => '1' ];
    $this->assertNull(onboarding\handle_step_submission('freeconnect', [ 'step' => 'welcome', 'do' => 'answer:connect' ]));
    $_POST = [];
    $this->assertStringStartsWith(connect\app_base() . '/connect/authorize?', onboarding\pending_redirect());
    $this->assertSame('welcome', $this->ids()[0]);
    // The checkbox ticked before leaving is not lost on the round trip.
    $this->assertSame('client', onboarding\get_steward());
    // tangible.one is an allowed redirect host only inside the step submission.
    $this->assertSame('nope', wp_validate_redirect(onboarding\pending_redirect(), 'nope'));
    delete_option(onboarding\STEWARD_OPTION);
  }

  function test_disconnect_from_welcome_brings_the_usage_question_back() {
    wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    connect\save_state([ 'token' => Connect_TestCase::TOKEN, 'status' => 'active' ]);
    add_filter('pre_http_request', function () { return new \WP_Error('offline', 'no network in tests'); });
    $this->assertNull(onboarding\handle_step_submission('freeconnect', [ 'step' => 'welcome', 'do' => 'answer:disconnect' ]));
    $_POST = [];
    $this->assertSame('', connect\get_token());
    $this->assertSame(['welcome', 'usage', 'done'], $this->ids());
  }

  function test_usage_data_records_either_answer_and_stays_on_the_rail_as_done() {
    add_filter('pre_http_request', function () { return new \WP_Error('offline', 'no network in tests'); });
    onboarding\mark('freeconnect', 'welcome', 'done');
    // No answer is not an answer.
    $this->assertNull(onboarding\handle_step_submission('freeconnect', [ 'step' => 'usage', 'do' => 'continue' ]));

    foreach (['declined', 'granted'] as $answer) {
      $this->assertSame('usage', onboarding\handle_step_submission('freeconnect', [ 'step' => 'usage', 'do' => 'answer:' . $answer ]));
      $entry = get_option(onboarding\CONSENT_OUTBOX)['telemetry_extended'];
      $this->assertSame($answer, $entry['answer']);
      // The exact wording shown, rows included — provable later.
      $this->assertStringContainsString('Active plugins', $entry['consent_text']);
      $plan = onboarding\resolve_plan('freeconnect', onboarding\build_facts('freeconnect'));
      $this->assertSame('done', $plan['current']);
      $this->assertSame('done', array_column($plan['rail'], 'state', 'id')['usage']);
      // Back reopens the question.
      $this->assertSame('usage', onboarding\handle_step_submission('freeconnect', [ 'step' => 'done', 'do' => 'back' ]));
      $this->assertArrayNotHasKey('telemetry_extended', get_option(onboarding\CONSENT_OUTBOX));
    }
    unset($_POST['answer']);
  }

  function test_the_done_band_asks_an_anonymous_site_for_an_address() {
    add_filter('pre_http_request', function () { return new \WP_Error('offline', 'no network in tests'); });
    onboarding\mark('freeconnect', 'welcome', 'done');
    onboarding\record_consent_answer('telemetry_extended', 'declined', 'wording v1');
    $plan = onboarding\resolve_plan('freeconnect', onboarding\build_facts('freeconnect'));
    $this->assertSame('done', $plan['current']);
    ob_start(); ($plan['steps'][0]['render'])($this->plugin); $html = ob_get_clean();
    $this->assertStringContainsString('name="marketing_email"', $html);

    $_POST = [ 'marketing_email' => 'not an address' ];
    $this->assertNull(onboarding\handle_step_submission('freeconnect', [ 'step' => 'done', 'do' => 'continue' ]));
    $this->assertArrayNotHasKey('marketing', get_option(onboarding\CONSENT_OUTBOX));

    $_POST = [ 'marketing_email' => 'owner@example.test' ];
    $this->assertSame('done', onboarding\handle_step_submission('freeconnect', [ 'step' => 'done', 'do' => 'continue' ]));
    $_POST = [];
    $entry = get_option(onboarding\CONSENT_OUTBOX)['marketing'];
    $this->assertSame([ 'granted', 'owner@example.test' ], [ $entry['answer'], $entry['email'] ]);
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
      if ($s['id'] === 'usage') return ($s['needed'])($facts);
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
    $this->assertContains('welcome', $ids);
  }

  function test_pending_shows_why_and_check_again_stays_on_welcome_until_verified() {
    wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    connect\save_state([ 'token' => Connect_TestCase::TOKEN, 'status' => 'pending', 'account_name' => 'Acme' ]);
    $html = $this->welcome_html();
    $this->assertStringContainsString('Waiting for tangible.one to verify', $html);
    $this->assertStringContainsString('answer:connect_check', $html);

    add_filter('pre_http_request', function () {
      return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode([ 'status' => 'pending' ]) ];
    });
    $this->assertNull(onboarding\handle_step_submission('freeconnect', [ 'step' => 'welcome', 'do' => 'answer:connect_check' ]));
    $this->assertStringStartsWith('Still waiting', (string) get_transient(onboarding\step_error_key('freeconnect')));
    remove_all_filters('pre_http_request');
    add_filter('pre_http_request', function () {
      return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode([ 'status' => 'active' ]) ];
    });
    $this->assertNull(onboarding\handle_step_submission('freeconnect', [ 'step' => 'welcome', 'do' => 'answer:connect_check' ]));
    $this->assertTrue(connect\is_connected());
    // Still on Welcome, now showing the connected box.
    $this->assertSame('welcome', $this->ids()[0]);
    $this->assertStringContainsString('Connected as Acme', $this->welcome_html());
    unset($_POST['answer']);
  }

  function test_get_started_with_a_pending_connection_moves_on() {
    connect\save_state([ 'token' => Connect_TestCase::TOKEN, 'status' => 'pending' ]);
    $_POST = [];
    $this->assertSame('welcome', onboarding\handle_step_submission('freeconnect', [ 'step' => 'welcome', 'do' => 'continue' ]));
    $this->assertSame(Connect_TestCase::TOKEN, connect\get_token());
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
