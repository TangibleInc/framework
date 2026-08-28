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

  // Resumable re-entry: the playbook's one universal finding. Dismissible,
  // and it re-resolves each load, so finishing setup removes it without a
  // dismissal ever being recorded.
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
// The House dial, ported from the design catalogue (/plugin-onboarding-e,
// /plugin-onboarding-fullscreen). The devices, not approximations of them:
//
//   ghost numeral   the step number, 84px mono, in the light purple
//   tile ladder     the six-tile logo as the progress marker — tiles fill as
//                   steps complete, the foot tile turns coral at the end
//   mono labels     10px/uppercase/.13em — eyebrows, rail, footer
//   double rule     the two-line divider under the step header
//   three purples   #9E9CF7 marks · #5B51C9 structure · #4265C4 data; the
//                   interactive colour stays the host's (--wp-admin-theme-color
//                   via .button-primary), so the wizard follows the reader's
//                   admin colour scheme instead of fighting it
//
// A skipped rail entry renders dashed with its note ("on file") — the promise
// about what we did not ask, kept visible.

function render_wizard($plugin) {
  $name = $plugin->name;
  $facts = build_facts($name);
  $plan = onboarding\resolve_plan($name, $facts);
  $step = $plan['steps'][0] ?? null;
  $title = esc_html($plugin->title ?? $name);

  $total = count($plan['rail']);
  $position = 0; $done = 0;
  foreach ($plan['rail'] as $idx => $r) {
    if ($r['id'] === $plan['current']) $position = $idx + 1;
    if (in_array($r['state'], ['done', 'skipped'], true)) $done++;
  }
  $all_done = !$step;
  // The tile ladder: five body tiles fill with progress, the foot tile is
  // the completion mark and turns coral only at the end.
  $filled = $total > 0 ? (int) round(($done / $total) * 5) : 0;
  ?>
  <style>
    <?php echo \tangible\design\font_faces_css(); ?>
    /* The three voices (mono audition, candidate E): Space Mono for DATA ONLY,
       League Spartan for labels and headings, native sans for sentences. */
    .tgbl-wiz { --mark:#9E9CF7; --deep:#5B51C9; --data:#4265C4; --salmon:#FD9597;
      <?php echo \tangible\design\font_tokens_css(); ?>
      max-width: 880px; margin: 34px auto 0; color:#1d2327; }
    .tgbl-wiz .lbl { font-family:var(--tgbl-font-label); font-size:10.5px; font-weight:600;
      letter-spacing:.14em; text-transform:uppercase; color:#646970; }
    .tgbl-wiz .whisper { font-family:var(--tgbl-font-body); font-size:11.5px; color:#8c8f94; }
    .tgbl-wiz-band { display:flex; align-items:center; gap:13px; padding:0 0 16px; }
    .tgbl-wiz-band .name { font-size:17px; font-weight:600; letter-spacing:-.01em; }
    .tgbl-ladder { display:inline-grid; grid-template-columns:repeat(3,8px); grid-template-rows:repeat(3,8px); gap:1px; }
    .tgbl-ladder i { display:block; border-radius:1px; background:#dcdcde; }
    .tgbl-ladder i:nth-child(1){grid-area:1/1}.tgbl-ladder i:nth-child(2){grid-area:1/2}
    .tgbl-ladder i:nth-child(3){grid-area:1/3}.tgbl-ladder i:nth-child(4){grid-area:2/1}
    .tgbl-ladder i:nth-child(5){grid-area:2/3}.tgbl-ladder i:nth-child(6){grid-area:3/2}
    .tgbl-ladder i[data-on] { background:var(--mark); }
    .tgbl-ladder i[data-done] { background:var(--salmon); }
    .tgbl-wiz-rail { display:flex; gap:20px; flex-wrap:wrap; padding:13px 0 15px;
      border-top:1px solid #c3c4c7; border-bottom:1px solid #c3c4c7; }
    .tgbl-wiz-rail .st { display:flex; align-items:center; gap:7px; }
    .tgbl-wiz-rail .dot { width:8px; height:8px; border-radius:1.5px; background:#dcdcde; flex:none; }
    .tgbl-wiz-rail .st[data-state="done"] .dot { background:var(--mark); }
    .tgbl-wiz-rail .st[data-state="current"] .dot { background:var(--deep); }
    .tgbl-wiz-rail .st[data-state="current"] .lbl { color:#1d2327; }
    .tgbl-wiz-rail .st[data-state="skipped"] .dot { background:transparent; border:1px dashed #a7aaad; }
    .tgbl-wiz-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px;
      padding:30px 34px 24px; margin-top:26px; box-shadow:0 1px 1px rgba(0,0,0,.04); }
    .tgbl-wiz-head { display:flex; gap:24px; align-items:flex-start; }
    .tgbl-ghost { font-family:var(--tgbl-font-data); font-size:84px; font-weight:700;
      line-height:.8; color:var(--mark); opacity:.5; letter-spacing:-.04em; flex:none; user-select:none; }
    .tgbl-wiz-card h2 { font-family:var(--tgbl-font-label); font-size:25px; font-weight:600;
      margin:7px 0 0; line-height:1.18; letter-spacing:-.005em; padding:0; }
    .tgbl-wiz-card p { font-size:14px; line-height:1.65; max-width:62ch; }
    .tgbl-dbl { border:0; border-top:1px solid #c3c4c7; border-bottom:1px solid #c3c4c7; height:3px; margin:22px 0; }
    .tgbl-wiz-card .code, .tgbl-wiz-card input[type=text], .tgbl-wiz-card input[type=password] {
      font-family:var(--tgbl-font-data); font-size:12.5px; color:var(--data); }
    .tgbl-wiz-foot { display:flex; align-items:center; gap:14px; margin-top:24px;
      padding-top:15px; border-top:1px solid #e4e4e7; }
    .tgbl-skip { color:#646970; background:none; border:0; border-bottom:1px dashed #a7aaad;
      cursor:pointer; padding:0 0 1px; font-size:12.5px; }
    .tgbl-skip:hover { color:#1d2327; border-bottom-color:#646970; }
    .tgbl-wiz-error { border:1px solid #c3c4c7; border-inline-start:3px solid var(--salmon);
      background:#fff; padding:9px 13px; font-size:13px; margin:0 0 14px; }
  </style>
  <div class="tgbl-wiz">
    <div class="tgbl-wiz-band">
      <span class="tgbl-ladder" aria-hidden="true"><?php
        for ($i = 1; $i <= 5; $i++) echo '<i' . ($i <= $filled ? ' data-on' : '') . '></i>';
        echo '<i' . ($all_done ? ' data-done' : '') . '></i>';
      ?></span>
      <span class="name"><?php echo $title; ?></span>
      <span class="lbl">setup</span>
      <span style="flex:1"></span>
      <span class="lbl" style="font-family:var(--tgbl-font-data);font-size:10px"><?php echo (int) $done; ?> of <?php echo (int) $total; ?> settled</span>
    </div>

    <div class="tgbl-wiz-rail">
      <?php foreach ($plan['rail'] as $r) : ?>
        <span class="st" data-state="<?php echo esc_attr($r['state']); ?>">
          <span class="dot"></span>
          <span class="lbl"><?php echo esc_html($r['label']);
            if ($r['state'] === 'skipped') echo ' · ' . esc_html($r['note'] ?? 'on file'); ?></span>
        </span>
      <?php endforeach; ?>
    </div>

    <?php if ($all_done) : ?>
      <div class="tgbl-wiz-card">
        <div class="tgbl-wiz-head">
          <span class="tgbl-ghost" aria-hidden="true">✓</span>
          <div>
            <p class="lbl" style="margin:4px 0 0">setup · complete</p>
            <h2>Nothing left to ask.</h2>
            <p>Everything is either configured or already on file.</p>
            <p style="margin-top:20px">
              <a class="button button-primary button-large" href="<?php echo esc_url(admin_url('admin.php?page=tangible-home')); ?>">Go to Tangible Home</a>
            </p>
          </div>
        </div>
      </div>
    <?php else : ?>
      <form class="tgbl-wiz-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('tangible_onboarding_step'); ?>
        <input type="hidden" name="action" value="tangible_onboarding_step" />
        <input type="hidden" name="plugin" value="<?php echo esc_attr($name); ?>" />
        <input type="hidden" name="step" value="<?php echo esc_attr($step['id']); ?>" />

        <?php $error = get_transient(step_error_key($name));
        if ($error) { delete_transient(step_error_key($name)); } ?>
        <div class="tgbl-wiz-head">
          <span class="tgbl-ghost" aria-hidden="true"><?php echo esc_html(str_pad((string) $position, 2, '0', STR_PAD_LEFT)); ?></span>
          <div style="flex:1;min-width:0">
            <?php if ($error) : ?>
              <div class="tgbl-wiz-error" role="alert"><?php echo esc_html($error); ?></div>
            <?php endif; ?>
            <p class="lbl" style="margin:4px 0 0">
              step <?php echo (int) $position; ?> of <?php echo (int) $total; ?> ·
              <?php echo esc_html($step['label'] ?? str_replace('-', ' ', $step['id'])); ?>
            </p>
            <?php if (is_callable($step['render'])) {
              call_user_func($step['render'], $plugin, $facts, $step);
            } else {
              echo '<h2>' . esc_html($step['id']) . '</h2>';
            } ?>
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
  <?php
}
