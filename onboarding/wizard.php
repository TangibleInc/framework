<?php
/**
 * The wizard shell — everything a plugin does NOT write.
 *
 * A plugin contributes steps (see index.php) and calls
 *
 *   onboarding\register_wizard($plugin);
 *
 * and the shell owns the rest: the activation redirect, the resumable
 * "setup pending" notice, the hidden setup page, the rail, the
 * continue/skip plumbing, and the guarantee that a step's `handle` runs
 * before its answer is recorded — nothing is saved until you continue.
 *
 * Step contract (in addition to the resolver fields):
 *
 *   render  callable($plugin, $facts, $step) — echoes the step's body.
 *           It runs inside the shell's <form>; fields it prints are the
 *           fields `handle` receives.
 *   handle  callable($plugin, $step) — on Continue, before the step is
 *           marked done. Reads $_POST, saves. Return false (or throw) to
 *           keep the step open — validation failure is not completion.
 *
 * v1 is server-rendered on purpose: no JS framework rides along until the
 * TUI-on-preact question is settled. The markup carries the House idioms
 * (tile mark, mono labels, dashed skip) so a hydration layer can land on
 * it later without re-deciding the design.
 *
 * Facts arrive through `tangible_onboarding_facts` ($facts, $plugin_name).
 * The default is honest about what is wired today: licence state comes from
 * the updater when present; the consent ask/skip decisions default to 'ask'
 * because the resolve endpoint is not built — the platform side
 * (resolveTelemetryConsentForKeyHash, marketing suppression) exists, the
 * HTTP route does not. When it lands, it plugs in here and nowhere else.
 */
namespace tangible\onboarding;

use tangible\framework;
use tangible\onboarding;

function get_setup_slug($plugin) {
  return ($plugin->name ?? $plugin['name']) . '-setup';
}

function get_setup_url($plugin) {
  return admin_url('admin.php?page=' . get_setup_slug($plugin));
}

function build_facts($plugin_name) {
  $facts = (object) [
    'licence_active' => false,
    // Decisions, not state — the shape the resolve endpoint will return.
    'ask' => [ 'telemetry_extended' => 'ask', 'marketing' => 'ask' ],
  ];
  $plugin = function_exists('tangible\\framework\\get_plugin')
    ? framework\get_plugin($plugin_name) : null;
  if ($plugin && function_exists('tangible\\updater\\get_license_status')) {
    $status = \tangible\updater\get_license_status($plugin);
    $facts->licence_active = in_array($status, ['valid', 'active'], true);
  }

  // Server-resolved decisions from the last activation, when the platform
  // sends them (steward, ask/skip per consent — decisions, never state).
  // Unknown stays 'ask': asking twice is annoying, collecting without
  // consent is illegal, so the failure mode is chosen deliberately.
  $cache = get_option('tangible_onboarding_facts_cache__' . $plugin_name, null);
  // Tolerant read: accept object or array — caches written before the
  // deep-convert fix hold a stdClass here, and array access on stdClass is
  // a fatal, not a null.
  $cached = is_array($cache) ? ($cache['data'] ?? null) : null;
  if (is_object($cached)) $cached = (array) $cached;
  $cached_ask = is_array($cached) ? (array) ($cached['ask'] ?? []) : [];
  if (!empty($cached_ask)) {
    foreach ($cached_ask as $k => $v) {
      if (in_array($v, ['ask', 'skip'], true)) $facts->ask[$k] = $v;
    }
  }
  // The opaque account handle + display name from the last activation. The
  // handle keys the LOCAL per-account steward map (steward lives on the site
  // by decision, 2026-08-28); the name lets the wizard say "managed by
  // Dave's Agency" instead of "a client".
  $facts->account_id = is_array($cached) && !empty($cached['accountId']) ? (string) $cached['accountId'] : '';
  $facts->account_name = is_array($cached) && !empty($cached['accountName']) ? (string) $cached['accountName'] : '';

  return apply_filters('tangible_onboarding_facts', $facts, $plugin_name);
}

/**
 * Wire the shell for one plugin. Call at plugins_loaded, after
 * framework\register_plugin().
 */
/**
 * Which plugins have a wizard. The shell's own steps (licence, consent)
 * attach only to these — a bare resolve_plan() for a name that never
 * registered a wizard sees exactly the steps its own filters contributed,
 * which is also what keeps test fixtures hermetic.
 */
function registered_wizards($add = null) {
  static $wizards = [];
  if ($add !== null) $wizards[$add] = true;
  return $wizards;
}

