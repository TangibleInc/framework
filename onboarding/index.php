<?php
/**
 * Onboarding — the resolver core
 *
 * Onboarding here is not a state machine with a stored current-step. It is a
 * plan resolved fresh from facts every time it is asked for:
 *
 *   steps = registry, filtered by needed(facts), minus what is already answered
 *
 * A stored "you are at step 3" goes stale the moment the same question is
 * answered somewhere else — checkout grants consent after install, a licence
 * activates from another tab, the same account finishes a wizard on another
 * site. Deriving the plan from facts makes that staleness impossible, which is
 * the same reason the platform's consent projection is replayed from its event
 * log rather than incremented.
 *
 * ## Contributing steps
 *
 * Plugins add rows through one filter — the same registry shape as the
 * campaign formats and the what's-new manifest:
 *
 *   add_filter('tangible_onboarding_steps', function ($steps, $facts) {
 *     $steps[] = [
 *       'id'     => 'engine',                      // required, unique
 *       'weight' => 50,                            // display order
 *       'scope'  => 'plugin',                      // where "answered" lives
 *       'needed' => fn($f) => !get_option('…'),    // beyond the recorded state
 *       'after'  => ['choose'],                    // hard ordering edge
 *       'render' => 'my_step_renderer',
 *     ];
 *     return $steps;
 *   }, 10, 2);
 *
 * `weight` is typography; `after` is an invariant. When they disagree — an
 * apply step that must follow the choice it applies — the edge wins, because
 * consent-before-side-effects is not a layout preference.
 *
 * ## Scopes
 *
 * A step's scope names where its "already answered" record lives, which is
 * what decides how often it fires:
 *
 *   account     server-side. This module records NOTHING for account steps —
 *               a local copy would go stale the moment the same account
 *               answered on another site. Only `needed($facts)` can silence
 *               them, and $facts is the caller's job to resolve (the licence
 *               check returns ask/skip decisions, not state).
 *   site        one shared record per site, across all Tangible plugins
 *               (a steward answer given during plugin A's setup must not be
 *               re-asked by plugin B).
 *   plugin      one record per plugin per site. The default.
 *   version:X   one record per plugin per upgrade boundary. The same id at a
 *               new boundary is a new question — 4.0.0's what's-new shows even
 *               though 3.0.0's was dismissed.
 */
namespace tangible\onboarding;

use tangible\onboarding;

const STEP_FILTER = 'tangible_onboarding_steps';

const SCOPES = ['account', 'site', 'plugin'];   // plus 'version:*', checked below

function get_state_key($plugin_name, $scope = 'plugin') {
  // Site-scoped answers are shared across plugins on purpose — that is the
  // scope's whole meaning. Everything else is per plugin.
  return $scope === 'site'
    ? 'tangible_onboarding_state__site'
    : 'tangible_onboarding_state__' . $plugin_name;
}

function validate_scope($scope) {
  if (in_array($scope, SCOPES, true)) return;
  if (strpos($scope, 'version:') === 0 && strlen($scope) > 8) return;
  throw new \Exception("Onboarding step scope \"$scope\" is not one of: "
    . implode(', ', SCOPES) . ", version:<boundary>");
}

/** The record key. Version-scoped records are keyed by (id, boundary). */
function get_record_key($step) {
  $scope = $step['scope'];
  return strpos($scope, 'version:') === 0
    ? $step['id'] . '@' . substr($scope, 8)
    : $step['id'];
}

/**
 * Collect and validate the registry for one plugin.
 *
 * Validation throws rather than skips: a malformed step silently dropped
 * would read as "already answered" on the rail, and a wizard that quietly
 * loses a consent step is the worst version of broken.
 */
function get_steps($plugin_name, $facts) {
  $raw = apply_filters(STEP_FILTER, [], $facts, $plugin_name);

  $steps = [];
  $seen = [];
  $seq = 0;
  foreach ($raw as $step) {
    if (empty($step['id']) || !is_string($step['id'])) {
      throw new \Exception('Onboarding step needs a string id');
    }
    if (isset($seen[ $step['id'] ])) {
      throw new \Exception("Onboarding step id \"{$step['id']}\" registered twice");
    }
    $seen[ $step['id'] ] = true;

    $step += [
      'weight'    => 50,
      'scope'     => 'plugin',
      'needed'    => null,
      'after'     => [],
      'skippable' => true,
      'render'    => null,
      'skip_note' => null,
    ];
    validate_scope($step['scope']);
    $step['_seq'] = $seq++;          // registration order, the tiebreak
    $steps[] = $step;
  }
  return $steps;
}

