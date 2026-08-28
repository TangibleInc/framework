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
    add_submenu_page(
      '', $plugin->title ?? $plugin->name, '', 'manage_options',
      get_setup_slug($plugin),
      function () use ($plugin) { render_wizard($plugin); }
    );
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
// The frame from the TUI prototype (`/ui-preview/plugin-onboarding` in the
// app), ported device by device rather than approximated:
//
//   band          one tinted strip — the only place colour appears — carrying
//                 the assembling brand mark, "{Plugin} setup", and the address
//                 chip (SS·02): the stable short code for this screen
//   rail          each step is the six-tile mark filled to its OWN position,
//                 so the row reads left to right as one object assembling
//                 itself; wires light as steps resolve; skipped nodes dim
//                 with their own caption instead of vanishing
//   skip strip    the sentence under the rail saying WHY something was not
//                 asked — the honesty device
//   crop marks    on the BODY, not the outer box (on the box the top pair
//                 hides under the band — found by rendering, not reading)
//   ghost numeral Space Mono 700; headings Recoleta; labels League Spartan;
//                 sentences the native sans. Four voices, one job each.
//
// The aside slot: a step may declare 'aside' (callable) and the body renders
// two-column — content plus a 250px facts panel behind a hairline, the
// prototype's "this site" panel generalized.

/** SS·02 — capitals of the title (minus Tangible/Plugin) + current position. */
function get_screen_address($plugin, $position) {
  $title = $plugin->title ?? $plugin->name;
  $title = trim(str_ireplace(['tangible', 'plugin'], '', $title));
  preg_match_all('/[A-Z]/', $title, $m);
  $code = implode('', array_slice($m[0], 0, 3));
  if ($code === '') $code = strtoupper(substr($title, 0, 2));
  return $code . '·' . str_pad((string) max(1, $position), 2, '0', STR_PAD_LEFT);
}

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

