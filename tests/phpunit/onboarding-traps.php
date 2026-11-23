<?php
namespace tests\framework;

use tangible\onboarding;

/**
 * Update traps: detecting an update, matching traps, firing once.
 */
class Onboarding_Traps_TestCase extends \WP_UnitTestCase {

  function tearDown(): void {
    remove_all_filters('tangible_onboarding_traps');
    foreach ([
      onboarding\VERSIONS_OPTION,
      onboarding\TRAPS_FIRED_OPTION,
      onboarding\ONBOARDED_OPTION,
      onboarding\trap_redirect_flag('example'),
    ] as $option) delete_option($option);
    parent::tearDown();
  }

  private function trap($trap) {
    add_filter('tangible_onboarding_traps', function ($traps, $name) use ($trap) {
      if ($name === 'example') $traps[] = $trap + [ 'id' => 'first' ];
      return $traps;
    }, 10, 2);
  }

  private function update($from, $to) {
    return onboarding\fire_traps('example', $from, $to);
  }

  function test_a_fresh_install_is_not_an_update() {
    $this->assertNull(onboarding\detect_update('example', '2.13', true));
    // And it is recorded: the next load at the same version is no update either.
    $this->assertNull(onboarding\detect_update('example', '2.13', false));
  }

  function test_no_record_without_an_activation_is_an_update_from_unrecorded() {
    $this->assertSame([ 'from' => null ], onboarding\detect_update('example', '2.13', false));
  }

  function test_a_higher_version_is_an_update_from_the_recorded_one() {
    onboarding\detect_update('example', '2.13', true);
    $this->assertSame([ 'from' => '2.13' ], onboarding\detect_update('example', '2.14', false));
    $this->assertNull(onboarding\detect_update('example', '2.14', false));
  }

  function test_a_downgrade_is_not_an_update() {
    onboarding\detect_update('example', '2.14', true);
    $this->assertNull(onboarding\detect_update('example', '2.13', false));
  }

  function test_exact_fires_only_on_that_version() {
    $this->trap([ 'to' => ['exact', '2.13'] ]);
    $this->assertSame([], $this->update('2.12', '2.14'));
    $this->assertSame(['first'], $this->update('2.12', '2.13'));
  }

  function test_at_least_fires_on_the_first_update_onto_or_past_the_version() {
    $this->trap([ 'to' => ['at_least', '2.13'] ]);
    $this->assertSame([], $this->update('2.11', '2.12'));
    $this->assertSame(['first'], $this->update('2.12', '2.14'));
    // Already past it: a later update is not "the first onto 2.13".
    $this->assertSame([], $this->update('2.14', '2.15'));
  }

  function test_at_least_counts_an_unrecorded_history_as_below() {
    $this->trap([ 'to' => ['at_least', '2.13'] ]);
    $this->assertSame(['first'], $this->update(null, '2.13'));
  }

  function test_always_fires_on_every_new_version_once() {
    $this->trap([ 'to' => 'always' ]);
    $this->assertSame(['first'], $this->update('1.0', '1.1'));
    $this->assertSame([], $this->update('1.0', '1.1'));
    $this->assertSame(['first'], $this->update('1.1', '1.2'));
  }

  function test_from_below_matches_lower_and_unrecorded() {
    $this->trap([ 'from' => ['below', '2.13'] ]);
    $this->assertSame([], $this->update('2.13', '2.14'));
    $this->assertSame(['first'], $this->update('2.12', '2.14'));
    $this->assertSame(['first'], $this->update(null, '2.15'));
  }

  function test_from_unrecorded_matches_only_unrecorded() {
    $this->trap([ 'from' => 'unrecorded' ]);
    $this->assertSame([], $this->update('2.12', '2.13'));
    $this->assertSame(['first'], $this->update(null, '2.13'));
  }

  function test_onboarded_conditions() {
    $this->trap([ 'id' => 'never-here', 'onboarded' => 'never_this_plugin' ]);
    $this->trap([ 'id' => 'never-any', 'onboarded' => 'never_any_tangible' ]);
    $this->trap([ 'id' => 'already', 'onboarded' => 'already_this_plugin' ]);

    $this->assertSame(['never-here', 'never-any'], $this->update('1.0', '1.1'));

    onboarding\mark_onboarded('another-plugin');
    $this->assertSame(['never-here'], $this->update('1.1', '1.2'));

    onboarding\mark_onboarded('example');
    $this->assertSame(['already'], $this->update('1.2', '1.3'));
  }

  function test_firing_raises_the_trap_redirect_flag() {
    $this->trap([ 'to' => 'always' ]);
    $this->assertFalse((bool) get_option(onboarding\trap_redirect_flag('example')));
    $this->update('1.0', '1.1');
    $this->assertTrue((bool) get_option(onboarding\trap_redirect_flag('example')));
  }

  function test_the_redirect_waits_for_an_admin() {
    update_option(onboarding\trap_redirect_flag('example'), 1);
    wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
    $this->assertFalse(onboarding\trap_redirect_due('example'));
    // The flag survives for the next load that can redirect.
    $this->assertTrue((bool) get_option(onboarding\trap_redirect_flag('example')));

    wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    $this->assertTrue(onboarding\trap_redirect_due('example'));
  }

  function test_a_malformed_trap_throws() {
    $this->trap([ 'to' => ['since', '2.13'] ]);
    $this->expectException(\Exception::class);
    onboarding\get_traps('example');
  }

  function test_no_traps_means_nothing_fires() {
    $this->assertSame([], $this->update(null, '9.9'));
    $this->assertFalse((bool) get_option(onboarding\trap_redirect_flag('example')));
  }
}