function register_wizard($plugin) {
  $name = $plugin->name;
  registered_wizards($name);
  $redirect_flag = 'tangible_onboarding_redirect__' . $name;

  // Activation → one-shot redirect flag. The flag pattern (not a direct
  // redirect) because activation runs in a request whose response the user
  // never sees; the next admin load performs the redirect.
  if (!empty($plugin->file_path)) {
    register_activation_hook($plugin->file_path, function () use ($redirect_flag) {
      update_option($redirect_flag, 1, false);
    });
  }

  add_action('admin_init', function () use ($plugin, $name, $redirect_flag) {
    if (!get_option($redirect_flag)) return;
    delete_option($redirect_flag);
    if (wp_doing_ajax() || !current_user_can('manage_options')) return;
    if (isset($_GET['activate-multi'])) return;   // bulk activation is not an invitation
    $plan = onboarding\resolve_plan($name, build_facts($name));
    if (empty($plan['steps'])) return;             // nothing to ask — stay out of the way
    wp_safe_redirect(get_setup_url($plugin));
    exit;
  });

  // The hidden setup page. Parent null = reachable by URL, absent from menus.
  add_action('admin_menu', function () use ($plugin) {
    $hook = add_submenu_page(
      '', $plugin->title ?? $plugin->name, '', 'manage_options',
      get_setup_slug($plugin),
      function () use ($plugin) { render_wizard($plugin); }
    );
    // Hidden pages resolve no page title, and WP trunk's admin-header now
    // deprecation-warns on strip_tags(null) — which also breaks any header()
    // sent later in the request. Supply the title before admin-header runs.
    if ($hook) {
      add_action('load-' . $hook, function () use ($plugin) {
        $GLOBALS['title'] = ($plugin->title ?? $plugin->name) . ' setup';
        // The wizard is a takeover: the CSS hides the admin bar, but WP still
        // emits its markup and a remote gravatar request for it. Inside
        // wp-admin the `show_admin_bar` filter is never consulted
        // (is_admin_bar_showing() returns true outright for is_admin()), so
        // the render action is what has to go.
        remove_action('in_admin_header', 'wp_admin_bar_render', 0);
      });
    }
  });

  // While setup is pending, every licence door leads to the wizard: the
  // updater's plugins-row "Activate License" link is filtered here so the
  // reader never lands on a bare settings field with no context. Once the
  // plan is empty, the filter stands down and licence management belongs to
  // the settings page again — one licence surface at a time.
  add_filter('tangible_updater_activation_url', function ($url, $for_plugin) use ($plugin, $name) {
    if (($for_plugin->name ?? null) !== $name) return $url;
    $plan = onboarding\resolve_plan($name, build_facts($name));
    return empty($plan['steps']) ? $url : get_setup_url($plugin);
  }, 10, 2);

  // Resumable re-entry: the playbook's one universal finding. Dismissible,
  // and it re-resolves each load, so finishing setup removes it without a
  // dismissal ever being recorded.
  // Outbox retry: any visit to the setup page redelivers unsynced consent
  // answers (server side is idempotent, so over-triggering costs nothing).
  add_action('admin_init', function () use ($plugin) {
    if (($_GET['page'] ?? '') !== get_setup_slug($plugin)) return;
    if (!current_user_can('manage_options')) return;
    attempt_consent_sync($plugin);
  });

  add_action('admin_init', function () use ($plugin, $name) {
    if (!current_user_can('manage_options')) return;
    if (($_GET['page'] ?? '') === get_setup_slug($plugin)) return;
    $notice_key = $name . '-setup-pending';
    if (framework\is_admin_notice_dismissed($notice_key)) return;
    $plan = onboarding\resolve_plan($name, build_facts($name));
    if (empty($plan['steps'])) return;
    framework\register_admin_notice(function () use ($plugin, $plan, $notice_key) {
      $title = esc_html($plugin->title ?? $plugin->name);
      $url = esc_url(get_setup_url($plugin));
      $n = count($plan['steps']);
      echo "<div class=\"notice notice-info is-dismissible\" data-tangible-admin-notice=\"$notice_key\">"
         . "<p><strong>$title</strong> — setup has $n step" . ($n === 1 ? '' : 's') . " left. "
         . "<a href=\"$url\">Continue setup</a></p></div>";
    });
  });
}

/**
 * Continue/skip submission. Factored out of the admin-post hook so the
 * decision logic is testable without HTTP.
 *
 * Returns the step id that was recorded, or null when nothing was
 * (validation failure, unknown step, or a step that refused).
 */
function handle_step_submission($plugin_name, $post) {
  $step_id = sanitize_text_field($post['step'] ?? '');
  $do = $post['do'] ?? '';
  if ($step_id === '' || !in_array($do, ['continue', 'skip'], true)) return null;

  $facts = build_facts($plugin_name);
  $plan = onboarding\resolve_plan($plugin_name, $facts);

  // Only the CURRENT step accepts a submission — a stale tab posting an
  // earlier step must not re-run a handler whose answer already exists.
  if ($plan['current'] !== $step_id) return null;

  $step = null;
  foreach ($plan['steps'] as $s) if ($s['id'] === $step_id) { $step = $s; break; }

  if ($do === 'skip') {
    if (!$step['skippable']) return null;
    // A step may declare 'on_skip' (callable) when skipping is itself a
    // decision with a consequence — "skip Connect" means "stay on the Local
    // backend", and that has to be RECORDED, not implied, or the promise in
    // the skip note quietly stops being true.
    if (is_callable($step['on_skip'] ?? null)) {
      $plugin = function_exists('tangible\\framework\\get_plugin')
        ? framework\get_plugin($plugin_name) : null;
      call_user_func($step['on_skip'], $plugin, $step);
    }
    onboarding\mark($plugin_name, $step_id, 'skipped');
    return $step_id;
  }

  if (is_callable($step['handle'] ?? null)) {
    $plugin = function_exists('tangible\\framework\\get_plugin')
      ? framework\get_plugin($plugin_name) : null;
    $ok = call_user_func($step['handle'], $plugin, $step);
    // A handler refuses two ways, and the shell treats them differently:
    //   false     — incomplete input; stay open, no message (the form says why)
    //   WP_Error  — a TEST failed; stay open and show the reason. This is the
    //               whole infra behind "a credentials step ends in a test, not
    //               a save": the shell knows nothing about Algolia or Stripe,
    //               only that a handler may decline with an explanation.
    if ($ok === false) return null;
    if (is_wp_error($ok)) {
      set_transient(step_error_key($plugin_name), $ok->get_error_message(), 60);
      return null;
    }
  }
  onboarding\mark($plugin_name, $step_id, 'done');
  return $step_id;
}

