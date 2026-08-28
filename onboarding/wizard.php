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
function register_wizard($plugin) {
  $name = $plugin->name;
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
    if ($ok === false) return null;   // stay open — nothing recorded
  }
  onboarding\mark($plugin_name, $step_id, 'done');
  return $step_id;
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

function render_wizard($plugin) {
  $name = $plugin->name;
  $facts = build_facts($name);
  $plan = onboarding\resolve_plan($name, $facts);
  $step = $plan['steps'][0] ?? null;
  $title = esc_html($plugin->title ?? $name);
  ?>
  <style>
    .tgbl-wiz { max-width: 860px; margin: 28px auto 0; }
    .tgbl-wiz .lbl { font-family:ui-monospace,Menlo,monospace; font-size:10px; font-weight:600;
      letter-spacing:.13em; text-transform:uppercase; color:#646970; }
    .tgbl-wiz-band { display:flex; align-items:center; gap:12px; padding:0 0 14px; }
    .tgbl-mark { display:inline-grid; grid-template-columns:repeat(3,7px); grid-template-rows:repeat(3,7px); gap:1px; }
    .tgbl-mark i { display:block; border-radius:1px; background:#9e9cf7; }
    .tgbl-mark i:nth-child(1){grid-area:1/1}.tgbl-mark i:nth-child(2){grid-area:1/2}
    .tgbl-mark i:nth-child(3){grid-area:1/3}.tgbl-mark i:nth-child(4){grid-area:2/1}
    .tgbl-mark i:nth-child(5){grid-area:2/3}.tgbl-mark i:nth-child(6){grid-area:3/2}
    .tgbl-wiz-rail { display:flex; gap:18px; flex-wrap:wrap; padding:12px 0 18px; border-bottom:1px solid #c3c4c7; }
    .tgbl-wiz-rail .st { display:flex; align-items:center; gap:7px; }
    .tgbl-wiz-rail .dot { width:9px; height:9px; border-radius:1.5px; background:#dcdcde; }
    .tgbl-wiz-rail .st[data-state="done"] .dot { background:#9e9cf7; }
    .tgbl-wiz-rail .st[data-state="current"] .dot { background:#2271b1; }
    .tgbl-wiz-rail .st[data-state="skipped"] .dot { background:transparent; border:1px dashed #a7aaad; }
    .tgbl-wiz-rail .st[data-state="skipped"] .lbl { text-decoration:none; }
    .tgbl-wiz-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:26px 30px 22px; margin-top:22px; }
    .tgbl-wiz-foot { display:flex; align-items:center; gap:12px; margin-top:22px; padding-top:14px; border-top:1px solid #e4e4e7; }
    .tgbl-skip { color:#646970; text-decoration:none; border-bottom:1px dashed #a7aaad; }
  </style>
  <div class="tgbl-wiz">
    <div class="tgbl-wiz-band">
      <span class="tgbl-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
      <span style="font-size:18px"><?php echo $title; ?></span>
      <span class="lbl">setup</span>
    </div>

    <div class="tgbl-wiz-rail">
      <?php foreach ($plan['rail'] as $r) : ?>
        <span class="st" data-state="<?php echo esc_attr($r['state']); ?>">
          <span class="dot"></span>
          <span class="lbl"><?php echo esc_html(str_replace('-', ' ', $r['id']));
            if ($r['state'] === 'skipped') echo ' · on file'; ?></span>
        </span>
      <?php endforeach; ?>
    </div>

    <?php if (!$step) : ?>
      <div class="tgbl-wiz-card">
        <p class="lbl">all set</p>
        <h2 style="margin:8px 0 6px">Nothing left to ask.</h2>
        <p>Everything is either configured or already on file.</p>
        <p style="margin-top:18px">
          <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tangible-home')); ?>">Go to Tangible Home</a>
        </p>
      </div>
    <?php else : ?>
      <form class="tgbl-wiz-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('tangible_onboarding_step'); ?>
        <input type="hidden" name="action" value="tangible_onboarding_step" />
        <input type="hidden" name="plugin" value="<?php echo esc_attr($name); ?>" />
        <input type="hidden" name="step" value="<?php echo esc_attr($step['id']); ?>" />

        <?php if (is_callable($step['render'])) {
          call_user_func($step['render'], $plugin, $facts, $step);
        } else {
          echo '<p>' . esc_html($step['id']) . '</p>';
        } ?>

        <div class="tgbl-wiz-foot">
          <span class="lbl">nothing is saved until you continue</span>
          <span style="flex:1"></span>
          <?php if ($step['skippable']) : ?>
            <button class="tgbl-skip" style="background:none;border-top:0;border-left:0;border-right:0;cursor:pointer"
                    type="submit" name="do" value="skip">Skip this step</button>
          <?php endif; ?>
          <button class="button button-primary button-large" type="submit" name="do" value="continue">Continue</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
  <?php
}
