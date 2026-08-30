<?php
namespace tests\framework;

use tangible\onboarding;

/**
 * Wizard shell — the submission logic, without HTTP.
 */
class Wizard_TestCase extends \WP_UnitTestCase {

  static $filter = 'tangible_onboarding_steps';

  function tearDown(): void {
    remove_all_filters(self::$filter);
    remove_all_filters('tangible_onboarding_facts');
    delete_option('tangible_onboarding_state__example');
    parent::tearDown();
  }

  private function register($steps) {
    add_filter(self::$filter, function ($all) use ($steps) {
      return array_merge($all, $steps);
    });
  }

  function test_continue_runs_handle_before_marking_done() {
    $handled = [];
    $this->register([[ 'id' => 'engine',
      'handle' => function () use (&$handled) { $handled[] = 'engine'; return true; } ]]);

    $recorded = onboarding\handle_step_submission('example', [ 'step' => 'engine', 'do' => 'continue' ]);

    $this->assertSame('engine', $recorded);
    $this->assertSame(['engine'], $handled);
    $this->assertCount(0, onboarding\resolve_plan('example', onboarding\build_facts('example'))['steps']);
  }

  function test_handle_returning_false_keeps_the_step_open() {
    // Validation failure is not completion: nothing recorded, step stays.
    $this->register([[ 'id' => 'engine', 'handle' => '__return_false' ]]);
    $recorded = onboarding\handle_step_submission('example', [ 'step' => 'engine', 'do' => 'continue' ]);
    $this->assertNull($recorded);
    $this->assertCount(1, onboarding\resolve_plan('example', (object) [])['steps']);
  }

  function test_only_the_current_step_accepts_a_submission() {
    // A stale tab posting a later (or earlier) step must not run handlers.
    $this->register([[ 'id' => 'first' ], [ 'id' => 'second' ]]);
    $this->assertNull(onboarding\handle_step_submission('example', [ 'step' => 'second', 'do' => 'continue' ]));
    $this->assertSame('first', onboarding\handle_step_submission('example', [ 'step' => 'first', 'do' => 'continue' ]));
    $this->assertSame('second', onboarding\handle_step_submission('example', [ 'step' => 'second', 'do' => 'continue' ]));
  }

  function test_skip_respects_skippable_false() {
    $this->register([[ 'id' => 'consent', 'skippable' => false ]]);
    $this->assertNull(onboarding\handle_step_submission('example', [ 'step' => 'consent', 'do' => 'skip' ]));
    $this->assertCount(1, onboarding\resolve_plan('example', (object) [])['steps']);
  }

  function test_an_object_shaped_facts_cache_still_applies() {
    // Regression: the cache used to be written with a shallow (array) cast,
    // leaving `ask` a stdClass — which the reader rejected, silently
    // re-asking questions the server said were on file. Both shapes must
    // apply, and neither may fatal.
    foreach ([
      json_decode('{"ask":{"telemetry_extended":"skip","marketing":"skip"}}', true),
      json_decode('{"ask":{"telemetry_extended":"skip","marketing":"skip"}}'),
    ] as $data) {
      update_option('tangible_onboarding_facts_cache__example', [ 'at' => 1, 'data' => $data ], false);
      $facts = onboarding\build_facts('example');
      $this->assertSame('skip', $facts->ask['telemetry_extended']);
      $this->assertSame('skip', $facts->ask['marketing']);
    }
    delete_option('tangible_onboarding_facts_cache__example');
  }

  function test_facts_default_to_asking_and_the_filter_overrides() {
    $facts = onboarding\build_facts('example');
    $this->assertFalse($facts->licence_active);
    $this->assertSame('ask', $facts->ask['telemetry_extended']);

    add_filter('tangible_onboarding_facts', function ($f) {
      $f->ask['telemetry_extended'] = 'skip';
      return $f;
    });
    $this->assertSame('skip', onboarding\build_facts('example')->ask['telemetry_extended']);
  }
}

/**
 * The shell's own steps + the test path — needs a real registered wizard.
 */
class Core_Steps_TestCase extends \WP_UnitTestCase {

  private $plugin;

