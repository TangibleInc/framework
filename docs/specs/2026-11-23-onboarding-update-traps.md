# Onboarding update traps

## Problem

The wizard opens on activation only, once per plugin per site. An update
never fires `activated_plugin`, so a site that updates a plugin into a
release with an onboarder never sees it. The case that needs this first is
the free Design Upgrade for LearnDash: thousands of wordpress.org installs
update from 2.12.x (no framework) to 2.13 (onboarder), and the first wp-admin
load after that update is the one chance to show setup.

The resolver already has `version:X` step scope (a step answered once per
upgrade boundary), but nothing notices that an upgrade happened.

## Design

A plugin registers **traps** the way it registers steps: rows through one
filter. A trap names the update it waits for and what it does then. Nothing
fires for a plugin that registers none; there is no framework-wide "on
update" behaviour.

```php
add_filter('tangible_onboarding_traps', function ($traps, $plugin_name) {
  if ($plugin_name !== 'design-upgrade-learndash') return $traps;
  $traps[] = [
    'id'        => 'first-onboarder',   // required, unique per plugin
    'to'        => ['at_least', '2.13'],
    'from'      => ['below', '2.13'],   // optional; 'unrecorded' also matches
    'onboarded' => 'never_this_plugin',
    'action'    => 'wizard',
  ];
  return $traps;
}, 10, 2);
```

### Fields

| Field | Values | Meaning |
| --- | --- | --- |
| `to` | `['exact', V]`, `['at_least', V]`, `'always'` | The version the site just updated to. `at_least` fires on the first update landing on V or later. |
| `from` | `['below', V]`, `'unrecorded'`, `'any'` (default) | The version it updated from. `unrecorded`: the previous version predates the version record (a release without this framework). `['below', V]` also matches `unrecorded`, since anything that old is below V. |
| `onboarded` | `'any'` (default), `'never_this_plugin'`, `'never_any_tangible'`, `'already_this_plugin'` | This site's onboarding history (the framework's `tangible_onboarding_onboarded` map). |
| `action` | `'wizard'` | Redirect to the plugin's setup page once. The only action in v1. |

Versions compare with `version_compare`.

### Detecting an update

The framework keeps the last-seen version of every plugin that registers a
wizard (`tangible_onboarding_versions`: name => version), checked on
`admin_init`:

- **Recorded and lower than now**: an update from that version.
- **Not recorded, and no activation pending** for the plugin: an update from
  an unrecorded version.
- **Not recorded, activation pending**: a fresh install. Traps never fire on
  activation; the activation redirect handles it.
- Then the current version is recorded.

Detection by version rather than `upgrader_process_complete` catches every
update path: auto-updates, WP-CLI, bulk updates, zip uploads.

### Firing

A trap that matches is recorded as fired (`tangible_onboarding_traps_fired`:
`name:id@version`), so it fires at most once per site and version, and sets
the plugin's one-shot redirect flag: the same flag and the same guards as
the activation redirect. The redirect is skipped for:

- non-admins (`manage_options`)
- AJAX, cron and WP-CLI requests
- bulk activation, network admin
- `TANGIBLE_ONBOARDING_DISABLE`
- a wizard with nothing left to ask

Unlike the activation redirect, a trap's redirect doesn't require "never
onboarded": the trap's own `onboarded` condition decides that.

At most one redirect per admin load. When the activation redirect and a trap
would both fire, activation wins and the trap's flag waits for the next load.

### Non-goals

- A notice action. Nothing needs one yet (the paid plugins' interim banner is
  plain code in the Escape Creative releases, without the framework).
- Opt-out: there is nothing to opt out of; traps are opt-in by registering.

## wordpress.org

Guideline 11 discourages taking over wp-admin; a redirect after an update is
more likely to draw a complaint than one after activation. The mitigation is
in the guards: once per site, admins only, an ordinary admin page load, and a
Skip on every step.

## Tests (tests/phpunit/onboarding-traps.php)

- `to` exact / at_least / always against recorded and unrecorded histories
- `from` below matches unrecorded; `from` any vs unrecorded
- each `onboarded` value
- fresh install (activation pending) never fires
- fired once per version: the same trap doesn't fire again on reload
- activation and a trap on the same load: activation redirects, trap waits
- guards: non-admin, AJAX, disabled
- an empty plan doesn't redirect