function render_wizard($plugin) {
  $name = $plugin->name;
  $facts = build_facts($name);
  $plan = onboarding\resolve_plan($name, $facts);
  $step = $plan['steps'][0] ?? null;
  $title = esc_html($plugin->title ?? $name);
  // The band title strips the vendor prefix — the band already wears the mark.
  $short_title = esc_html(trim(str_ireplace(['tangible ', ' plugin'], ['', ''], $plugin->title ?? $name)));

  $total = count($plan['rail']);
  $position = 0; $done = 0;
  foreach ($plan['rail'] as $idx => $r) {
    if ($r['id'] === $plan['current']) $position = $idx + 1;
    if (in_array($r['state'], ['done', 'skipped'], true)) $done++;
  }
  $all_done = !$step;

  // The skip strip: one sentence per skipped-with-note step.
  $skip_sentences = [];
  foreach ($plan['rail'] as $r) {
    if ($r['state'] === 'skipped' && !empty($r['note'])) {
      $skip_sentences[] = '<strong>' . esc_html(ucfirst($r['label'])) . '</strong> was skipped — '
        . esc_html($r['note']) . '.';
    }
  }
  ?>
  <style>
    <?php echo \tangible\design\font_faces_css(); ?>
    .tgbl-wiz { --mark:#9E9CF7; --deep:#5B51C9; --data:#4265C4; --salmon:#FD9597;
      --accent:var(--wp-admin-theme-color, #2271b1);
      <?php echo \tangible\design\font_tokens_css(); ?>
      max-width: 920px; margin: 30px auto 0; color:#1d2327;
      font-family:var(--tgbl-font-body); font-size:13px; }
    .tgbl-wiz .lbl { font-family:var(--tgbl-font-label); font-size:10.5px; font-weight:600;
      letter-spacing:.14em; text-transform:uppercase; color:#646970; }
    .tgbl-wiz .eyebrow { font-family:var(--tgbl-font-data); font-size:10px; font-weight:700;
      letter-spacing:.18em; text-transform:uppercase; color:#646970; }
    .tgbl-wiz .whisper { font-family:var(--tgbl-font-body); font-size:11.5px; color:#8c8f94; }
    .tgbl-wiz .code { font-family:var(--tgbl-font-data); font-size:12px; color:var(--data); }

    .tgbl-frame { border:1px solid #c3c4c7; border-radius:4px; background:#fff;
      overflow:hidden; box-shadow:0 1px 1px rgba(0,0,0,.04); }

    /* the band — the only place colour appears */
    .tgbl-band { display:flex; align-items:center; gap:12px; padding:12px 20px;
      background:color-mix(in srgb, var(--accent) 16%, #fff);
      border-bottom:2px solid var(--accent); }
    .tgbl-band .bt { font-size:14px; font-weight:600; }
    .tgbl-addr { font-family:var(--tgbl-font-data); font-size:11px; font-weight:700;
      letter-spacing:.22em; color:var(--accent); margin-left:auto; }

    /* the mark */
    .tgbl-mark { --u:7px; display:inline-grid; gap:1px; flex:none;
      grid-template-columns:repeat(3,var(--u)); grid-template-rows:repeat(3,var(--u)); }
    .tgbl-mark i { display:block; border-radius:1px; background:#dcdcde; }
    .tgbl-mark i:nth-child(1){grid-area:1/1}.tgbl-mark i:nth-child(2){grid-area:1/2}
    .tgbl-mark i:nth-child(3){grid-area:1/3}.tgbl-mark i:nth-child(4){grid-area:2/1}
    .tgbl-mark i:nth-child(5){grid-area:2/3}.tgbl-mark i:nth-child(6){grid-area:3/2}
    .tgbl-mark i[data-on] { background:var(--mark); }
    .tgbl-mark i[data-now] { background:var(--accent); }
    .tgbl-mark i[data-done] { background:var(--salmon); }

    /* the rail — each node the mark filled to its own position */
    .tgbl-rail { display:flex; align-items:flex-start; padding:16px 20px;
      background:#f6f6f7; border-bottom:1px solid #c3c4c7; }
    .tgbl-rail .node { display:flex; flex-direction:column; align-items:center; gap:7px;
      flex:0 0 auto; max-width:140px; text-align:center; }
    .tgbl-rail .node .t { font-family:var(--tgbl-font-label); font-size:11px; font-weight:600;
      letter-spacing:.03em; color:#646970; line-height:1.3; }
    .tgbl-rail .node[data-state="current"] .t { color:#1d2327; }
    .tgbl-rail .node[data-state="skipped"] { opacity:.5; }
    .tgbl-rail .wire { flex:1 1 0; min-width:20px; height:1.5px; margin-top:13px; background:#dcdcde; }
    .tgbl-rail .wire[data-on] { background:var(--mark); }

    .tgbl-skipstrip { padding:9px 20px; background:#fff; border-bottom:1px solid #e4e4e7;
      font-size:12px; color:#646970; }
    .tgbl-skipstrip a { color:var(--accent); }

    /* the body, wearing the crop marks */
    .tgbl-body { position:relative; padding:30px 34px 24px; }
    .tgbl-body::before, .tgbl-body::after, .tgbl-body .cm::before, .tgbl-body .cm::after {
      content:''; position:absolute; width:14px; height:14px; pointer-events:none; }
    .tgbl-body::before { top:10px; left:10px; border-top:1px solid var(--mark); border-left:1px solid var(--mark); }
    .tgbl-body::after { top:10px; right:10px; border-top:1px solid var(--mark); border-right:1px solid var(--mark); }
    .tgbl-body .cm::before { bottom:10px; left:10px; border-bottom:1px solid var(--mark); border-left:1px solid var(--mark); }
    .tgbl-body .cm::after { bottom:10px; right:10px; border-bottom:1px solid var(--mark); border-right:1px solid var(--mark); }

    .tgbl-wiz-head { display:flex; gap:26px; align-items:flex-start; }
    .tgbl-ghost { font-family:var(--tgbl-font-data); font-size:84px; font-weight:700;
      line-height:.8; color:var(--mark); opacity:.55; letter-spacing:-.04em; flex:none; user-select:none; }
    .tgbl-wiz h2 { font-family:var(--tgbl-font-display); font-size:28px; font-weight:600;
      margin:7px 0 0; line-height:1.15; letter-spacing:-.008em; padding:0; color:#1d2327; }
    .tgbl-wiz .step-intro { font-size:14px; line-height:1.68; color:#50575e; margin:9px 0 0; max-width:62ch; }
    .tgbl-wiz p { font-size:13.5px; line-height:1.6; }
    .tgbl-dbl { border:0; border-top:1px solid #1d2327; border-bottom:1px solid #1d2327;
      height:3px; margin:22px 0 20px; opacity:.75; }
    .tgbl-wiz input[type=text], .tgbl-wiz input[type=password] {
      font-family:var(--tgbl-font-data); font-size:12.5px; color:var(--data); }

    /* the aside slot — content + facts panel */
    .tgbl-cols { display:flex; gap:30px; align-items:flex-start; }
    .tgbl-cols .main { flex:1 1 auto; min-width:0; }
    .tgbl-aside { flex:0 0 250px; border-left:1px solid #dcdcde; padding-left:20px; }
    .tgbl-aside dl { display:grid; grid-template-columns:1fr auto; gap:5px 10px; font-size:12.5px; margin:0; }
    .tgbl-aside dt { font-family:var(--tgbl-font-label); font-size:9.5px; font-weight:600;
      letter-spacing:.14em; text-transform:uppercase; color:#646970; }
    .tgbl-aside dd { margin:0; text-align:right; font-family:var(--tgbl-font-data); font-size:11.5px; color:var(--data); }

    /* the schematic — hairlines and boxes, no illustration */
    .tgbl-schem { display:flex; align-items:center; margin:14px 0 4px; }
    .tgbl-schem .snode { border:1px solid #c3c4c7; border-radius:3px; background:#fafafa;
      padding:8px 12px; min-width:104px; text-align:center; font-size:12px; }
    .tgbl-schem .snode b { display:block; font-size:12.5px; }
    .tgbl-schem .snode .sk { font-family:var(--tgbl-font-label); font-size:9px; font-weight:600;
      letter-spacing:.1em; text-transform:uppercase; color:#646970; }
    .tgbl-schem .snode[data-on] { border-color:var(--accent); box-shadow:inset 0 0 0 1px var(--accent); background:#fff; }
    .tgbl-schem .snode[data-ghost] { border-style:dashed; opacity:.55; }
    .tgbl-schem .swire { flex:1 1 0; min-width:26px; height:1.5px; background:var(--mark); position:relative; }
    .tgbl-schem .swire em { position:absolute; top:-16px; left:50%; transform:translateX(-50%);
      font-family:var(--tgbl-font-label); font-style:normal; font-size:9px; font-weight:600;
      letter-spacing:.1em; text-transform:uppercase; color:#646970; white-space:nowrap; }

    .tgbl-wiz-foot { display:flex; align-items:center; gap:14px; margin-top:24px;
      padding-top:15px; border-top:1px solid #e4e4e7; }
    .tgbl-skip { color:#646970; background:none; border:0; border-bottom:1px dashed #a7aaad;
      cursor:pointer; padding:0 0 1px; font-size:12.5px; font-family:var(--tgbl-font-body); }
    .tgbl-skip:hover { color:#1d2327; border-bottom-color:#646970; }
    .tgbl-wiz-error { border:1px solid #c3c4c7; border-left:3px solid var(--salmon);
      background:#fff; padding:9px 13px; font-size:13px; margin:0 0 14px; }
  </style>
  <div class="tgbl-wiz">
    <div class="tgbl-frame">
      <div class="tgbl-band">
        <?php render_mark($done, $position > 0 ? $position - 1 : -1, 'md', $all_done); ?>
        <span class="bt"><?php echo $short_title; ?> setup</span>
        <span class="tgbl-addr"><?php echo esc_html(get_screen_address($plugin, $all_done ? $total : $position)); ?></span>
      </div>

      <div class="tgbl-rail">
        <?php $n = count($plan['rail']);
        foreach ($plan['rail'] as $i => $r) :
          $reached = in_array($r['state'], ['done', 'current'], true); ?>
          <div class="node" data-state="<?php echo esc_attr($r['state']); ?>">
            <?php render_mark($reached ? $i + 1 : 0, $r['state'] === 'current' ? $i : -1, 'lg'); ?>
            <span class="t"><?php echo esc_html(ucfirst($r['label'])); ?></span>
            <?php if ($r['state'] === 'skipped') : ?>
              <span class="eyebrow" style="font-size:8.5px"><?php echo esc_html($r['note'] ?? 'on file'); ?></span>
            <?php endif; ?>
          </div>
          <?php if ($i < $n - 1) : ?>
            <span class="wire" <?php echo $r['state'] !== 'pending' ? 'data-on' : ''; ?>></span>
          <?php endif;
        endforeach; ?>
      </div>

      <?php if ($skip_sentences) : ?>
        <div class="tgbl-skipstrip"><?php echo implode(' ', $skip_sentences); ?>
          <a href="https://tangible.one/account" target="_blank" rel="noopener">Manage in your account</a>.</div>
      <?php endif; ?>

      <div class="tgbl-body"><span class="cm"></span>
        <?php if ($all_done) : ?>
          <div class="tgbl-wiz-head">
            <span class="tgbl-ghost" aria-hidden="true">OK</span>
            <div>
              <p class="eyebrow" style="margin:4px 0 0">setup · complete</p>
              <h2>Nothing left to ask.</h2>
              <p class="step-intro">Everything is either configured or already on file.</p>
              <p style="margin-top:18px">
                <a class="button button-primary button-large" href="<?php echo esc_url(admin_url('admin.php?page=tangible-home')); ?>">Go to Tangible Home</a>
              </p>
            </div>
          </div>
        <?php else : ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('tangible_onboarding_step'); ?>
            <input type="hidden" name="action" value="tangible_onboarding_step" />
            <input type="hidden" name="plugin" value="<?php echo esc_attr($name); ?>" />
            <input type="hidden" name="step" value="<?php echo esc_attr($step['id']); ?>" />

            <?php $error = get_transient(step_error_key($name));
            if ($error) { delete_transient(step_error_key($name)); } ?>
            <?php if ($error) : ?>
              <div class="tgbl-wiz-error" role="alert"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

            <div class="tgbl-wiz-head">
              <span class="tgbl-ghost" aria-hidden="true"><?php echo esc_html(str_pad((string) $position, 2, '0', STR_PAD_LEFT)); ?></span>
              <div style="flex:1;min-width:0">
                <p class="eyebrow" style="margin:4px 0 0">
                  step <?php echo (int) $position; ?> of <?php echo (int) $total; ?> ·
                  <?php echo esc_html($step['label'] ?? str_replace('-', ' ', $step['id'])); ?>
                </p>
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

            <div class="tgbl-wiz-foot">
              <span class="whisper">Nothing is saved until you continue.</span>
              <span style="flex:1"></span>
              <?php if ($step['skippable']) : ?>
                <button class="tgbl-skip" type="submit" name="do" value="skip">Skip this step</button>
              <?php endif; ?>
              <button class="button button-primary button-large" type="submit" name="do" value="continue"><?php
                echo esc_html($step['submit_label'] ?? 'Continue'); ?></button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php
}
