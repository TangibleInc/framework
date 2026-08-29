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

/** Server-rendered .tui-stepper — the same DOM the TUI component emits. */
function render_stepper($rail, $current_id) {
  $map = [ 'done' => 'complete', 'skipped' => 'skipped', 'current' => 'current', 'pending' => 'upcoming' ];
  ?>
  <nav aria-label="Setup progress" class="tui-stepper">
    <ol class="tui-stepper__list">
      <?php foreach ($rail as $r) :
        $status = $map[$r['state']] ?? 'upcoming';
        $suffix = $status === 'complete' ? 'complete' : ($status === 'skipped' ? 'skipped' : ''); ?>
        <li class="tui-stepper__step" data-status="<?php echo esc_attr($status); ?>"
            <?php if ($status === 'current') echo 'aria-current="step"'; ?>>
          <span class="tui-stepper__marker" aria-hidden="true"></span>
          <span class="tui-stepper__label"><?php echo esc_html(ucfirst($r['label'])); ?></span>
          <?php if ($suffix) : ?><span class="tui-visually-hidden">, <?php echo esc_html($suffix); ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
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
    <?php echo \tangible\design\font_faces_css(); ?>
    /* Full-screen takeover: the wizard owns the page (Figma: no admin chrome). */
    html.wp-toolbar { padding-top: 0 !important; }
    #wpadminbar, #adminmenumain, #adminmenuback, #wpfooter,
    .notice, .update-nag, .updated, .error:not(.tgbl-keep) { display: none !important; }
    #wpcontent, #wpbody-content { margin-left: 0 !important; padding: 0 !important; float: none; }
    #wpbody-content .tgbl-wizard { min-height: 100vh; }

    .tgbl-wizard {
      <?php echo \tangible\design\font_tokens_css(); ?>
      display: flex; flex-direction: column;
      background: var(--tui-color-bg);
      color: var(--tui-color-fg);
      font-size: 13px;
    }
    .tgbl-wizard__topbar { display:flex; align-items:center; gap:12px;
      padding: 12px 28px; border-bottom: 1px solid var(--tui-color-border); }
    .tgbl-wizard__lockup { font-family: var(--tgbl-font-label); font-size: 12px;
      font-weight: 600; letter-spacing: .14em; text-transform: uppercase; }
    .tgbl-wizard__lockup span { color: var(--tui-color-fg-muted); letter-spacing: .04em; }
    .tgbl-wizard__exit { margin-left: auto; }
    .tgbl-wizard__stepper-band { padding: 10px 28px;
      border-bottom: 1px solid var(--tui-color-border); }
    .tgbl-wizard__skipstrip { padding: 8px 28px; font-size: 12px;
      color: var(--tui-color-fg-muted); border-bottom: 1px solid var(--tui-color-border); }
    .tgbl-wizard__skipstrip a { color: var(--tui-theme-primary-base); }
    .tgbl-wizard__well { flex: 1; background: var(--tui-color-bg-muted);
      padding: 40px 28px 60px; display: flex; justify-content: center; align-items: flex-start; }
    .tgbl-wizard__content { width: 100%; max-width: 760px; display: flex;
      flex-direction: column; gap: 16px; }
    .tgbl-wizard__card { background: var(--tui-color-bg-surface);
      border: 1px solid var(--tui-color-border); border-radius: var(--tui-radius-md);
      padding: 28px 32px; }
    .tgbl-wizard__footer { display: flex; align-items: center; gap: 14px;
      padding: 12px 28px; border-top: 1px solid var(--tui-color-border);
      background: var(--tui-color-bg); position: sticky; bottom: 0; }

    /* The step heading voices: Recoleta display, mono data, native sentences. */
    .tgbl-wizard h2 { font-family: var(--tgbl-font-display); font-size: 26px;
      font-weight: 600; line-height: 1.15; letter-spacing: -.008em; margin: 0 0 8px; padding: 0; }
    .tgbl-wizard .step-intro { font-size: 14px; line-height: 1.68;
      color: var(--tui-color-fg-muted); margin: 0 0 6px; max-width: 62ch; }
    .tgbl-wizard p { font-size: 13.5px; line-height: 1.6; }
    .tgbl-wizard .lbl { font-family: var(--tgbl-font-label); font-size: 10.5px; font-weight: 600;
      letter-spacing: .14em; text-transform: uppercase; color: var(--tui-color-fg-muted); }
    .tgbl-wizard .eyebrow { font-family: var(--tgbl-font-data); font-size: 10px; font-weight: 700;
      letter-spacing: .18em; text-transform: uppercase; color: var(--tui-color-fg-muted); }
    .tgbl-wizard .whisper { font-size: 11.5px; color: var(--tui-color-fg-muted); }
    .tgbl-wizard .code, .tgbl-wizard input[type=text], .tgbl-wizard input[type=password] {
      font-family: var(--tgbl-font-data); font-size: 12.5px; }
    .tgbl-dbl { border: 0; border-top: 1px solid var(--tui-color-fg); border-bottom: 1px solid var(--tui-color-fg);
      height: 3px; margin: 18px 0 16px; opacity: .75; }

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

    /* the aside slot — content + facts panel, unchanged contract */
    .tgbl-cols { display:flex; gap:30px; align-items:flex-start; }
    .tgbl-cols .main { flex:1 1 auto; min-width:0; }
    .tgbl-aside { flex:0 0 250px; border-left:1px solid var(--tui-color-border); padding-left:20px; }
    .tgbl-aside dl { display:grid; grid-template-columns:1fr auto; gap:5px 10px; font-size:12.5px; margin:0; }
    .tgbl-aside dt { font-family:var(--tgbl-font-label); font-size:9.5px; font-weight:600;
      letter-spacing:.14em; text-transform:uppercase; color:var(--tui-color-fg-muted); }
    .tgbl-aside dd { margin:0; text-align:right; font-family:var(--tgbl-font-data); font-size:11.5px; }

    /* the schematic — hairlines and boxes, no illustration */
    .tgbl-schem { display:flex; align-items:center; margin:14px 0 4px; }
    .tgbl-schem .snode { border:1px solid var(--tui-color-border); border-radius:3px;
      background:var(--tui-color-bg-muted); padding:8px 12px; min-width:104px; text-align:center; font-size:12px; }
    .tgbl-schem .snode b { display:block; font-size:12.5px; }
    .tgbl-schem .snode .sk { font-family:var(--tgbl-font-label); font-size:9px; font-weight:600;
      letter-spacing:.1em; text-transform:uppercase; color:var(--tui-color-fg-muted); }
    .tgbl-schem .snode[data-on] { border-color:var(--tui-theme-primary-base);
      box-shadow:inset 0 0 0 1px var(--tui-theme-primary-base); background:var(--tui-color-bg-surface); }
    .tgbl-schem .snode[data-ghost] { border-style:dashed; opacity:.55; }
    .tgbl-schem .swire { flex:1 1 0; min-width:26px; height:1.5px; background:#9E9CF7; position:relative; }
    .tgbl-schem .swire em { position:absolute; top:-16px; left:50%; transform:translateX(-50%);
      font-family:var(--tgbl-font-label); font-style:normal; font-size:9px; font-weight:600;
      letter-spacing:.1em; text-transform:uppercase; color:var(--tui-color-fg-muted); white-space:nowrap; }

    .tgbl-skip { color: var(--tui-color-fg-muted); background: none; border: 0;
      border-bottom: 1px dashed var(--tui-color-border); cursor: pointer; padding: 0 0 1px;
      font-size: 12.5px; font-family: var(--tgbl-font-body); }
    .tgbl-skip:hover { color: var(--tui-color-fg); border-bottom-color: var(--tui-color-fg-muted); }
  </style>
  <div class="tui-interface tgbl-wizard">
    <header class="tgbl-wizard__topbar">
      <?php render_mark($done, $position > 0 ? $position - 1 : -1, 'md', $all_done); ?>
      <span class="tgbl-wizard__lockup">Tangible <span><?php echo $short_title; ?></span></span>
      <a class="tui-button is-size-sm is-style-ghost is-theme-secondary tgbl-wizard__exit"
         href="<?php echo esc_url(admin_url('admin.php?page=tangible-home')); ?>">Exit setup</a>
    </header>

    <div class="tgbl-wizard__stepper-band">
      <?php render_stepper($plan['rail'], $plan['current']); ?>
    </div>

    <?php if ($skip_sentences) : ?>
      <div class="tgbl-wizard__skipstrip"><?php echo implode(' ', $skip_sentences); ?>
        <a href="https://tangible.one/account" target="_blank" rel="noopener">Manage in your account</a>.</div>
    <?php endif; ?>

    <?php if ($all_done) : ?>
      <main class="tgbl-wizard__well">
        <div class="tgbl-wizard__content">
          <div class="tgbl-wizard__card">
            <p class="eyebrow" style="margin:0 0 6px">setup · complete</p>
            <h2>Nothing left to ask.</h2>
            <p class="step-intro">Everything is either configured or already on file.</p>
            <p style="margin-top:18px">
              <a class="tui-button is-theme-primary" href="<?php echo esc_url(admin_url('admin.php?page=tangible-home')); ?>">Go to Tangible Home</a>
            </p>
          </div>
        </div>
      </main>
    <?php else : ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            style="display:flex; flex-direction:column; flex:1; min-height:0">
        <?php wp_nonce_field('tangible_onboarding_step'); ?>
        <input type="hidden" name="action" value="tangible_onboarding_step" />
        <input type="hidden" name="plugin" value="<?php echo esc_attr($name); ?>" />
        <input type="hidden" name="step" value="<?php echo esc_attr($step['id']); ?>" />

        <main class="tgbl-wizard__well">
          <div class="tgbl-wizard__content">
            <?php $error = get_transient(step_error_key($name));
            if ($error) { delete_transient(step_error_key($name)); } ?>
            <?php if ($error) : ?>
              <div class="tui-notice is-theme-danger tgbl-keep" role="alert">
                <div class="tui-notice__inner"><div class="tui-notice__body"><?php echo esc_html($error); ?></div></div>
              </div>
            <?php endif; ?>

            <p class="eyebrow" style="margin:0">
              step <?php echo (int) $position; ?> of <?php echo (int) $total; ?> ·
              <?php echo esc_html($step['label'] ?? str_replace('-', ' ', $step['id'])); ?>
            </p>

            <div class="tgbl-wizard__card">
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
          </div>
        </main>

        <footer class="tgbl-wizard__footer">
          <span class="whisper">Nothing is saved until you continue.</span>
          <span style="flex:1"></span>
          <?php if ($step['skippable']) : ?>
            <button class="tgbl-skip" type="submit" name="do" value="skip">Skip this step</button>
          <?php endif; ?>
          <button class="tui-button is-theme-primary" type="submit" name="do" value="continue"><?php
            echo esc_html($step['submit_label'] ?? 'Continue'); ?></button>
        </footer>
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
