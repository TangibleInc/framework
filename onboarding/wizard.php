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
  // The control's shape follows the input type, not the card variant: a tick
  // in a square says "pick as many as you like", a dot in a circle says "pick
  // one". TUI's own is-row variant squares the control either way, which
  // makes every single-choice row lie about itself — hence is-radio here.
  $classes = 'tui-option-card'
    . ($a['type'] === 'radio' ? ' is-radio' : ' is-checkbox')
    . ($a['variant'] === 'row' ? ' is-row' : '')
    . ($a['checked'] ? ' is-selected' : '');
  ?>
  <label class="<?php echo esc_attr($classes); ?>" data-tgbl-option>
    <input class="tui-option-card__input tui-visually-hidden"
           type="<?php echo esc_attr($a['type']); ?>"
           name="<?php echo esc_attr($a['name']); ?>"
           value="<?php echo esc_attr($a['value']); ?>"
           <?php checked($a['checked']); ?> />
    <span class="tui-option-card__control" aria-hidden="true"><?php
    if ($a['type'] !== 'radio') : ?><span class="tui-icon"><?php echo $check_svg; ?></span><?php endif; ?></span>
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

/**
 * A toggle row — "index this, or don't".
 *
 * The design (Cristian's Index frame) puts a switch on the right of a titled
 * row, with the count beside it, and the same row tinted and two-up when what
 * it describes was detected rather than always there. A box with a tick reads
 * as "add this to a list"; a switch reads as "this is on", which is the
 * truthful shape for a thing that either indexes or does not.
 *
 * TUI's <Switch> is a <button role="switch"> that needs JS to hold its state.
 * This renders inside a plain form, so the control underneath is a native
 * checkbox wearing a track and a thumb: it submits, it keyboard-focuses, and
 * it still works with JS off.
 *
 * $args: name, value, title, checked, description, meta, note, tone, count
 */
function render_toggle_row($args) {
  $a = wp_parse_args($args, [
    'checked' => false, 'description' => '', 'meta' => '', 'note' => '',
    'tone' => '', 'count' => null, 'value' => '1',
  ]);
  // Title, description, meta and control are siblings rather than nested, so
  // each variant can place them with grid areas — the two-up card wants the
  // control top-right, the full-width row wants it centred at the end, and no
  // amount of ordering reaches into a wrapper to do that.
  $classes = 'tgbl-toggle-row'
    . ($a['tone'] ? ' is-tone-' . $a['tone'] : '')
    . ($a['description'] ? ' has-desc' : '')
    . ($a['checked'] ? ' is-on' : '');
  ?>
  <label class="<?php echo esc_attr($classes); ?>">
    <span class="tgbl-toggle-row__title"><?php echo esc_html($a['title']);
      if ($a['note']) : ?> <em class="tgbl-toggle-row__note"><?php
        echo esc_html($a['note']); ?></em><?php endif; ?></span>
    <?php if ($a['description']) : ?>
      <span class="tgbl-toggle-row__desc"><?php echo esc_html($a['description']); ?></span>
    <?php endif; ?>
    <?php if ($a['meta']) : ?>
      <span class="tgbl-toggle-row__meta"><?php echo esc_html($a['meta']); ?></span>
    <?php endif; ?>
    <input class="tgbl-toggle" type="checkbox"
           name="<?php echo esc_attr($a['name']); ?>"
           value="<?php echo esc_attr($a['value']); ?>"
           <?php if ($a['count'] !== null) : ?>data-count="<?php echo (int) $a['count']; ?>"<?php endif; ?>
           <?php checked($a['checked']); ?> />
  </label>
  <?php
}

/** Wrapper for toggle rows. $cols: 1 (full-width rows) or 2 (the tinted pair). */
function render_toggle_group($aria_label, $render_rows, $cols = 1) {
  ?>
  <div role="group" aria-label="<?php echo esc_attr($aria_label); ?>"
       class="tgbl-toggle-group<?php echo (int) $cols === 2 ? ' is-cols-2' : ''; ?>">
    <?php $render_rows(); ?>
  </div>
  <?php
}