  function setUp(): void {
    parent::setUp();
    $this->plugin = \tangible\framework\register_plugin([
      'name' => 'coretest', 'title' => 'Core Test', 'version' => '1.0.0',
      // Both halves of a licensed build: cloud_id is the plugin's own
      // declaration, activation_url is what the updater's register_plugin()
      // gives it. A fixture with only the first models a plugin that could
      // never activate — see test_licence_is_absent_without_an_activation_url.
      'cloud_id' => 'coretest',
      'activation_url' => 'https://cloud.tangible.one/api/edd',
    ]);
    onboarding\register_wizard($this->plugin);
  }

  function tearDown(): void {
    delete_option('tangible_onboarding_state__coretest');
    delete_option(onboarding\STEWARD_OPTION);
    delete_option(onboarding\CONSENT_OUTBOX);
    remove_all_filters('tangible_onboarding_facts');
    parent::tearDown();
  }

  function test_a_registered_wizard_gets_licence_and_consent() {
    $plan = onboarding\resolve_plan('coretest', onboarding\build_facts('coretest'));
    $ids = array_column($plan['steps'], 'id');
    $this->assertContains('licence', $ids);
    $this->assertContains('consent', $ids);
    // Licence first, and never skippable.
    $this->assertSame('licence', $plan['current']);
    $this->assertFalse($plan['steps'][0]['skippable']);
  }

  function test_an_unregistered_name_gets_no_core_steps() {
    $plan = onboarding\resolve_plan('someone-else', (object) []);
    $this->assertSame([], array_column($plan['steps'], 'id'));
  }

  function test_licence_is_absent_on_free_builds() {
    $free = \tangible\framework\register_plugin([ 'name' => 'freetest', 'title' => 'Free' ]);
    onboarding\register_wizard($free);
    $plan = onboarding\resolve_plan('freetest', onboarding\build_facts('freetest'));
    $this->assertNotContains('licence', array_column($plan['steps'], 'id'));
    delete_option('tangible_onboarding_state__freetest');
  }

  /**
   * The updater's functions can be on the site because ANOTHER plugin shipped
   * them — that does not make THIS plugin activatable. activation_url is only
   * defaulted inside the updater's own register_plugin(), so a plugin that
   * never registered with it would post its key to a null URL and take the
   * request down. Offering the step at all is the bug.
   */
  function test_licence_is_absent_without_an_activation_url() {
    $unregistered = \tangible\framework\register_plugin([
      'name' => 'noactivation', 'title' => 'No Activation', 'cloud_id' => 'noactivation',
    ]);
    onboarding\register_wizard($unregistered);
    $plan = onboarding\resolve_plan('noactivation', onboarding\build_facts('noactivation'));
    $this->assertNotContains('licence', array_column($plan['steps'], 'id'));
    delete_option('tangible_onboarding_state__noactivation');
  }

  function test_consent_refuses_half_an_answer() {
    // Answer only one of two asks: nothing may be recorded — half-consent
    // recorded is worse than none.
    add_filter('tangible_onboarding_facts', function ($f) {
      $f->licence_active = true;   // clear the licence step out of the way
      return $f;
    });
    // Step fields travel in $_POST — that is the render/handle contract.
    $_POST = [ 'telemetry_extended' => 'granted' ];
    $recorded = onboarding\handle_step_submission('coretest', [
      'step' => 'consent', 'do' => 'continue',
    ]);
    $_POST = [];
    $this->assertNull($recorded);
    $this->assertSame([], get_option(onboarding\CONSENT_OUTBOX, []));
  }

  function test_consent_records_both_and_silences_the_ask() {
    add_filter('tangible_onboarding_facts', function ($f) {
      $f->licence_active = true;
      return $f;
    });
    $_POST = [ 'telemetry_extended' => 'granted', 'marketing' => 'declined' ];
    $recorded = onboarding\handle_step_submission('coretest', [
      'step' => 'consent', 'do' => 'continue',
    ]);
    $_POST = [];
    $this->assertSame('consent', $recorded);

    $outbox = get_option(onboarding\CONSENT_OUTBOX);
    $this->assertSame('granted', $outbox['telemetry_extended']['answer']);
    $this->assertSame('declined', $outbox['marketing']['answer']);
    // The exact wording shown rides with the answer — provable later.
    $this->assertNotEmpty($outbox['marketing']['consent_text']);
    $this->assertFalse($outbox['telemetry_extended']['synced']);

    // A declined marketing ask is an ANSWER: the wizard never re-asks it.
    $plan = onboarding\resolve_plan('coretest', onboarding\build_facts('coretest'));
    $this->assertNotContains('consent', array_column($plan['steps'], 'id'));
    $rail = array_column($plan['rail'], 'state', 'id');
    $this->assertSame('skipped', $rail['consent']);
  }