/** Per-user, so two admins onboarding two sites in parallel don't cross wires. */
function step_error_key($plugin_name) {
  return 'tangible_onboarding_error__' . $plugin_name . '__' . get_current_user_id();
}

add_action('admin_post_tangible_onboarding_step', function () {
  if (!current_user_can('manage_options')) wp_die('Nope');
  check_admin_referer('tangible_onboarding_step');
  $plugin_name = sanitize_text_field($_POST['plugin'] ?? '');
  handle_step_submission($plugin_name, $_POST);
  $plugin = framework\get_plugin($plugin_name);
  wp_safe_redirect($plugin ? get_setup_url($plugin) : admin_url());
  exit;
});

// ── rendering ──────────────────────────────────────────────────────────────
//
// The frame is the Plugin Wizard design (Cristian, Figma 2026-08), rendered
// with the TUI wizard element catalog (@tangible/ui `Compositions/Wizard`):
//
//   top bar    the House mark + product lockup left, "Exit setup" right
//   stepper    horizontal, every step named, in its own white band —
//              done = success dot, current = filled pill, skipped = hollow
//              dot (the honesty device, folded into the strip), upcoming =
//              muted dot. Server-rendered .tui-stepper markup, so the TUI
//              component can hydrate over it without re-deciding anything.
//   well       tinted content well carrying one card; a step may declare
//              'aside' (callable) for the two-column facts-panel layout
//   footer     whisper left ("nothing is saved until you continue"),
//              dashed skip + outcome-named primary right (submit_label)
//
// Styling is the compiled TUI subset (onboarding/wizard.css — button,
// notice, stepper, option-card, checkbox, switch, progress, chip, inputs)
// plus the brand faces from tangible\design: Recoleta for the step heading,
// Space Mono for data, the native sans for sentences. Step BODIES keep core
// form controls unstyled in our panel — that is the archetype contract.
//
// v1 stays server-rendered on purpose: the markup is the TUI components'
// own DOM (classes and all), so the preact hydration layer can land on it
// later without a redesign.

/** The six-tile mark, filled to $filled; current tile in the host accent. */
function render_mark($filled, $current_at = -1, $size = 'md', $done = false) {
  $u = $size === 'lg' ? '9px' : ($size === 'sm' ? '5px' : '7px');
  echo '<span class="tgbl-mark" style="--u:' . $u . '" aria-hidden="true">';
  for ($i = 0; $i < 5; $i++) {
    $attr = $i === $current_at ? ' data-now' : ($i < $filled ? ' data-on' : '');
    echo "<i$attr></i>";
  }
  echo '<i' . ($done ? ' data-done' : '') . '></i></span>';
}

/**
 * The wizard's step strip — Cristian's Plugin Wizard geometry: a numbered
 * disc per step, a checkmark once done, and one progress bar under the row.
 *
 * TUI's own <Stepper> is the dot-rail variant (small marker, no numerals);
 * this numbered variant does not exist there yet, so the classes below are
 * wizard-local (.tgbl-steps) rather than pretending to be .tui-stepper DOM.
 * If TUI grows a `numbered` variant, this renderer is what it should emit.
 */
function render_stepper($rail, $current_id) {
  $check = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" '
    . 'stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    . '<path d="M20 6 9 17l-5-5"/></svg>';
  $total = count($rail);
  $reached = 0;
  foreach ($rail as $i => $r) {
    if (in_array($r['state'], ['done', 'skipped'], true)) $reached = $i + 1;
    if ($r['id'] === $current_id) { $reached = $i; break; }
  }
  // The bar fills to the middle of the current disc, so it reads as "here",
  // not "this step is finished".
  $pct = $total > 1 ? max(0, min(100, ($reached + 0.5) / $total * 100)) : 100;
  ?>
  <nav aria-label="Setup progress" class="tgbl-steps">
    <ol class="tgbl-steps__list">
      <?php foreach ($rail as $i => $r) :
        $state = $r['state'];
        $suffix = $state === 'done' ? 'complete' : ($state === 'skipped' ? 'skipped' : ''); ?>
        <li class="tgbl-steps__step" data-state="<?php echo esc_attr($state); ?>"
            <?php if ($r['id'] === $current_id) echo 'aria-current="step"'; ?>>
          <span class="tgbl-steps__disc" aria-hidden="true"><?php
            echo $state === 'done' ? $check : ($i + 1); ?></span>
          <span class="tgbl-steps__label"><?php echo esc_html(ucfirst($r['label'])); ?></span>
          <?php if ($suffix) : ?><span class="tui-visually-hidden">, <?php echo esc_html($suffix); ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <div class="tgbl-steps__track" aria-hidden="true">
      <span class="tgbl-steps__fill" style="width:<?php echo round($pct, 2); ?>%"></span>
    </div>
  </nav>
  <?php
}

/**
 * A selectable option card — the same DOM @tangible/ui's OptionCard emits,
 * over a native input the browser owns. Steps compose these for the Choice
 * and steward archetypes; the group wrapper is render_option_group().
 *
 * $args: name, value, title, checked, type (radio|checkbox), description,
 *        badge, meta, variant (card|row), bullets (string[])
 */