/**
 * Group wrapper for option cards.
 *
 * $layout: 'stack' (one per row), 'grid' (auto-fit), or an integer column
 * count — short choices read as a row of siblings, not a vertical list.
 */
function render_option_group($aria_label, $render_cards, $layout = 'stack', $single = true) {
  $class = 'tui-option-card-group';
  if ($layout === 'stack') $class .= ' is-stack';
  elseif (is_int($layout) || ctype_digit((string) $layout)) $class .= ' is-cols-' . (int) $layout;
  ?>
  <div role="<?php echo $single ? 'radiogroup' : 'group'; ?>"
       aria-label="<?php echo esc_attr($aria_label); ?>"
       class="<?php echo esc_attr($class); ?>">
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
      --tui-typography-size: 13px;
      --tui-typography-size-sm: 13px;
      --tui-typography-size-xs: 12px;
      --tui-control-height-md: 36px;
      --tui-control-height-lg: 40px;
      --tui-control-font-size-md: 13px;
      --tui-control-font-size-lg: 13px;
      --tui-button-font-size: 13px;
      --tui-button-font-size-sm: 13px;
      --tui-button-font-weight: 600;
    }

    .tgbl-wizard {
      --tgbl-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
        "Helvetica Neue", Arial, sans-serif;
      --tgbl-font-data: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
      --tgbl-modal: 960px;
      --tgbl-pad: 40px;

      /* ----------------------------------------------------------------
         Seven roles, and nothing outside them.

         An earlier pass left eleven different size/weight pairs on a
         single step — some from here, some from TUI's component tokens,
         some from the host plugin's WP-native override. That is what
         "the font feels weird" is: no ramp, just accidents. Every rule
         below spends one of these and none invents its own number.

         The base is 13px because this lives inside wp-admin, which is
         13px; 14 read oversized against the screens either side of it.
         ---------------------------------------------------------------- */
      --tgbl-display: 28px;    /* step heading            700 */
      --tgbl-lede:    15px;    /* step subtitle           400 */
      --tgbl-title:   14px;    /* row / card titles       600 */
      --tgbl-body:    13px;    /* everything else         400 */
      --tgbl-meta:    13px;    /* counts, emphasis        600 */
      --tgbl-label:   12px;    /* section labels          600 caps */
      --tgbl-micro:   11px;    /* badges                  700 caps */

      display: flex; flex-direction: column;
      background: var(--tui-color-bg-muted);
      color: var(--tui-color-fg);
      font-family: var(--tgbl-font);
      font-size: var(--tgbl-body); line-height: 1.5;
      -webkit-font-smoothing: antialiased;
    }
    .tgbl-wizard *, .tgbl-wizard *::before, .tgbl-wizard *::after { box-sizing: border-box; }

    /* Top strip — the design's breadcrumb line, plus the exit affordance. */
    .tgbl-wizard__topbar { display:flex; align-items:center; gap:10px;
      padding: 14px 28px; background: var(--tui-color-bg);
      border-bottom: 1px solid var(--tui-color-divider); }
    .tgbl-wizard__lockup { font-size: var(--tgbl-label); font-weight: 600;
      letter-spacing: .06em; text-transform: uppercase; }
    .tgbl-wizard__lockup span { color: var(--tui-color-fg-muted); font-weight: 600; }
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
      font-size: var(--tgbl-body); line-height: 1.2; color: var(--tui-color-fg-muted); }
    .tgbl-steps__disc { flex: none; width: 21px; height: 21px; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: var(--tgbl-micro); font-weight: 600; font-variant-numeric: tabular-nums;
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
    .tgbl-wizard__skipstrip { font-size: var(--tgbl-body); color: var(--tui-color-fg-muted);
      margin: -12px 0 0; }
    .tgbl-wizard__skipstrip a { color: var(--tui-theme-primary-base); }

    /* Type scale ---------------------------------------------------------- */
    .tgbl-wizard h2 { font-family: inherit; font-size: var(--tgbl-display); font-weight: 700;
      line-height: 1.22; letter-spacing: -.015em; margin: 0; padding: 0;
      color: var(--tui-color-fg); }
    .tgbl-wizard .step-intro { font-size: var(--tgbl-lede); line-height: 1.55;
      color: var(--tui-color-fg-muted); margin: 8px 0 0; max-width: 76ch; }
    .tgbl-wizard p { font-size: var(--tgbl-body); line-height: 1.6; margin: 0; }
    /* Section labels open a block, so they carry the air above and a tight
       gap below — steps no longer hand-tune this per instance. */
    .tgbl-wizard .lbl, .tgbl-wizard .eyebrow { display: block; font-size: var(--tgbl-label);
      font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
      color: var(--tui-color-fg-muted); margin: 28px 0 10px; }
    .tgbl-wizard__body > .lbl:first-child { margin-top: 0; }
    .tgbl-wizard .lbl + *, .tgbl-wizard .eyebrow + * { margin-top: 0; }

    /* A labelled field: <p class="tgbl-field"><label>Name<br/><input/></label></p> */
    .tgbl-wizard .tgbl-field { margin-top: 16px; }
    .tgbl-wizard .tgbl-field label { font-weight: 600; font-size: var(--tgbl-meta); }
    .tgbl-wizard .tgbl-field input, .tgbl-wizard .tgbl-field select { margin-top: 6px; }
    .tgbl-wizard .tgbl-field__hint { display: block; margin-top: 6px; }

    /* Consent question pairs (framework steps.php) */
    .tgbl-wizard .tgbl-answer { margin-top: 24px; }
    .tgbl-wizard .tgbl-answer__q { font-size: var(--tgbl-title); font-weight: 600; margin: 0 0 4px; }
    .tgbl-wizard .tgbl-answer__d { font-size: var(--tgbl-body); color: var(--tui-color-fg-muted);
      margin: 0 0 12px; }
    .tgbl-wizard .tgbl-factkey { padding: 3px 16px 3px 0; font-size: var(--tgbl-label);
      margin: 0; letter-spacing: .05em; }
    .tgbl-wizard .whisper { font-size: var(--tgbl-body); color: var(--tui-color-fg-muted); }
    .tgbl-wizard .code { font-family: var(--tgbl-font-data); font-size: var(--tgbl-body); }
    .tgbl-wizard label { font-size: var(--tgbl-body); }
    .tgbl-wizard strong, .tgbl-wizard b { font-weight: 600; }
    .tgbl-wizard a { color: var(--tui-theme-primary-base); }
    /* WP admin underlines anchors; a link-as-button must not wear it. */
    .tgbl-wizard a.tui-button, .tgbl-wizard a.tui-button:hover { text-decoration: none; }

    /* Fields fill the measure — a 300px input inside a 880px card is the
       misalignment the design never has. */
    .tgbl-wizard input[type=text], .tgbl-wizard input[type=password],
    .tgbl-wizard input[type=email], .tgbl-wizard input[type=url],
    .tgbl-wizard select, .tgbl-wizard textarea { width: 100%; max-width: 100%;
      font-family: inherit; font-size: var(--tgbl-body); }
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
    .tgbl-wizard .tui-option-card { --tui-option-card-padding: 14px 16px;
      --tui-option-card-radius: 10px; --_control-size: 18px; }
    .tgbl-wizard .tui-option-card.is-row { --tui-option-card-padding: 12px 16px; }

    /* A tick in a square means "as many as you like"; a dot in a circle means
       "one of these". TUI's is-row squares the control whichever it is, so the
       shape is restated here from the input type the card actually holds. */
    .tgbl-wizard .tui-option-card.is-radio .tui-option-card__control {
      border-radius: 999px; background: var(--tui-color-bg);
      border-color: var(--tui-color-border); }
    .tgbl-wizard .tui-option-card.is-radio.is-selected .tui-option-card__control {
      background: var(--tui-color-bg); border-color: var(--tui-theme-primary-base);
      box-shadow: inset 0 0 0 5px var(--tui-theme-primary-base); }
    .tgbl-wizard .tui-option-card.is-checkbox .tui-option-card__control {
      border-radius: 4px; }

    /* Short choices belong side by side. A four-item pick spread down 880px
       of column is the "vertical instead of horizontal" complaint. */
    .tgbl-wizard .tui-option-card-group.is-cols-2 { grid-template-columns: repeat(2, 1fr); }
    .tgbl-wizard .tui-option-card-group.is-cols-3 { grid-template-columns: repeat(3, 1fr); }
    .tgbl-wizard .tui-option-card-group.is-cols-4 { grid-template-columns: repeat(4, 1fr); }
    @media (max-width: 860px) {
      .tgbl-wizard .tui-option-card-group[class*="is-cols-"] {
        grid-template-columns: repeat(2, 1fr); }
    }
    .tgbl-wizard .tui-option-card__title { font-size: var(--tgbl-title); font-weight: 600; }
    .tgbl-wizard .tui-option-card__description,
    .tgbl-wizard .tui-option-card__bullet { font-size: var(--tgbl-body); font-weight: 400; }
    .tgbl-wizard .tui-option-card__badge { font-size: var(--tgbl-micro); font-weight: 700; }
    .tgbl-wizard .tui-option-card__meta { font-size: var(--tgbl-meta); font-weight: 600;
      color: var(--tui-theme-primary-base); font-variant-numeric: tabular-nums; }
    .tgbl-wizard .tui-option-card-group { --tui-option-card-group-gap: 14px; }

    /* Toggle rows — the design's Index step. A native checkbox wearing a
       track and a thumb, so the form still works with JS off. */
    .tgbl-toggle-group { display: grid; gap: 10px; }
    .tgbl-toggle-group.is-cols-2 { grid-template-columns: repeat(2, 1fr); align-items: stretch; }
    @media (max-width: 860px) { .tgbl-toggle-group.is-cols-2 { grid-template-columns: 1fr; } }

    .tgbl-toggle-row { display: grid; cursor: pointer;
      grid-template-columns: 1fr auto auto; gap: 2px 14px; align-items: center;
      grid-template-areas: "title meta toggle";
      padding: 12px 16px; border: 1px solid var(--tui-color-border); border-radius: 10px;
      background: var(--tui-color-bg);
      transition: border-color var(--tui-motion-duration) var(--tui-motion-timing); }
    .tgbl-toggle-row.has-desc { grid-template-areas: "title meta toggle" "desc meta toggle"; }
    .tgbl-toggle-row:hover { border-color: var(--tui-color-fill-strong); }
    .tgbl-toggle-row__title { grid-area: title; font-size: var(--tgbl-title);
      font-weight: 600; line-height: 1.35; }
    .tgbl-toggle-row__note { font-style: normal; font-weight: 600;
      color: var(--tui-theme-primary-base); }
    .tgbl-toggle-row__desc { grid-area: desc; font-size: var(--tgbl-body);
      color: var(--tui-color-fg-muted); line-height: 1.45; }
    .tgbl-toggle-row__meta { grid-area: meta; font-size: var(--tgbl-meta); font-weight: 600;
      color: var(--tui-color-fg-muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
    .tgbl-toggle-row.is-on .tgbl-toggle-row__meta { color: var(--tui-theme-primary-base); }
    .tgbl-toggle-row .tgbl-toggle { grid-area: toggle; }

    /* Two-up: the control goes top-right, the copy runs the full card width. */
    .tgbl-toggle-group.is-cols-2 .tgbl-toggle-row { grid-template-columns: 1fr auto;
      grid-template-areas: "title toggle" "meta meta"; align-items: start;
      align-content: start; gap: 6px 12px; padding: 16px; }
    .tgbl-toggle-group.is-cols-2 .tgbl-toggle-row.has-desc {
      grid-template-areas: "title toggle" "desc desc" "meta meta"; }

    /* Tint is reserved for things we DETECTED — a plugin we found active.
       A site's own custom post types are ordinary and stay on white. */
    .tgbl-toggle-row.is-tone-detected { background: var(--tui-theme-primary-subtlest);
      border-color: var(--tui-theme-primary-subtle); }
    .tgbl-toggle-row.is-tone-detected .tgbl-toggle-row__title { color: var(--tui-theme-primary-stronger); }
    .tgbl-toggle-row.is-tone-detected .tgbl-toggle-row__desc { color: var(--tui-theme-primary-strong); }

    /* wp-admin styles `input[type=checkbox]` (0,1,1) — a bare .tgbl-toggle
       (0,1,0) loses to it and renders as a blue tick box. Match on the
       attribute too so the track actually wins. */
    .tgbl-wizard input.tgbl-toggle[type=checkbox] {
      appearance: none; -webkit-appearance: none; flex: none; margin: 0;
      position: relative; width: 38px; height: 22px; min-width: 38px; border-radius: 999px;
      background: var(--tui-color-fill-strong); cursor: pointer; border: 0; padding: 0;
      box-shadow: none;
      transition: background-color var(--tui-motion-duration) var(--tui-motion-timing); }
    .tgbl-wizard input.tgbl-toggle[type=checkbox]::before { content: none; }
    .tgbl-wizard input.tgbl-toggle[type=checkbox]::after { content: ""; position: absolute;
      top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff;
      box-shadow: 0 1px 2px rgba(0,0,0,.2); margin: 0;
      transition: transform var(--tui-motion-duration) var(--tui-motion-timing); }
    .tgbl-wizard input.tgbl-toggle[type=checkbox]:checked {
      background: var(--tui-theme-primary-base); }
    .tgbl-wizard input.tgbl-toggle[type=checkbox]:checked::after { transform: translateX(16px); }
    .tgbl-wizard input.tgbl-toggle[type=checkbox]:focus-visible {
      outline: var(--tui-focus-ring-width) solid var(--tui-color-focus-ring);
      outline-offset: var(--tui-focus-ring-offset); }

    /* The estimate the design closes the Index step with. */
    .tgbl-estimate { display: flex; align-items: center; gap: 9px;
      padding: 12px 16px; border-radius: 10px;
      background: var(--tui-theme-success-subtlest);
      color: var(--tui-theme-success-stronger);
      font-size: var(--tgbl-body); font-weight: 600; }
    .tgbl-estimate svg { flex: none; align-self: center; }
    .tgbl-estimate b { font-variant-numeric: tabular-nums; }

    /* Build step — the design's progress panel, log and preview.
       These live here rather than inline in the step so the step markup
       carries no colours or measures of its own. */
    .tgbl-build__panel { border: 1px solid var(--tui-color-divider); border-radius: 10px;
      background: var(--tui-color-bg-surface); padding: 18px 20px; }
    .tgbl-build__head { display: flex; align-items: baseline; gap: 12px; }
    .tgbl-build__title { font-size: var(--tgbl-title); font-weight: 600; }
    .tgbl-build__pct { margin-left: auto; font-size: var(--tgbl-meta); font-weight: 600;
      color: var(--tui-theme-primary-base); font-variant-numeric: tabular-nums; }
    .tgbl-build__track { margin-top: 12px; height: 8px; border-radius: 999px;
      background: var(--tui-color-fill-subtle); overflow: hidden; }
    .tgbl-build__fill { display: block; height: 100%; width: 0%; border-radius: inherit;
      background: var(--tui-theme-primary-base); transition: width .4s ease; }
    .tgbl-build__count { margin-top: 10px; font-size: var(--tgbl-body); color: var(--tui-color-fg-muted);
      font-variant-numeric: tabular-nums; }
    .tgbl-build__log { list-style: none; margin: 0; padding: 14px 16px; border-radius: 10px;
      background: #1d2327; color: #c3c4c7; font-family: var(--tgbl-font-data);
      font-size: var(--tgbl-body); line-height: 1.85; max-height: 190px; overflow-y: auto; }
    .tgbl-build__log li { margin: 0; }
    .tgbl-build__sample { border: 1px solid var(--tui-color-divider); border-radius: 10px;
      padding: 16px; background: var(--tui-color-bg-muted); }
    .tgbl-build__caption { margin-top: 12px; text-align: center;
      color: var(--tui-color-fg-muted); }

    /* A search result, shaped like one: title and its kind on the same line. */
    .tgbl-result { display: flex; align-items: baseline; gap: 12px;
      padding: 14px 16px; border-radius: 8px; background: var(--tui-color-bg);
      border: 1px solid var(--tui-theme-primary-base); }
    .tgbl-result__title { flex: 1; min-width: 0; font-size: var(--tgbl-title);
      font-weight: 600; line-height: 1.35; }
    .tgbl-result__chip { flex: none; font-size: var(--tgbl-micro); font-weight: 700;
      letter-spacing: .06em; text-transform: uppercase; padding: 3px 8px;
      border-radius: 999px; background: var(--tui-theme-primary-subtlest);
      color: var(--tui-theme-primary-stronger); }
    .tgbl-result__chip[hidden] { display: none; }

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
    .tgbl-wizard .tgbl-list { list-style: disc; padding-left: 20px; font-size: var(--tgbl-body);
      line-height: 1.7; margin-bottom: 0; }
    .tgbl-wizard .tgbl-list li + li { margin-top: 4px; }

    /* the aside slot — content + facts panel, unchanged contract */
    .tgbl-cols { display:flex; gap:32px; align-items:flex-start; }
    .tgbl-cols .main { flex:1 1 auto; min-width:0; }
    .tgbl-aside { flex:0 0 240px; border-left:1px solid var(--tui-color-divider); padding-left:24px; }
    .tgbl-aside dl { display:grid; grid-template-columns:1fr auto; gap:6px 12px;
      font-size:var(--tgbl-body); margin:0; }
    .tgbl-aside dt { font-size:var(--tgbl-body); font-weight:400; color:var(--tui-color-fg-muted); }
    .tgbl-aside dd { margin:0; text-align:right; font-variant-numeric: tabular-nums;
      font-weight:600; font-size:var(--tgbl-meta); }

    /* the schematic — hairlines and boxes, no illustration */
    .tgbl-schem { display:flex; align-items:center; margin:8px 0 4px; }
    .tgbl-schem .snode { border:1px solid var(--tui-color-border); border-radius:8px;
      background:var(--tui-color-bg-surface); padding:10px 14px; min-width:110px;
      text-align:center; font-size:var(--tgbl-body); }
    .tgbl-schem .snode b { display:block; font-size:var(--tgbl-title); font-weight:600; }
    .tgbl-schem .snode .sk { font-size:var(--tgbl-micro); font-weight:600;
      color:var(--tui-color-fg-muted); }
    .tgbl-schem .snode[data-on] { border-color:var(--tui-theme-primary-base);
      box-shadow:inset 0 0 0 1px var(--tui-theme-primary-base); background:var(--tui-color-bg); }
    .tgbl-schem .snode[data-ghost] { border-style:dashed; opacity:.55; }
    .tgbl-schem .swire { flex:1 1 0; min-width:26px; height:1.5px; background:#9E9CF7; position:relative; }
    .tgbl-schem .swire em { position:absolute; top:-16px; left:50%; transform:translateX(-50%);
      font-style:normal; font-size:var(--tgbl-micro); font-weight:600;
      color:var(--tui-color-fg-muted); white-space:nowrap; }

    .tgbl-skip { color: var(--tui-color-fg-muted); background: none; border: 0;
      cursor: pointer; padding: 0; font-size: var(--tgbl-body); font-family: inherit;
      text-decoration: underline; text-underline-offset: 3px;
      text-decoration-color: var(--tui-color-border); }
    .tgbl-skip:hover { color: var(--tui-color-fg); text-decoration-color: currentColor; }

    @media (max-width: 782px) {
      .tgbl-wizard { --tgbl-pad: 24px; }
      .tgbl-wizard__well { padding: 20px 14px 40px; }
      .tgbl-wizard { --tgbl-display: 22px; }
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
    var toggle = e.target.closest && e.target.closest('.tgbl-toggle');
    if (toggle) {
      var row = toggle.closest('.tgbl-toggle-row');
      if (row) row.classList.toggle('is-on', toggle.checked);
      var out = document.querySelector('[data-tgbl-estimate]');
      if (out) {
        var total = 0;
        document.querySelectorAll('.tgbl-toggle[data-count]').forEach(function (t) {
          if (t.checked) total += parseInt(t.dataset.count, 10) || 0;
        });
        out.textContent = total.toLocaleString();
      }
      return;
    }
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
