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
      'cloud_id' => 'coretest',
    ]);
    onboarding\register_wizard($this->plugin);
  }

  function tearDown(): void {
    delete_option('tangible_onboarding_state__coretest');
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