  function test_a_handler_wp_error_keeps_the_step_open_and_stores_the_reason() {
    // The shell's steward step (weight 40) must not outrank the fixture's
    // creds step (50): answer it up front.
    onboarding\set_steward('', 'team');
    add_filter('tangible_onboarding_facts', function ($f) {
      $f->licence_active = true;
      $f->ask = [ 'telemetry_extended' => 'skip', 'marketing' => 'skip' ];
      return $f;
    });
    add_filter('tangible_onboarding_steps', function ($steps, $facts, $name) {
      if ($name !== 'coretest') return $steps;
      $steps[] = [ 'id' => 'creds', 'weight' => 50, 'submit_label' => 'Test connection',
        'handle' => function () { return new \WP_Error('x', 'That key has no write access.'); } ];
      return $steps;
    }, 10, 3);

    wp_set_current_user(self::factory()->user->create());
    $recorded = onboarding\handle_step_submission('coretest', [ 'step' => 'creds', 'do' => 'continue' ]);

    $this->assertNull($recorded);
    $this->assertSame('That key has no write access.',
      get_transient(onboarding\step_error_key('coretest')));
    // And nothing was marked: the step is still the plan's current.
    $plan = onboarding\resolve_plan('coretest', onboarding\build_facts('coretest'));
    $this->assertSame('creds', $plan['current']);
    remove_all_filters('tangible_onboarding_steps');
  }
}

/*
 * The updater module is not part of the framework's wp-env, so the sync tests
 * stand in its two key functions — same storage contract the real ones have
 * (the licence key as a subfield of the framework settings array).
 */
if (!function_exists('tangible\\updater\\get_license_key_setting_field')) {
  eval('namespace tangible\\updater;
    function get_license_key_setting_field() { return "license_key"; }
    function get_license_key($plugin) {
      $settings = \\tangible\\framework\\get_plugin_settings($plugin);
      return $settings["license_key"] ?? "";
    }');
}

/**
 * The consent outbox's delivery client.
 */
class Consent_Sync_TestCase extends \WP_UnitTestCase {

  private $plugin;
  private $requests = [];

  function setUp(): void {
    parent::setUp();
    $this->plugin = \tangible\framework\register_plugin([
      'name' => 'synctest', 'title' => 'Sync Test', 'cloud_id' => 'synctest',
      'setting_prefix' => 'synctest',
    ]);
    $this->plugin->activation_url = 'https://api.example.test/api/edd';
    // A key on file — the sync precondition.
    \tangible\framework\update_plugin_settings($this->plugin, [
      \tangible\updater\get_license_key_setting_field() => 'TGBL-TEST-KEY',
    ]);
    onboarding\record_consent_answer('telemetry_extended', 'granted', 'wording v1');
  }

  function tearDown(): void {
    delete_option(onboarding\CONSENT_OUTBOX);
    delete_option('synctest_settings');
    remove_all_filters('pre_http_request');
    $this->requests = [];
    parent::tearDown();
  }

  private function fake_server($response_body) {
    add_filter('pre_http_request', function ($pre, $args, $url) use ($response_body) {
      $this->requests[] = [ 'url' => $url, 'body' => $args['body'] ];
      if ($response_body instanceof \WP_Error) return $response_body;
      return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode($response_body) ];
    }, 10, 3);
  }

  function test_a_successful_delivery_marks_the_answer_synced() {
    $this->fake_server([ 'success' => true, 'synced' => 1 ]);
    onboarding\attempt_consent_sync($this->plugin);

    $this->assertCount(1, $this->requests);
    $body = $this->requests[0]['body'];
    $this->assertSame('tangible_sync_consent', $body['edd_action']);
    $this->assertSame('TGBL-TEST-KEY', $body['license']);
    $answers = json_decode($body['answers'], true);
    $this->assertSame('telemetry_extended', $answers[0]['key']);
    $this->assertSame('wording v1', $answers[0]['consentText']);

    $outbox = get_option(onboarding\CONSENT_OUTBOX);
    $this->assertTrue($outbox['telemetry_extended']['synced']);

    // Nothing left to deliver: the next attempt does not even POST.
    onboarding\attempt_consent_sync($this->plugin);
    $this->assertCount(1, $this->requests);
  }

  function test_a_failed_delivery_leaves_the_answer_for_the_next_trigger() {
    $this->fake_server(new \WP_Error('http', 'unreachable'));
    onboarding\attempt_consent_sync($this->plugin);
    $this->assertFalse(get_option(onboarding\CONSENT_OUTBOX)['telemetry_extended']['synced'] ?? true);

    // Server said no (e.g. licence revoked between answer and delivery):
    remove_all_filters('pre_http_request');
    $this->fake_server([ 'success' => false, 'error' => 'license_invalid' ]);
    onboarding\attempt_consent_sync($this->plugin);
    $this->assertFalse(get_option(onboarding\CONSENT_OUTBOX)['telemetry_extended']['synced'] ?? true);
  }

  function test_no_key_means_no_post_the_outbox_just_waits() {
    \tangible\framework\update_plugin_settings($this->plugin, [
      \tangible\updater\get_license_key_setting_field() => '',
    ]);
    $this->fake_server([ 'success' => true ]);
    onboarding\attempt_consent_sync($this->plugin);
    $this->assertCount(0, $this->requests);
  }
}

