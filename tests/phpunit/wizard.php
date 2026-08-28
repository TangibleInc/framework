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