/**
 * Order all steps: weight, then registration order, with `after` edges as
 * hard constraints. Kahn's algorithm picking the lightest available node, so
 * the sort is stable and a cycle leaves nodes stranded — which throws.
 *
 * Edges are honoured for ordering whether or not the dependency will run: a
 * dependent of a skipped step keeps its position, it is not stranded.
 */
function order_steps($steps) {
  $by_id = [];
  foreach ($steps as $s) $by_id[ $s['id'] ] = $s;

  $blocked_by = [];   // id => count of unmet deps
  $unblocks = [];     // id => ids it unblocks
  foreach ($steps as $s) {
    $blocked_by[ $s['id'] ] = 0;
    foreach ((array) $s['after'] as $dep) {
      if (!isset($by_id[$dep])) continue;   // edge to an unregistered id is inert
      $blocked_by[ $s['id'] ]++;
      $unblocks[$dep][] = $s['id'];
    }
  }

  $ordered = [];
  $available = array_filter($steps, function ($s) use ($blocked_by) {
    return $blocked_by[ $s['id'] ] === 0;
  });

  while (!empty($available)) {
    usort($available, function ($a, $b) {
      return $a['weight'] === $b['weight']
        ? $a['_seq'] <=> $b['_seq']
        : $a['weight'] <=> $b['weight'];
    });
    $next = array_shift($available);
    $ordered[] = $next;
    foreach ($unblocks[ $next['id'] ] ?? [] as $id) {
      if (--$blocked_by[$id] === 0) $available[] = $by_id[$id];
    }
  }

  if (count($ordered) !== count($steps)) {
    $stranded = array_diff(array_keys($by_id), array_column($ordered, 'id'));
    throw new \Exception('Onboarding steps have a dependency cycle: '
      . implode(', ', $stranded));
  }
  return $ordered;
}

/**
 * Resolve the plan for one plugin.
 *
 * Returns:
 *   steps    the steps to actually run, in order
 *   rail     EVERY registered step with a display state — done | skipped |
 *            current | pending. Skipped steps stay on the rail on purpose:
 *            "not asked · on file" is a promise about what we did not do, and
 *            a step that silently vanishes cannot make it.
 *   current  the first runnable step's id, or null when the wizard is over.
 */
function resolve_plan($plugin_name, $facts) {
  $steps = order_steps(get_steps($plugin_name, $facts));

  $site_state   = get_option(get_state_key($plugin_name, 'site'), []);
  $plugin_state = get_option(get_state_key($plugin_name, 'plugin'), []);

  $plan = [];
  $rail = [];
  foreach ($steps as $step) {
    $record = null;
    if ($step['scope'] !== 'account') {
      $state = $step['scope'] === 'site' ? $site_state : $plugin_state;
      $record = $state[ get_record_key($step) ] ?? null;
    }

    if ($record) {
      $rail[] = [ 'id' => $step['id'], 'state' => $record['status'] === 'skipped' ? 'skipped' : 'done' ];
      continue;
    }
    if (is_callable($step['needed']) && !call_user_func($step['needed'], $facts)) {
      $rail[] = [ 'id' => $step['id'], 'state' => 'skipped', 'note' => $step['skip_note'] ];
      continue;
    }
    $plan[] = $step;
    $rail[] = [ 'id' => $step['id'], 'state' => 'pending' ];
  }

  $current = $plan[0]['id'] ?? null;
  foreach ($rail as &$r) {
    if ($r['id'] === $current) $r['state'] = 'current';
  }
  unset($r);

  return [ 'steps' => $plan, 'rail' => $rail, 'current' => $current ];
}

/**
 * Record an answer. $status: 'done' | 'skipped'.
 *
 * Account-scoped steps record nothing here — their truth lives server-side
 * and the caller (the step's own handler) is responsible for having written
 * it there. Recording a local shadow would only create a second source that
 * disagrees later.
 */
function mark($plugin_name, $step_id, $status) {
  if (!in_array($status, ['done', 'skipped'], true)) {
    throw new \Exception("Onboarding mark status \"$status\" is not done|skipped");
  }

  // The step's scope decides where (and whether) the record lands.
  $steps = get_steps($plugin_name, (object) []);
  $found = null;
  foreach ($steps as $s) if ($s['id'] === $step_id) { $found = $s; break; }
  $scope = $found ? $found['scope'] : 'plugin';

  if ($scope === 'account') return;

  $key = get_state_key($plugin_name, $scope);
  $state = get_option($key, []);
  $state[ get_record_key($found ?? ['id' => $step_id, 'scope' => $scope]) ] = [
    'status' => $status,
    'at'     => time(),
  ];
  update_option($key, $state, false);
}

require_once __DIR__ . '/wizard.php';
