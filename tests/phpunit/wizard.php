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

  function test_an_answer_button_is_a_continue_carrying_its_answer() {
    // Footer answer buttons post do=answer:<value>; the handler reads it.
    $seen = [];
    $this->register([[ 'id' => 'ask', 'actions' => [ 'no' => [ 'label' => 'No' ], 'yes' => [ 'label' => 'Yes' ] ],
      'handle' => function () use (&$seen) { $seen[] = $_POST['answer'] ?? null; return true; } ]]);
    $this->assertSame('ask', onboarding\handle_step_submission('example', [ 'step' => 'ask', 'do' => 'answer:yes' ]));
    $this->assertSame(['yes'], $seen);
    unset($_POST['answer']);
    // Nothing after the colon is not an answer.
    $this->assertNull(onboarding\handle_step_submission('example', [ 'step' => 'ask', 'do' => 'answer:' ]));
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
      // The consent handler reads the key through the plugin's settings.
      'setting_prefix' => 'coretest',
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

  function test_a_paid_plugin_gets_welcome_then_done() {
    // Figma "[Core] Pro 01": the licence lives in Welcome. A paid site is
    // never asked about usage data (ADR-011), and the step leaves the rail
    // rather than sitting there as "skipped".
    $plan = onboarding\resolve_plan('coretest', onboarding\build_facts('coretest'));
    $this->assertSame(['welcome', 'done'], array_column($plan['steps'], 'id'));
    $this->assertSame(['welcome', 'done'], array_column($plan['rail'], 'id'));
    $this->assertSame('welcome', $plan['current']);
    $this->assertFalse($plan['steps'][0]['skippable']);
    $this->assertSame(1, onboarding\steps_left('coretest'));
  }

  function test_welcome_refuses_get_started_without_a_licence() {
    wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    $_POST = [];
    $this->assertNull(onboarding\handle_step_submission('coretest', [ 'step' => 'welcome', 'do' => 'continue' ]));
    $_POST = [];
    $this->assertStringContainsString('license key', (string) get_transient(onboarding\step_error_key('coretest')));
    $this->assertSame('welcome', onboarding\resolve_plan('coretest', onboarding\build_facts('coretest'))['current']);
  }

  function test_welcome_shows_the_licence_field_only_when_it_can_activate() {
    ob_start(); onboarding\render_welcome($this->plugin); $html = ob_get_clean();
    $this->assertStringContainsString('name="license_key"', $html);
    $this->assertStringContainsString('Your license key', $html);
    $this->assertStringContainsString('I am installing this for a client', $html);
  }

  function test_an_unregistered_name_gets_no_core_steps() {
    $plan = onboarding\resolve_plan('someone-else', (object) []);
    $this->assertSame([], array_column($plan['steps'], 'id'));
  }

  function test_licence_is_absent_on_free_builds() {
    $free = \tangible\framework\register_plugin([ 'name' => 'freetest', 'title' => 'Free' ]);
    onboarding\register_wizard($free);
    $this->assertFalse(onboarding\licence_applies($free));
    ob_start(); onboarding\render_welcome($free); $html = ob_get_clean();
    $this->assertStringNotContainsString('name="license_key"', $html);
    // Nothing to activate, so Get started needs nothing but the checkbox.
    $_POST = [];
    $this->assertSame('welcome', onboarding\handle_step_submission('freetest', [ 'step' => 'welcome', 'do' => 'continue' ]));
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
    $this->assertFalse(onboarding\licence_applies($unregistered));
    ob_start(); onboarding\render_welcome($unregistered); $html = ob_get_clean();
    $this->assertStringNotContainsString('name="license_key"', $html);
    delete_option('tangible_onboarding_state__noactivation');
  }

  function test_a_finished_wizard_from_before_welcome_is_not_reopened() {
    // A site that finished setup under the old rail has a Done record and no
    // Welcome record; it must not turn into "1 step left".
    update_option('tangible_onboarding_state__coretest', [ 'done' => [ 'status' => 'done', 'at' => 1 ] ]);
    $plan = onboarding\resolve_plan('coretest', onboarding\build_facts('coretest'));
    $this->assertSame([], $plan['steps']);
    $this->assertSame(0, onboarding\steps_left('coretest'));
  }

  function test_the_done_band_records_the_account_email_opt_in_for_a_keyed_site() {
    // Keyed: no typed address — the platform uses the account's email.
    \tangible\framework\update_plugin_settings($this->plugin, [
      \tangible\updater\get_license_key_setting_field() => 'TGBL-TEST-KEY',
    ]);
    add_filter('pre_http_request', function () { return new \WP_Error('offline', 'no network in tests'); });
    onboarding\mark('coretest', 'welcome', 'done');
    $plan = onboarding\resolve_plan('coretest', onboarding\build_facts('coretest'));
    $this->assertSame('done', $plan['current']);
    $this->assertTrue($plan['steps'][0]['submit_in_body']);
    ob_start(); ($plan['steps'][0]['render'])($this->plugin); $html = ob_get_clean();
    $this->assertStringContainsString('Finish Setup', $html);
    $this->assertStringNotContainsString('name="marketing_email"', $html);

    $_POST = [];
    $this->assertSame('done', onboarding\handle_step_submission('coretest', [ 'step' => 'done', 'do' => 'continue' ]));
    $entry = get_option(onboarding\CONSENT_OUTBOX)['marketing'];
    $this->assertSame('granted', $entry['answer']);
    $this->assertSame('', $entry['email']);
    $this->assertStringContainsString('Core Test', $entry['consent_text']);
    delete_option('coretest_settings');
    remove_all_filters('pre_http_request');
  }

  function test_with_marketing_on_file_done_finishes_from_the_footer_and_records_nothing() {
    onboarding\record_consent_answer('marketing', 'declined', 'wording v1');
    onboarding\mark('coretest', 'welcome', 'done');
    $plan = onboarding\resolve_plan('coretest', onboarding\build_facts('coretest'));
    $this->assertFalse($plan['steps'][0]['submit_in_body']);
    $this->assertSame('done', onboarding\handle_step_submission('coretest', [ 'step' => 'done', 'do' => 'continue' ]));
    $this->assertSame('declined', get_option(onboarding\CONSENT_OUTBOX)['marketing']['answer']);
  }

  function test_a_handler_wp_error_keeps_the_step_open_and_stores_the_reason() {
    // Welcome (weight 5) must not outrank the fixture's creds step (50):
    // answer it up front.
    onboarding\mark('coretest', 'welcome', 'done');
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
    // Site/plugin context and one stable id per batch (tangible-one #236).
    $this->assertSame('synctest', $body['slug']);
    $this->assertStringStartsWith('wizard-batch:', $body['source_operation_id']);

    $outbox = get_option(onboarding\CONSENT_OUTBOX);
    $this->assertTrue($outbox['telemetry_extended']['synced']);

    // Nothing left to deliver: the next attempt does not even POST.
    onboarding\attempt_consent_sync($this->plugin);
    $this->assertCount(1, $this->requests);
  }

  function test_a_retried_batch_carries_the_same_operation_id() {
    $this->fake_server(new \WP_Error('http', 'unreachable'));
    onboarding\attempt_consent_sync($this->plugin);
    onboarding\attempt_consent_sync($this->plugin);
    $this->assertCount(2, $this->requests);
    $this->assertSame($this->requests[0]['body']['source_operation_id'],
      $this->requests[1]['body']['source_operation_id']);
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
 * The steward answer — one site option, no account keying (2026-09-25).
 */
class Steward_TestCase extends \WP_UnitTestCase {

  function setUp(): void {
    parent::setUp();
    $plugin = \tangible\framework\register_plugin([ 'name' => 'stewtest', 'title' => 'Stew Test' ]);
    onboarding\register_wizard($plugin);
  }

  function tearDown(): void {
    delete_option(onboarding\STEWARD_OPTION);
    delete_option('tangible_onboarding_state__site');
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

  function test_the_welcome_checkbox_is_the_site_answer() {
    // Ticked → client; the answer is the site's, so another plugin's Welcome
    // opens with it ticked. Unticked is a complete answer too: me/my team.
    $this->assertSame('welcome', $this->plan('acct_A')['current']);
    $_POST = [ 'steward_client' => '1' ];
    $this->assertSame('welcome', onboarding\handle_step_submission('stewtest', [ 'step' => 'welcome', 'do' => 'continue' ]));
    $_POST = [];
    $this->assertSame('client', onboarding\get_steward());

    $other = \tangible\framework\register_plugin([ 'name' => 'stewtest2', 'title' => 'Stew Test 2' ]);
    onboarding\register_wizard($other);
    ob_start(); onboarding\render_welcome($other); $html = ob_get_clean();
    $this->assertMatchesRegularExpression('/name="steward_client" value="1"\s+checked/', $html);

    $this->assertSame('welcome', onboarding\handle_step_submission('stewtest2', [ 'step' => 'welcome', 'do' => 'continue' ]));
    $this->assertSame('team', onboarding\get_steward());
    delete_option('tangible_onboarding_state__stewtest');
    delete_option('tangible_onboarding_state__stewtest2');
  }

  function test_older_shapes_still_read() {
    update_option(onboarding\STEWARD_OPTION, 'team');
    $this->assertSame('team', onboarding\get_steward());
    // The 2026-08-28 per-account map: client only when every answer says so.
    update_option(onboarding\STEWARD_OPTION, [ 'acct_A' => 'client', '' => 'client' ]);
    $this->assertSame('client', onboarding\get_steward());
    update_option(onboarding\STEWARD_OPTION, [ 'acct_A' => 'client', 'acct_B' => 'team' ]);
    $this->assertSame('team', onboarding\get_steward());
  }

  function test_hub_client_managed_follows_the_site_answer() {
    onboarding\set_steward('client');
    $this->assertTrue(\tangible\hub\is_client_managed_site());
    onboarding\set_steward('team');
    $this->assertFalse(\tangible\hub\is_client_managed_site());
  }
}

/**
 * Appearance rules, Done, Back (plugin-onboarder spec §3, §5).
 */
class Shell_Rules_TestCase extends \WP_UnitTestCase {

  function setUp(): void {
    parent::setUp();
    $plugin = \tangible\framework\register_plugin([ 'name' => 'ruletest', 'title' => 'Rule Test' ]);
    onboarding\register_wizard($plugin);
    add_filter('tangible_onboarding_facts', function ($f) {
      $f->ask = [ 'telemetry_extended' => 'skip', 'marketing' => 'skip' ];
      return $f;
    });
  }

  function tearDown(): void {
    foreach ([onboarding\STEWARD_OPTION, onboarding\ONBOARDED_OPTION,
              'tangible_onboarding_state__site', 'tangible_onboarding_state__ruletest'] as $o) delete_option($o);
    remove_all_filters('tangible_onboarding_facts');
    remove_all_filters('tangible_onboarding_steps_extra');
    parent::tearDown();
  }

  private function plan() {
    return onboarding\resolve_plan('ruletest', onboarding\build_facts('ruletest'));
  }

  function test_done_is_last_and_never_counts_as_a_question() {
    onboarding\mark('ruletest', 'welcome', 'done');
    $plan = $this->plan();
    $this->assertSame(['done'], array_column($plan['steps'], 'id'));
    $this->assertSame([], onboarding\pending_questions($plan));
    $this->assertSame(0, onboarding\steps_left('ruletest'));
  }

  function test_done_renders_plugin_notices_and_next_step_links() {
    onboarding\mark('ruletest', 'welcome', 'done');
    add_filter('tangible_onboarding_done', function ($done) {
      $done['heading'] = 'Rule Test is installed';
      $done['notices'][] = [ 'title' => 'A setting is in the way', 'text' => 'Turn it off.',
        'actions' => [ [ 'label' => 'Open settings', 'url' => 'https://example.test/s', 'style' => 'button' ] ] ];
      $done['links'][] = [ 'label' => 'See it on a course', 'url' => 'https://example.test/c', 'kind' => 'Course' ];
      return $done;
    });
    $step = $this->plan()['steps'][0];
    remove_all_filters('tangible_onboarding_done');
    $this->assertSame('center', $step['align']);
    ob_start(); ($step['render'])((object) [ 'name' => 'ruletest', 'title' => 'Rule Test' ]); $html = ob_get_clean();
    $this->assertStringContainsString('Rule Test is installed', $html);
    $this->assertStringContainsString('A setting is in the way', $html);
    $this->assertStringContainsString('tgbl-notice__button', $html);
    $this->assertStringContainsString('What people usually do next', $html);
    $this->assertStringContainsString('<span class="tgbl-tag">Course</span>', $html);
  }

  function test_done_redirects_to_its_primary_action() {
    onboarding\mark('ruletest', 'welcome', 'done');
    add_filter('tangible_onboarding_done', function ($done) {
      $done['primary'] = [ 'label' => 'Open it', 'url' => 'https://example.test/open' ];
      return $done;
    });
    $recorded = onboarding\handle_step_submission('ruletest', [ 'step' => 'done', 'do' => 'continue' ]);
    remove_all_filters('tangible_onboarding_done');
    $this->assertSame('done', $recorded);
    $this->assertSame('https://example.test/open', onboarding\pending_redirect());
  }

  function test_activation_is_noticed_by_basename_and_consumed_once() {
    do_action('activated_plugin', 'ruletest/ruletest.php', false);
    $this->assertTrue(onboarding\consume_activation('ruletest/ruletest.php'));
    $this->assertFalse(onboarding\consume_activation('ruletest/ruletest.php'));
    // Network activation leaves nothing behind.
    do_action('activated_plugin', 'ruletest/ruletest.php', true);
    $this->assertFalse(onboarding\consume_activation('ruletest/ruletest.php'));
    delete_option(onboarding\ACTIVATED_OPTION);
  }

  function test_a_deactivated_plugin_leaves_the_snapshot() {
    update_option(onboarding\ACTIVE_SNAPSHOT_OPTION, ['a/a.php', 'ruletest/ruletest.php'], false);
    do_action('deactivated_plugin', 'ruletest/ruletest.php', false);
    $this->assertSame(['a/a.php'], get_option(onboarding\ACTIVE_SNAPSHOT_OPTION));
    delete_option(onboarding\ACTIVE_SNAPSHOT_OPTION);
  }

  function test_only_a_sole_never_onboarded_wizard_is_guessed() {
    delete_option(onboarding\ONBOARDED_OPTION);
    foreach (array_keys(onboarding\registered_wizards()) as $name) {
      if ($name !== 'ruletest') onboarding\mark_onboarded($name);
    }
    $this->assertSame('ruletest', onboarding\sole_unonboarded_wizard());
    onboarding\registered_wizards('othertest');
    $this->assertNull(onboarding\sole_unonboarded_wizard());
    delete_option(onboarding\ONBOARDED_OPTION);
  }

  function test_the_notice_is_sticky_only_for_an_unlicensed_paid_plugin_on_a_live_site() {
    $plugin = (object) [ 'name' => 'ruletest', 'setting_prefix' => 'ruletest' ];
    $unlicensed = (object) [ 'licence_active' => false ];
    $licensed = (object) [ 'licence_active' => true ];
    $live = function () { return false; };
    add_filter('tangible_onboarding_is_dev_site', $live);

    update_option('ruletest_distribution', 'licensed');
    $this->assertTrue(onboarding\notice_is_sticky($plugin, $unlicensed));
    $this->assertFalse(onboarding\notice_is_sticky($plugin, $licensed));

    update_option('ruletest_distribution', 'free');
    $this->assertFalse(onboarding\notice_is_sticky($plugin, $unlicensed));
    delete_option('ruletest_distribution');
    $this->assertFalse(onboarding\notice_is_sticky($plugin, $unlicensed), 'unknown is not paid');

    remove_filter('tangible_onboarding_is_dev_site', $live);
    update_option('ruletest_distribution', 'licensed');
    add_filter('tangible_onboarding_is_dev_site', '__return_true');
    $this->assertFalse(onboarding\notice_is_sticky($plugin, $unlicensed), 'dev sites may dismiss');
    remove_filter('tangible_onboarding_is_dev_site', '__return_true');
    delete_option('ruletest_distribution');
  }

  function test_the_onboarded_marker_is_set_once() {
    $this->assertFalse(onboarding\was_onboarded('ruletest'));
    onboarding\mark_onboarded('ruletest');
    $first = get_option(onboarding\ONBOARDED_OPTION)['ruletest'];
    onboarding\mark_onboarded('ruletest');
    $this->assertTrue(onboarding\was_onboarded('ruletest'));
    $this->assertSame($first, get_option(onboarding\ONBOARDED_OPTION)['ruletest']);
  }

  function test_back_from_done_reopens_welcome_with_its_answers_kept() {
    $_POST = [ 'steward_client' => '1' ];
    $this->assertSame('welcome', onboarding\handle_step_submission('ruletest', [ 'step' => 'welcome', 'do' => 'continue' ]));
    $_POST = [];
    $this->assertSame('done', $this->plan()['current']);

    $this->assertSame('welcome', onboarding\handle_step_submission('ruletest', [ 'step' => 'done', 'do' => 'back' ]));
    $this->assertSame('welcome', $this->plan()['current']);
    // The checkbox is the site option; Welcome opens with it still ticked.
    $this->assertSame('client', onboarding\get_steward());
  }
}