function render_option_card($args) {
  $a = wp_parse_args($args, [
    'type' => 'radio', 'variant' => 'card', 'checked' => false,
    'description' => '', 'badge' => '', 'meta' => '', 'bullets' => [],
  ]);
  $check_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10.687 16.567 18.14 3.99l1.72 1.02-8.547 14.423-7.382-5.111 1.138-1.644z" clip-rule="evenodd"/></svg>';
  $classes = 'tui-option-card' . ($a['variant'] === 'row' ? ' is-row' : '') . ($a['checked'] ? ' is-selected' : '');
  ?>
  <label class="<?php echo esc_attr($classes); ?>" data-tgbl-option>
    <input class="tui-option-card__input tui-visually-hidden"
           type="<?php echo esc_attr($a['type']); ?>"
           name="<?php echo esc_attr($a['name']); ?>"
           value="<?php echo esc_attr($a['value']); ?>"
           <?php checked($a['checked']); ?> />
    <span class="tui-option-card__control" aria-hidden="true"><span class="tui-icon"><?php echo $check_svg; ?></span></span>
    <span class="tui-option-card__body">
      <span class="tui-option-card__heading">
        <span class="tui-option-card__title"><?php echo esc_html($a['title']); ?></span>
        <?php if ($a['badge']) : ?><span class="tui-option-card__badge"><?php echo esc_html($a['badge']); ?></span><?php endif; ?>
      </span>
      <?php if ($a['description']) : ?>
        <span class="tui-option-card__description"><?php echo esc_html($a['description']); ?></span>
      <?php endif; ?>
      <?php if ($a['variant'] === 'card' && $a['bullets']) : ?>
        <span class="tui-option-card__bullets">
          <?php foreach ($a['bullets'] as $b) : ?>
            <span class="tui-option-card__bullet"><span class="tui-icon"><?php echo $check_svg; ?></span><?php echo esc_html($b); ?></span>
          <?php endforeach; ?>
        </span>
      <?php endif; ?>
    </span>
    <?php if ($a['meta']) : ?><span class="tui-option-card__meta"><?php echo esc_html($a['meta']); ?></span><?php endif; ?>
  </label>
  <?php
}

/** Group wrapper for option cards. $layout: grid|stack. */
function render_option_group($aria_label, $render_cards, $layout = 'stack', $single = true) {
  ?>
  <div role="<?php echo $single ? 'radiogroup' : 'group'; ?>"
       aria-label="<?php echo esc_attr($aria_label); ?>"
       class="tui-option-card-group<?php echo $layout === 'stack' ? ' is-stack' : ''; ?>">
    <?php $render_cards(); ?>
  </div>
  <?php
}

