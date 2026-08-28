<?php
namespace tests\framework;

use tangible\onboarding;

/**
 * Onboarding module — the resolver core.
 *
 * The model under test: onboarding is NOT a state machine with a stored
 * current-step. It is a plan resolved fresh from facts each time —
 * `steps = registry filtered by needed(facts)` — so answers given elsewhere
 * (another site, checkout, the dashboard) are honoured without any sync step.
 * Steps carry a `scope` that decides where their "answered" record lives;
 * account-scoped facts are resolved server-side by the caller and only ride in
 * through $facts, which is why nothing here ever writes an account fact.
 */
class Onboarding_TestCase extends \WP_UnitTestCase {

  static $filter = 'tangible_onboarding_steps';

  function tearDown(): void {
    remove_all_filters(self::$filter);
    delete_option('tangible_onboarding_state__example');
    parent::tearDown();
  }

  // ── registration ─────────────────────────────────────────────────

  function test_steps_are_contributed_through_the_filter() {
    add_filter(self::$filter, function ($steps, $facts) {
      $steps[] = [ 'id' => 'engine', 'render' => '__return_null' ];
      return $steps;
    }, 10, 2);

    $plan = onboarding\resolve_plan('example', (object) []);
    $this->assertSame(['engine'], array_column($plan['steps'], 'id'));
  }