/**
 * The steward map — local, per-account.
 */
class Steward_TestCase extends \WP_UnitTestCase {

  function setUp(): void {
    parent::setUp();
    $plugin = \tangible\framework\register_plugin([ 'name' => 'stewtest', 'title' => 'Stew Test' ]);
    onboarding\register_wizard($plugin);
  }

  function tearDown(): void {
    delete_option(onboarding\STEWARD_OPTION);
    delete_option('tangible_onboarding_facts_cache__stewtest');
    remove_all_filters('tangible_onboarding_facts');
    parent::tearDown();
  }

  private function plan($account_id = '') {
    add_filter('tangible_onboarding_facts', function ($f) use ($account_id) {
      $f->account_id = $account_id;
      $f->ask = [ 'telemetry_extended' => 'skip', 'marketing' => 'skip' ];
      return $f;
    });
    $plan = onboarding\resolve_plan('stewtest', onboarding\build_facts('stewtest'));
    remove_all_filters('tangible_onboarding_facts');
    return $plan;
  }

  function test_a_second_account_on_the_same_site_is_asked_again() {
    $this->assertContains('steward', array_column($this->plan('acct_A')['steps'], 'id'));
    onboarding\set_steward('acct_A', 'client');
    // acct_A answered; the same site under acct_B is a NEW question…
    $this->assertNotContains('steward', array_column($this->plan('acct_A')['steps'], 'id'));
    $this->assertContains('steward', array_column($this->plan('acct_B')['steps'], 'id'));
    // …and each answer is its own record.
    onboarding\set_steward('acct_B', 'team');
    $this->assertSame('client', onboarding\get_steward('acct_A'));
    $this->assertSame('team', onboarding\get_steward('acct_B'));
  }

  function test_the_legacy_bare_string_reads_as_the_anonymous_answer() {
    update_option(onboarding\STEWARD_OPTION, 'team');
    $this->assertSame('team', onboarding\get_steward(''));
    // The anonymous answer covers accounts too — whoever set up the first
    // plugin answered for the site as they knew it.
    $this->assertSame('team', onboarding\get_steward('acct_A'));
    // Writing upgrades the shape without losing the legacy answer.
    onboarding\set_steward('acct_A', 'client');
    $this->assertSame('team', onboarding\get_steward(''));
    $this->assertSame('client', onboarding\get_steward('acct_A'));
  }

  function test_hub_client_managed_requires_every_answer_to_say_client() {
    onboarding\set_steward('acct_A', 'client');
    $this->assertTrue(\tangible\hub\is_client_managed_site());
    onboarding\set_steward('acct_B', 'team');
    $this->assertFalse(\tangible\hub\is_client_managed_site());
  }
}