function render_wizard($plugin) {
  $name = $plugin->name;
  $facts = build_facts($name);
  $plan = onboarding\resolve_plan($name, $facts);
  $step = $plan['steps'][0] ?? null;
  // The lockup strips the vendor prefix — the top bar already wears the mark.
  $short_title = esc_html(trim(str_ireplace(['tangible ', ' plugin'], ['', ''], $plugin->title ?? $name)));

  $total = count($plan['rail']);
  $position = 0; $done = 0;
  foreach ($plan['rail'] as $idx => $r) {
    if ($r['id'] === $plan['current']) $position = $idx + 1;
    if (in_array($r['state'], ['done', 'skipped'], true)) $done++;
  }
  $all_done = !$step;

  // The skip strip: one sentence per skipped-with-note step — the stepper
  // shows THAT something was skipped, this line says WHY.
  $skip_sentences = [];
  foreach ($plan['rail'] as $r) {
    if ($r['state'] === 'skipped' && !empty($r['note'])) {
      $skip_sentences[] = '<strong>' . esc_html(ucfirst($r['label'])) . '</strong> was skipped — '
        . esc_html($r['note']) . '.';
    }
  }
  // module_url carries no trailing slash and may answer with the wrong
  // scheme behind SSL proxies — normalize both or the stylesheet 404s.
  $css_url = set_url_scheme(trailingslashit(framework\module_url(__FILE__)) . 'wizard.css')
    . '?v=' . rawurlencode(framework::$state->version ?? '1');
  ?>
  <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>" />
  <style>
    /* ------------------------------------------------------------------
       Wizard shell — Cristian's Plugin Wizard geometry (960 modal, 40
       padding, 16 radius) on the native admin type stack.

       No brand faces here. Recoleta / League Spartan / Space Mono are
       licensed for Tangible's own sites, not for redistribution inside a
       plugin ZIP, so the wizard that ships to customer sites uses the
       system stack only — which is also what the design specifies.
       ------------------------------------------------------------------ */

    /* Full-screen takeover: the wizard owns the page (Figma: no admin chrome). */
    html.wp-toolbar { padding-top: 0 !important; }
    #wpadminbar, #adminmenumain, #adminmenuback, #wpfooter,
    .notice, .update-nag, .updated, .error:not(.tgbl-keep) { display: none !important; }
    #wpcontent, #wpbody-content { margin-left: 0 !important; padding: 0 !important; float: none; }
    #wpbody-content .tgbl-wizard { min-height: 100vh; }

    /* ------------------------------------------------------------------
       Token pinning.

       The host plugin ships its own WP-native TUI theme at
       `.wp-admin .tui-interface` (2px radii, 13px controls, and
       --tui-color-bg: #f0f0f0 — which turns every "white" surface grey).
       That is right for its settings screens and wrong for a full-page
       takeover, and it outranks :where(.tui-interface), so the wizard
       has to state its own surfaces at matching specificity.

       The accent is deliberately NOT pinned: it stays the WP admin theme
       colour, which is what the design draws and what the rest of the
       plugin already wears.
       ------------------------------------------------------------------ */
    .wp-admin .tui-interface.tgbl-wizard {
      --tui-color-bg: #fff;
      --tui-color-bg-surface: #fff;
      --tui-color-bg-elevated: #fff;
      --tui-color-bg-muted: #f6f7f7;
      --tui-color-fg: #1e1e1e;
      --tui-color-fg-secondary: #3c434a;
      --tui-color-fg-muted: #646970;
      --tui-color-border: #dcdcde;
      --tui-color-divider: #e6e7e8;
      --tui-color-fill: #eef0f1;
      --tui-color-fill-subtle: #f0f0f1;
      /* Selection reads as a tint, not a fill — the host theme's
         primary-subtlest (#c5d9ed) is a mid blue and swamps a selected row. */
      --tui-theme-primary-subtlest: #eef2ff;
      --tui-theme-primary-subtle: #dbe3fe;
      --tui-radius-md: 10px;
      --tui-button-radius: 6px;
      --tui-input-radius: 6px;
      --tui-select-trigger-radius: 6px;
      --tui-select-content-radius: 8px;
      --tui-card-radius: 12px;
      --tui-notice-radius: 8px;
      --tui-typography-size: 14px;
      --tui-control-height-md: 40px;
      --tui-control-height-lg: 44px;
      --tui-control-font-size-md: 14px;
      --tui-control-font-size-lg: 14px;
      --tui-button-font-size: 14px;
      --tui-button-font-weight: 500;
    }

    .tgbl-wizard {
      --tgbl-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
        "Helvetica Neue", Arial, sans-serif;
      --tgbl-font-data: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
      --tgbl-modal: 960px;
      --tgbl-pad: 40px;
      display: flex; flex-direction: column;
      background: var(--tui-color-bg-muted);
      color: var(--tui-color-fg);
      font-family: var(--tgbl-font);
      font-size: 14px; line-height: 1.5;
      -webkit-font-smoothing: antialiased;
    }
    .tgbl-wizard *, .tgbl-wizard *::before, .tgbl-wizard *::after { box-sizing: border-box; }

    /* Top strip — the design's breadcrumb line, plus the exit affordance. */
    .tgbl-wizard__topbar { display:flex; align-items:center; gap:10px;
      padding: 14px 28px; background: var(--tui-color-bg);
      border-bottom: 1px solid var(--tui-color-divider); }
    .tgbl-wizard__lockup { font-size: 13px; font-weight: 600; letter-spacing: .04em;
      text-transform: uppercase; }
    .tgbl-wizard__lockup span { color: var(--tui-color-fg-muted); font-weight: 500; }
    .tgbl-wizard__exit { margin-left: auto; }

    /* The well holds one modal, centred, at the design's fixed measure. */
    .tgbl-wizard__well { flex: 1; padding: 32px 24px 56px;
      display: flex; justify-content: center; align-items: flex-start; }
    .tgbl-wizard__modal { width: 100%; max-width: var(--tgbl-modal);
      background: var(--tui-color-bg); border: 1px solid var(--tui-color-divider);
      border-radius: 16px; padding: var(--tgbl-pad);
      box-shadow: 0 0.7px 1px rgba(0,0,0,.05), 0 2.7px 3.8px -0.2px rgba(0,0,0,.06);
      display: flex; flex-direction: column; gap: 32px; }
    /* Steps author their own markup with a margin-bottom convention, so the
       body is a block with a default flow rhythm rather than a flex gap —
       a gap would stack on top of those margins and pull the heading block
       apart. Anything with its own inline margin still wins. */
    .tgbl-wizard__body { display: block; }
    /* .step-intro is excluded: it belongs to the heading above it and keeps
       its own tight 8px, in one-column and two-column steps alike. */
    .tgbl-wizard__body > * + *:not(.step-intro),
    .tgbl-wizard .tgbl-cols > .main > * + *:not(.step-intro),
    .tgbl-wizard .tgbl-flow > * + *:not(.step-intro) { margin-top: 20px; }
    /* A trailing note after a card group is a caption for it, not a new
       block — but it still needs air, or it reads as card overflow. */
    .tgbl-wizard__body > .tui-option-card-group + .whisper,
    .tgbl-wizard__body > .tui-option-card-group + p { margin-top: 18px; }
    .tgbl-wizard .tgbl-flow > [hidden] { display: none; }

    /* Step strip ---------------------------------------------------------- */
    .tgbl-steps { display: flex; flex-direction: column; gap: 12px; }
    .tgbl-steps__list { display: flex; flex-wrap: wrap; gap: 8px 20px;
      margin: 0; padding: 0; list-style: none; }
    .tgbl-steps__step { display: flex; align-items: center; gap: 7px; margin: 0;
      font-size: 13px; line-height: 1.2; color: var(--tui-color-fg-muted); }
    .tgbl-steps__disc { flex: none; width: 21px; height: 21px; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 600; font-variant-numeric: tabular-nums;
      background: var(--tui-color-fill); color: var(--tui-color-fg-muted); }
    .tgbl-steps__step[data-state="done"] { color: var(--tui-color-fg); }
    .tgbl-steps__step[data-state="done"] .tgbl-steps__disc {
      background: var(--tui-theme-success-subtle); color: var(--tui-theme-success-stronger); }
    .tgbl-steps__step[data-state="skipped"] .tgbl-steps__disc {
      background: transparent; color: var(--tui-color-fg-muted);
      box-shadow: inset 0 0 0 1px var(--tui-color-border); }
    .tgbl-steps__step[aria-current="step"] { color: var(--tui-color-fg); font-weight: 600; }
    .tgbl-steps__step[aria-current="step"] .tgbl-steps__disc {
      background: var(--tui-theme-primary-base); color: var(--tui-color-fg-on-accent); }
    .tgbl-steps__track { height: 4px; border-radius: 999px;
      background: var(--tui-color-fill-subtle); overflow: hidden; }
    .tgbl-steps__fill { display: block; height: 100%; border-radius: inherit;
      background: var(--tui-theme-primary-base);
      transition: width var(--tui-motion-duration) var(--tui-motion-timing); }

    /* The skipped-step explanations, as one quiet line under the strip. */
    .tgbl-wizard__skipstrip { font-size: 13px; color: var(--tui-color-fg-muted);
      margin: -12px 0 0; }
    .tgbl-wizard__skipstrip a { color: var(--tui-theme-primary-base); }

    /* Type scale ---------------------------------------------------------- */
    .tgbl-wizard h2 { font-family: inherit; font-size: 30px; font-weight: 700;
      line-height: 1.2; letter-spacing: -.018em; margin: 0; padding: 0;
      color: var(--tui-color-fg); }
    .tgbl-wizard .step-intro { font-size: 15px; line-height: 1.55;
      color: var(--tui-color-fg-muted); margin: 8px 0 0; max-width: 76ch; }
    .tgbl-wizard p { font-size: 14px; line-height: 1.55; margin: 0; }
    /* Section labels open a block, so they carry the air above and a tight
       gap below — steps no longer hand-tune this per instance. */
    .tgbl-wizard .lbl, .tgbl-wizard .eyebrow { display: block; font-size: 12px;
      font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
      color: var(--tui-color-fg-muted); margin: 28px 0 10px; }
    .tgbl-wizard__body > .lbl:first-child { margin-top: 0; }
    .tgbl-wizard .lbl + *, .tgbl-wizard .eyebrow + * { margin-top: 0; }

    /* A labelled field: <p class="tgbl-field"><label>Name<br/><input/></label></p> */
    .tgbl-wizard .tgbl-field { margin-top: 16px; }
    .tgbl-wizard .tgbl-field label { font-weight: 600; font-size: 13px; }
    .tgbl-wizard .tgbl-field input, .tgbl-wizard .tgbl-field select { margin-top: 6px; }
    .tgbl-wizard .tgbl-field__hint { display: block; margin-top: 6px; }

    /* Consent question pairs (framework steps.php) */
    .tgbl-wizard .tgbl-answer { margin-top: 24px; }
    .tgbl-wizard .tgbl-answer__q { font-size: 15px; font-weight: 600; margin: 0 0 4px; }
    .tgbl-wizard .tgbl-answer__d { font-size: 13px; color: var(--tui-color-fg-muted);
      margin: 0 0 12px; }
    .tgbl-wizard .tgbl-factkey { padding: 3px 16px 3px 0; font-size: 11px;
      margin: 0; letter-spacing: .05em; }
    .tgbl-wizard .whisper { font-size: 13px; color: var(--tui-color-fg-muted); }
    .tgbl-wizard .code { font-family: var(--tgbl-font-data); font-size: 13px; }
    .tgbl-wizard label { font-size: 14px; }
    .tgbl-wizard strong, .tgbl-wizard b { font-weight: 600; }
    .tgbl-wizard a { color: var(--tui-theme-primary-base); }
    /* WP admin underlines anchors; a link-as-button must not wear it. */
    .tgbl-wizard a.tui-button, .tgbl-wizard a.tui-button:hover { text-decoration: none; }

    /* Fields fill the measure — a 300px input inside a 880px card is the
       misalignment the design never has. */
    .tgbl-wizard input[type=text], .tgbl-wizard input[type=password],
    .tgbl-wizard input[type=email], .tgbl-wizard input[type=url],
    .tgbl-wizard select, .tgbl-wizard textarea { width: 100%; max-width: 100%;
      font-family: inherit; font-size: 14px; }
    .tgbl-wizard .tui-field, .tgbl-wizard .tui-input-wrap { width: 100%; }

    /* Steps emit .tgbl-dbl between the heading block and the controls.
       The design separates those with air, not a rule, so this is now a
       spacer — the double hairline was a leftover from the DDD artifacts. */
    .tgbl-dbl { border: 0; height: 0; margin: 12px 0 0; }

    /* Footer sits inside the modal, above the fold of its own card. */
    .tgbl-wizard__footer { display: flex; align-items: center; gap: 14px;
      padding-top: 24px; border-top: 1px solid var(--tui-color-divider); }

    /* the mark (top bar brand element) */
    .tgbl-mark { --u:7px; display:inline-grid; gap:1px; flex:none;
      grid-template-columns:repeat(3,var(--u)); grid-template-rows:repeat(3,var(--u)); }
    .tgbl-mark i { display:block; border-radius:1px; background:#dcdcde; }
    .tgbl-mark i:nth-child(1){grid-area:1/1}.tgbl-mark i:nth-child(2){grid-area:1/2}
    .tgbl-mark i:nth-child(3){grid-area:1/3}.tgbl-mark i:nth-child(4){grid-area:2/1}
    .tgbl-mark i:nth-child(5){grid-area:2/3}.tgbl-mark i:nth-child(6){grid-area:3/2}
    .tgbl-mark i[data-on] { background:#9E9CF7; }
    .tgbl-mark i[data-now] { background:var(--tui-theme-primary-base); }
    .tgbl-mark i[data-done] { background:#FD9597; }

    /* Option cards — the design's card is roomier than TUI's default. */
    .tgbl-wizard .tui-option-card { --tui-option-card-padding: 18px;
      --tui-option-card-radius: 10px; }
    .tgbl-wizard .tui-option-card__title { font-size: 15px; }
    .tgbl-wizard .tui-option-card__description,
    .tgbl-wizard .tui-option-card__bullet { font-size: 13px; font-weight: 400; }
    .tgbl-wizard .tui-option-card-group { --tui-option-card-group-gap: 14px; }

    /* Build step — the design's progress panel, log and preview.
       These live here rather than inline in the step so the step markup
       carries no colours or measures of its own. */
    .tgbl-build__panel { border: 1px solid var(--tui-color-divider); border-radius: 10px;
      background: var(--tui-color-bg-surface); padding: 18px 20px; }
    .tgbl-build__head { display: flex; align-items: baseline; gap: 12px; }
    .tgbl-build__title { font-size: 14px; font-weight: 600; }
    .tgbl-build__pct { margin-left: auto; font-size: 13px; font-weight: 600;
      color: var(--tui-theme-primary-base); font-variant-numeric: tabular-nums; }
    .tgbl-build__track { margin-top: 12px; height: 8px; border-radius: 999px;
      background: var(--tui-color-fill-subtle); overflow: hidden; }
    .tgbl-build__fill { display: block; height: 100%; width: 0%; border-radius: inherit;
      background: var(--tui-theme-primary-base); transition: width .4s ease; }
    .tgbl-build__count { margin-top: 10px; font-size: 13px; color: var(--tui-color-fg-muted);
      font-variant-numeric: tabular-nums; }
    .tgbl-build__log { list-style: none; margin: 0; padding: 14px 16px; border-radius: 10px;
      background: #1d2327; color: #c3c4c7; font-family: var(--tgbl-font-data);
      font-size: 12.5px; line-height: 1.9; max-height: 190px; overflow-y: auto; }
    .tgbl-build__log li { margin: 0; }
    .tgbl-build__sample { border: 1px solid var(--tui-color-divider); border-radius: 10px;
      padding: 16px 20px; }
    .tgbl-build__sample .lbl { margin-top: 0; }

    /* Done step — the design's centred finish: a mark, the claim, the two
       ways onward. Anything conditional (staging paused, global search)
       stays left-aligned below, because a notice is not a celebration. */
    .tgbl-done { text-align: center; padding: 8px 0 4px; }
    .tgbl-done__mark { display: inline-flex; align-items: center; justify-content: center;
      width: 60px; height: 60px; border-radius: 50%; margin-bottom: 20px;
      background: var(--tui-theme-success-subtle); color: var(--tui-theme-success-stronger); }
    .tgbl-done .step-intro { margin-left: auto; margin-right: auto; }
    .tgbl-done__actions { display: flex; flex-direction: column; align-items: center;
      gap: 10px; margin-top: 24px; }
    .tgbl-done__actions .tui-button { min-width: 280px; justify-content: center; }

    .tgbl-wizard .tgbl-notice__p { margin: 0 0 10px; }
    /* No margin reset here — that would out-specify the body's flow rhythm
       and jam the list under its heading. */
    .tgbl-wizard .tgbl-list { list-style: disc; padding-left: 20px; font-size: 14px;
      line-height: 1.7; margin-bottom: 0; }
    .tgbl-wizard .tgbl-list li + li { margin-top: 4px; }

    /* the aside slot — content + facts panel, unchanged contract */
    .tgbl-cols { display:flex; gap:32px; align-items:flex-start; }
    .tgbl-cols .main { flex:1 1 auto; min-width:0; }
    .tgbl-aside { flex:0 0 240px; border-left:1px solid var(--tui-color-divider); padding-left:24px; }
    .tgbl-aside dl { display:grid; grid-template-columns:1fr auto; gap:6px 12px; font-size:13px; margin:0; }
    .tgbl-aside dt { font-size:12px; font-weight:600; color:var(--tui-color-fg-muted); }
    .tgbl-aside dd { margin:0; text-align:right; font-variant-numeric: tabular-nums;
      font-weight:600; font-size:13px; }

    /* the schematic — hairlines and boxes, no illustration */
    .tgbl-schem { display:flex; align-items:center; margin:8px 0 4px; }
    .tgbl-schem .snode { border:1px solid var(--tui-color-border); border-radius:8px;
      background:var(--tui-color-bg-surface); padding:10px 14px; min-width:110px;
      text-align:center; font-size:13px; }
    .tgbl-schem .snode b { display:block; font-size:13px; font-weight:600; }
    .tgbl-schem .snode .sk { font-size:11px; font-weight:600; color:var(--tui-color-fg-muted); }
    .tgbl-schem .snode[data-on] { border-color:var(--tui-theme-primary-base);
      box-shadow:inset 0 0 0 1px var(--tui-theme-primary-base); background:var(--tui-color-bg); }
    .tgbl-schem .snode[data-ghost] { border-style:dashed; opacity:.55; }
    .tgbl-schem .swire { flex:1 1 0; min-width:26px; height:1.5px; background:#9E9CF7; position:relative; }
    .tgbl-schem .swire em { position:absolute; top:-16px; left:50%; transform:translateX(-50%);
      font-style:normal; font-size:11px; font-weight:600;
      color:var(--tui-color-fg-muted); white-space:nowrap; }

    .tgbl-skip { color: var(--tui-color-fg-muted); background: none; border: 0;
      cursor: pointer; padding: 0; font-size: 13px; font-family: inherit;
      text-decoration: underline; text-underline-offset: 3px;
      text-decoration-color: var(--tui-color-border); }
    .tgbl-skip:hover { color: var(--tui-color-fg); text-decoration-color: currentColor; }

    @media (max-width: 782px) {
      .tgbl-wizard { --tgbl-pad: 24px; }
      .tgbl-wizard__well { padding: 20px 14px 40px; }
      .tgbl-wizard h2 { font-size: 24px; }
      .tgbl-cols { flex-direction: column; }
      .tgbl-aside { flex: 1 1 auto; border-left: 0; border-top: 1px solid var(--tui-color-divider);
        padding-left: 0; padding-top: 16px; width: 100%; }
    }
  </style>
  <div class="tui-interface tgbl-wizard">
    <header class="tgbl-wizard__topbar">
      <?php render_mark($done, $position > 0 ? $position - 1 : -1, 'md', $all_done); ?>
      <span class="tgbl-wizard__lockup">Tangible <span><?php echo $short_title; ?></span></span>
      <a class="tui-button is-size-sm is-style-ghost is-theme-secondary tgbl-wizard__exit"
         href="<?php echo esc_url(admin_url('admin.php?page=tangible-home')); ?>">Exit setup</a>
    </header>

    <?php if ($all_done) : ?>
      <main class="tgbl-wizard__well">
        <div class="tgbl-wizard__modal">
          <?php render_stepper($plan['rail'], $plan['current']); ?>
          <div class="tgbl-wizard__body">
            <h2>Nothing left to ask.</h2>
            <p class="step-intro">Everything is either configured or already on file.</p>
          </div>
          <footer class="tgbl-wizard__footer">
            <span style="flex:1"></span>
            <a class="tui-button is-theme-primary"
               href="<?php echo esc_url(admin_url('admin.php?page=tangible-home')); ?>">Go to Tangible Home</a>
          </footer>
        </div>
      </main>
    <?php else : ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('tangible_onboarding_step'); ?>
        <input type="hidden" name="action" value="tangible_onboarding_step" />
        <input type="hidden" name="plugin" value="<?php echo esc_attr($name); ?>" />
        <input type="hidden" name="step" value="<?php echo esc_attr($step['id']); ?>" />

        <main class="tgbl-wizard__well">
          <div class="tgbl-wizard__modal">
            <?php render_stepper($plan['rail'], $plan['current']); ?>

            <?php if ($skip_sentences) : ?>
              <p class="tgbl-wizard__skipstrip"><?php echo implode(' ', $skip_sentences); ?>
                <a href="https://tangible.one/account" target="_blank" rel="noopener">Manage in your account</a>.</p>
            <?php endif; ?>

            <?php $error = get_transient(step_error_key($name));
            if ($error) { delete_transient(step_error_key($name)); } ?>
            <?php if ($error) : ?>
              <div class="tui-notice is-theme-danger tgbl-keep" role="alert">
                <div class="tui-notice__inner"><div class="tui-notice__body"><?php echo esc_html($error); ?></div></div>
              </div>
            <?php endif; ?>

            <div class="tgbl-wizard__body">
              <?php
              $render = function () use ($step, $plugin, $facts) {
                if (is_callable($step['render'])) call_user_func($step['render'], $plugin, $facts, $step);
                else echo '<h2>' . esc_html($step['id']) . '</h2>';
              };
              if (is_callable($step['aside'] ?? null)) : ?>
                <div class="tgbl-cols">
                  <div class="main"><?php $render(); ?></div>
                  <aside class="tgbl-aside"><?php call_user_func($step['aside'], $plugin, $facts, $step); ?></aside>
                </div>
              <?php else : $render(); endif; ?>
            </div>

            <footer class="tgbl-wizard__footer">
              <span class="whisper">Nothing is saved until you continue.</span>
              <span style="flex:1"></span>
              <?php if ($step['skippable']) : ?>
                <button class="tgbl-skip" type="submit" name="do" value="skip">Skip this step</button>
              <?php endif; ?>
              <button class="tui-button is-theme-primary" type="submit" name="do" value="continue"><?php
                echo esc_html($step['submit_label'] ?? 'Continue'); ?></button>
            </footer>
          </div>
        </main>
      </form>
    <?php endif; ?>
  </div>
  <script>
  /* is-selected follows the native input without a framework — one delegated
     listener covers every option card the page renders. */
  document.addEventListener('change', function (e) {
    var input = e.target.closest && e.target.closest('.tui-option-card__input');
    if (!input) return;
    document.querySelectorAll('.tui-option-card__input[name="' + input.name + '"]').forEach(function (i) {
      var card = i.closest('[data-tgbl-option]');
      if (card) card.classList.toggle('is-selected', i.checked);
    });
  });
  </script>
  <?php
}