  function test_a_step_without_an_id_is_rejected_loudly() {
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'render' => '__return_null' ];
      return $steps;
    });
    $this->expectException(\Exception::class);
    onboarding\resolve_plan('example', (object) []);
  }

  function test_duplicate_ids_are_rejected() {
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'id' => 'engine' ];
      $steps[] = [ 'id' => 'engine' ];
      return $steps;
    });
    $this->expectException(\Exception::class);
    onboarding\resolve_plan('example', (object) []);
  }

  // ── the resolver ──────────────────────────────────────────────────

  function test_needed_false_removes_the_step_but_keeps_it_on_the_rail() {
    add_filter(self::$filter, function ($steps, $facts) {
      $steps[] = [ 'id' => 'licence', 'needed' => fn() => false,
                   'skip_note' => 'on file' ];
      $steps[] = [ 'id' => 'engine' ];
      return $steps;
    }, 10, 2);

    $plan = onboarding\resolve_plan('example', (object) []);

    // The plan runs only what is needed…
    $this->assertSame(['engine'], array_column($plan['steps'], 'id'));
    // …but the rail is honest about what was not asked and why: a skipped
    // step renders as "not asked · on file", it does not vanish.
    $rail = array_column($plan['rail'], 'state', 'id');
    $this->assertSame('skipped', $rail['licence']);
    // engine is the first (only) runnable step, so it rails as current.
    $this->assertSame('current', $rail['engine']);
  }

  function test_needed_receives_the_facts() {
    add_filter(self::$filter, function ($steps, $facts) {
      $steps[] = [ 'id' => 'consent',
                   'needed' => fn($f) => $f->ask_extended ];
      return $steps;
    }, 10, 2);

    $asked = onboarding\resolve_plan('example', (object) [ 'ask_extended' => true ]);
    $silent = onboarding\resolve_plan('example', (object) [ 'ask_extended' => false ]);
    $this->assertCount(1, $asked['steps']);
    $this->assertCount(0, $silent['steps']);
  }

  function test_steps_order_by_weight_and_registration_order_breaks_ties() {
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'id' => 'c', 'weight' => 50 ];
      $steps[] = [ 'id' => 'a', 'weight' => 10 ];
      $steps[] = [ 'id' => 'd', 'weight' => 50 ];
      $steps[] = [ 'id' => 'b', 'weight' => 20 ];
      return $steps;
    });
    $plan = onboarding\resolve_plan('example', (object) []);
    $this->assertSame(['a','b','c','d'], array_column($plan['steps'], 'id'));
  }

  function test_after_pulls_a_step_behind_its_dependency_despite_weight() {
    add_filter(self::$filter, function ($steps) {
      // apply says weight 10 but depends on choose (weight 50): the DAG edge
      // wins over the weight, because consent-before-side-effects is an
      // invariant and weight is just typography.
      $steps[] = [ 'id' => 'apply', 'weight' => 10, 'after' => ['choose'] ];
      $steps[] = [ 'id' => 'choose', 'weight' => 50 ];
      return $steps;
    });
    $plan = onboarding\resolve_plan('example', (object) []);
    $ids = array_column($plan['steps'], 'id');
    $this->assertGreaterThan(array_search('choose', $ids), array_search('apply', $ids));
  }

  function test_after_referencing_a_skipped_step_does_not_strand_the_dependent() {
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'id' => 'choose', 'needed' => fn() => false ];
      $steps[] = [ 'id' => 'apply', 'after' => ['choose'] ];
      return $steps;
    });
    $plan = onboarding\resolve_plan('example', (object) []);
    $this->assertSame(['apply'], array_column($plan['steps'], 'id'));
  }

  function test_a_dependency_cycle_is_rejected_loudly() {
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'id' => 'a', 'after' => ['b'] ];
      $steps[] = [ 'id' => 'b', 'after' => ['a'] ];
      return $steps;
    });
    $this->expectException(\Exception::class);
    onboarding\resolve_plan('example', (object) []);
  }

  // ── recorded answers (site / plugin / version scopes) ─────────────

  function test_a_completed_step_stops_resolving_and_rails_as_done() {
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'id' => 'engine' ];
      $steps[] = [ 'id' => 'apply' ];
      return $steps;
    });

    onboarding\mark('example', 'engine', 'done');
    $plan = onboarding\resolve_plan('example', (object) []);

    $this->assertSame(['apply'], array_column($plan['steps'], 'id'));
    $rail = array_column($plan['rail'], 'state', 'id');
    $this->assertSame('done', $rail['engine']);
  }

  function test_a_skipped_step_does_not_resolve_again_a_nudge_is_not_a_nag() {
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'id' => 'whats-new', 'skippable' => true ];
      return $steps;
    });
    onboarding\mark('example', 'whats-new', 'skipped');
    $plan = onboarding\resolve_plan('example', (object) []);
    $this->assertCount(0, $plan['steps']);
  }

  function test_version_scope_reopens_when_the_boundary_moves() {
    $register = function ($since) {
      remove_all_filters(self::$filter);
      add_filter(self::$filter, function ($steps) use ($since) {
        $steps[] = [ 'id' => 'whats-new', 'scope' => 'version:' . $since ];
        return $steps;
      });
    };

    // Seen at the 3.0.0 boundary…
    $register('3.0.0');
    onboarding\mark('example', 'whats-new', 'done');
    $this->assertCount(0, onboarding\resolve_plan('example', (object) [])['steps']);

    // …the same id at a NEW boundary is a new question. The record is keyed
    // by (id, boundary), so 4.0.0's what's-new shows even though 3.0.0's was
    // dismissed — this is the once-per-upgrade-boundary bucket from the
    // design, expressed in storage rather than in anyone's memory.
    $register('4.0.0');
    $this->assertCount(1, onboarding\resolve_plan('example', (object) [])['steps']);
  }

  function test_account_scope_never_touches_local_state() {
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'id' => 'consent', 'scope' => 'account' ];
      return $steps;
    });
    // Marking an account-scoped step records nothing locally: the truth for
    // account facts lives server-side and rides in through $facts. A local
    // copy would go stale the moment the same account answered on another
    // site, which is the exact staleness the resolver model exists to avoid.
    onboarding\mark('example', 'consent', 'done');
    $this->assertFalse(get_option('tangible_onboarding_state__example'));
    // And it still resolves — only needed($facts) can silence it.
    $this->assertCount(1, onboarding\resolve_plan('example', (object) [])['steps']);
  }

  function test_an_unknown_scope_is_rejected_loudly() {
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'id' => 'x', 'scope' => 'user' ];
      return $steps;
    });
    $this->expectException(\Exception::class);
    onboarding\resolve_plan('example', (object) []);
  }

  function test_site_scope_is_shared_across_plugins() {
    // The scope's whole meaning: a steward answer given during plugin A's
    // setup must not be re-asked by plugin B on the same site.
    add_filter(self::$filter, function ($steps) {
      $steps[] = [ 'id' => 'steward', 'scope' => 'site' ];
      return $steps;
    });
    onboarding\mark('plugin-a', 'steward', 'done');
    $this->assertCount(0, onboarding\resolve_plan('plugin-b', (object) [])['steps']);
    delete_option('tangible_onboarding_state__site');
  }

  function test_an_unknown_mark_status_is_rejected() {
    $this->expectException(\Exception::class);
    onboarding\mark('example', 'x', 'maybe');
  }

  // ── the whole story, once ─────────────────────────────────────────

  function test_a_paid_second_install_asks_only_the_plugin_specific_step() {
    // The scenario the whole design serves: paid account, consented at
    // checkout, second Tangible plugin on the site. Account steps resolve
    // out via facts, and the wizard is one screen long.
    add_filter(self::$filter, function ($steps, $facts) {
      $steps[] = [ 'id' => 'licence', 'weight' => 10, 'scope' => 'site',
                   'needed' => fn($f) => !$f->licence_active ];
      $steps[] = [ 'id' => 'telemetry-extended', 'weight' => 20, 'scope' => 'account',
                   'needed' => fn($f) => $f->ask['telemetry_extended'] === 'ask' ];
      $steps[] = [ 'id' => 'marketing', 'weight' => 30, 'scope' => 'account',
                   'needed' => fn($f) => $f->ask['marketing'] === 'ask' ];
      $steps[] = [ 'id' => 'engine', 'weight' => 50, 'scope' => 'plugin' ];
      return $steps;
    }, 10, 2);

    $facts = (object) [
      'licence_active' => true,
      // the resolve endpoint returns decisions, not state — by design
      'ask' => [ 'telemetry_extended' => 'skip', 'marketing' => 'skip' ],
    ];

    $plan = onboarding\resolve_plan('example', $facts);
    $this->assertSame(['engine'], array_column($plan['steps'], 'id'));
    $this->assertSame('engine', $plan['current']);
    $rail = array_column($plan['rail'], 'state', 'id');
    $this->assertSame(['licence'=>'skipped','telemetry-extended'=>'skipped','marketing'=>'skipped','engine'=>'current'],
      $rail);
  }
}
