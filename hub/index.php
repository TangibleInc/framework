<?php
/**
 * Tangible Hub — the landing page the shared "Tangible" menu never had,
 * and the admin-bar mark that gets people there.
 *
 * One page for everything Tangible on a site: installed plugins with their
 * licence truth, pending updates, and the notification rail (the one owned
 * channel — see notifications.php). Scarecrow: /tangible-hub on the design
 * catalog; this is that page built with the framework's own primitives.
 *
 * Data seams, all consumed rather than owned:
 *   plugins   framework::$state->plugins (whatever register_plugin() saw)
 *   licences  tangible\updater\get_license_status() when the updater is
 *             present — the Hub renders licence facts, never stores them
 *   updates   core's update_plugins transient, which the updater's checker
 *             already hydrates — no second update check
 *
 * Steward rule: when this site is marked as client-managed
 * (option tangible_site_steward = 'client', written by an onboarding step),
 * the admin-bar mark does not render and the Hub drops its commerce rows.
 * The person in this admin is not the customer.
 */
namespace tangible\hub;

use tangible\framework;
use tangible\hub;

require_once __DIR__ . '/notifications.php';

const PAGE = 'tangible-home';

function is_client_managed_site() {
  return get_option('tangible_site_steward') === 'client';
}

// ── menu: first item under the shared Tangible top-level ──────────────────
add_action('init', function () {
  if (!is_admin()) return;
  framework\register_admin_menu([
    'name'       => PAGE,
    'title'      => 'Home',
    'page_title' => 'Tangible',
    'position'   => -100,
    'separator'  => 'after',
    'callback'   => 'tangible\\hub\\render_page',
  ]);
});

// ── the admin-bar mark ─────────────────────────────────────────────────────
add_action('admin_bar_menu', function ($bar) {
  if (!current_user_can('manage_options')) return;
  if (is_client_managed_site()) return;   // absent, not muted — see header note

  $count = hub\get_unread_count();
  // The official six-tile logo (design/tangible-logo.svg geometry), inlined as
  // SVG: the admin bar's own item CSS scrambles a CSS grid, but has nothing to
  // break in an SVG. Grey at rest, brand colours on hover.
  $tiles = '<svg class="tgbl-bar-mark" width="16" height="16" viewBox="0 0 99 99" aria-hidden="true">'
         . '<path d="M0 0h33v33H0z"/><path d="M33 0h33v33H33z"/><path d="M66 0h33v33H66z"/>'
         . '<path d="M0 33h33v33H0z"/><path d="M66 33h33v33H66z"/><path d="M33 66h33v33H33z"/>'
         . '</svg>';
  // Inline pill, the bar's own vocabulary (the Yoast convention). No pill at
  // zero — a "0" is a nag pretending to be a number.
  $pill = $count > 0
    ? '<span class="tgbl-bar-pill">' . ($count > 9 ? '9+' : (int) $count) . '</span>'
    : '';

  $bar->add_node([
    'id'    => 'tangible-hub',
    'title' => $tiles . $pill,
    'href'  => admin_url('admin.php?page=' . PAGE),
    'meta'  => [ 'title' => 'Tangible' . ($count ? " — $count unread" : '') ],
  ]);
}, 80);

add_action('wp_before_admin_bar_render', function () {
  if (is_client_managed_site()) return;
  ?><style>
    #wpadminbar .tgbl-bar-mark { vertical-align:middle; margin-top:-2px; }
    #wpadminbar .tgbl-bar-mark path { fill:#c3c4c7; }
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(1){fill:#262262}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(2){fill:#662d91}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(3){fill:#9f1f63}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(4){fill:#2e3192}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(5){fill:#ec008c}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(6){fill:#02aeef}
    #wpadminbar .tgbl-bar-pill { background:#d63638; color:#fff; font-size:10px; font-weight:600;
      line-height:16px; min-width:16px; padding:0 4px; border-radius:8px; display:inline-block;
      text-align:center; margin-left:6px; vertical-align:middle; }
  </style><?php
});

// ── dismissals ─────────────────────────────────────────────────────────────
add_action('admin_post_tangible_hub_dismiss', function () {
  if (!current_user_can('manage_options')) wp_die('Nope');
  check_admin_referer('tangible_hub_dismiss');
  hub\dismiss_notification(sanitize_text_field($_GET['item'] ?? ''));
  wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . PAGE));
  exit;
});

// ── facts for the table ────────────────────────────────────────────────────
function get_plugin_rows() {
  $rows = [];
  foreach (framework::$state->plugins ?? [] as $plugin) {
    $row = [
      'plugin'  => $plugin,
      'title'   => $plugin->title ?? $plugin->name,
      'version' => $plugin->version ?? '',
      'settings_url' => function_exists('tangible\\framework\\get_plugin_settings_page_url')
        ? framework\get_plugin_settings_page_url($plugin) : null,
      'license' => null,   // null = not licence-managed (free / no updater)
      'update'  => null,
    ];

    // Licence facts — the updater's, when it is present and manages this one.
    if (function_exists('tangible\\updater\\get_license_status')) {
      $status = \tangible\updater\get_license_status($plugin);
      if (!empty($status)) $row['license'] = $status;
    }

    // Pending update — core's transient, which the updater's checker hydrates.
    if (!empty($plugin->file_path)) {
      $updates = get_site_transient('update_plugins');
      $basename = plugin_basename($plugin->file_path);
      if (!empty($updates->response[$basename]->new_version)) {
        $row['update'] = $updates->response[$basename]->new_version;
      }
    }
    $rows[] = $row;
  }
  return $rows;
}

// ── the page ───────────────────────────────────────────────────────────────
function render_page() {
  $rows = get_plugin_rows();
  $items = hub\get_notifications();
  $client = is_client_managed_site();
  ?>
  <style>
    <?php echo \tangible\design\font_faces_css(); ?>
    .tgbl-hub { max-width: 1200px; <?php echo \tangible\design\font_tokens_css(); ?> }
    .tgbl-hub .lbl { font-family:var(--tgbl-font-label); font-size:10.5px; font-weight:600;
      letter-spacing:.14em; text-transform:uppercase; color:#646970; }
    .tgbl-hub-head { display:flex; align-items:center; gap:12px; padding:14px 0 6px; }
    .tgbl-hub-head h1 { font-size:23px; font-weight:400; margin:0; padding:0; }
    .tgbl-mark { display:inline-grid; grid-template-columns:repeat(3,7px); grid-template-rows:repeat(3,7px); gap:1px; }
    .tgbl-mark i { display:block; border-radius:1px; background:#9e9cf7; }
    .tgbl-mark i:nth-child(1){grid-area:1/1}.tgbl-mark i:nth-child(2){grid-area:1/2}
    .tgbl-mark i:nth-child(3){grid-area:1/3}.tgbl-mark i:nth-child(4){grid-area:2/1}
    .tgbl-mark i:nth-child(5){grid-area:2/3}.tgbl-mark i:nth-child(6){grid-area:3/2}
    .tgbl-hub-grid { display:flex; gap:24px; align-items:flex-start; margin-top:14px; }
    .tgbl-hub-main { flex:1; min-width:0; }
    .tgbl-hub-rail { flex:0 0 316px; }
    .tgbl-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; }
    .tgbl-card + .tgbl-card { margin-top:20px; }
    .tgbl-card-h { padding:12px 16px 10px; border-bottom:1px solid #e4e4e7; display:flex; gap:12px; align-items:baseline; }
    .tgbl-hub table { width:100%; border-collapse:collapse; }
    .tgbl-hub td, .tgbl-hub th { text-align:left; padding:11px 16px; border-bottom:1px solid #f0f0f1; font-size:13px; }
    .tgbl-hub tr:last-child td { border-bottom:0; }
    .tgbl-ver { font-family:var(--tgbl-font-data); font-size:11.5px; color:#4265c4; }
    .tgbl-lic::before { content:""; display:inline-block; width:7px; height:7px; border-radius:1px;
      background:#9e9cf7; margin-right:7px; }
    .tgbl-lic--warn::before { background:#fd9597; }
    .tgbl-ncard { background:#fff; border:1px solid #e4e4e7; border-radius:4px; padding:11px 13px; margin:10px 12px; position:relative; }
    .tgbl-ncard h4 { margin:4px 0 5px; font-size:13px; }
    .tgbl-ncard p { margin:0; font-size:12.3px; line-height:1.55; color:#50575e; }
    .tgbl-ncard .tgbl-x { position:absolute; top:6px; right:9px; color:#a7aaad; text-decoration:none; font-size:14px; }
    .tgbl-nfoot { padding:9px 13px; border-top:1px solid #e4e4e7; }
  </style>
  <div class="wrap tgbl-hub">
    <div class="tgbl-hub-head">
      <span class="tgbl-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
      <h1>Tangible</h1>
      <span style="flex:1"></span>
      <span class="lbl"><?php echo count($rows); ?> product<?php echo count($rows) === 1 ? '' : 's'; ?> installed</span>
    </div>

    <div class="tgbl-hub-grid">
      <div class="tgbl-hub-main">
        <div class="tgbl-card">
          <div class="tgbl-card-h"><span class="lbl">installed on this site</span></div>
          <table>
            <?php foreach ($rows as $r) : ?>
            <tr>
              <td style="font-weight:600"><?php echo esc_html($r['title']); ?></td>
              <td class="tgbl-ver"><?php echo esc_html($r['version']); ?></td>
              <td>
                <?php if ($client) : ?>
                  <span class="lbl" style="font-size:9.5px">managed for you</span>
                <?php elseif ($r['license'] === null) : ?>
                  <span style="color:#646970">free — no licence needed</span>
                <?php elseif (in_array($r['license'], ['valid', 'active'], true)) : ?>
                  <span class="tgbl-lic">licence valid</span>
                <?php else : ?>
                  <span class="tgbl-lic tgbl-lic--warn">licence <?php echo esc_html($r['license']); ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['update']) : ?>
                  <a class="button" href="<?php echo esc_url(admin_url('plugins.php')); ?>">Update to <?php echo esc_html($r['update']); ?></a>
                <?php else : ?>
                  <span class="lbl" style="font-size:9.5px">up to date</span>
                <?php endif; ?>
              </td>
              <td><?php if ($r['settings_url']) : ?><a href="<?php echo esc_url($r['settings_url']); ?>">Settings</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?><tr><td>No Tangible plugins registered.</td></tr><?php endif; ?>
          </table>
        </div>
      </div>

      <?php if (!$client) : ?>
      <aside class="tgbl-hub-rail">
        <div class="tgbl-card">
          <div class="tgbl-card-h"><span class="lbl">notifications<?php echo $items ? ' · ' . count($items) : ''; ?></span></div>
          <?php foreach ($items as $item) : ?>
            <div class="tgbl-ncard">
              <a class="tgbl-x" title="Dismiss"
                 href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tangible_hub_dismiss&item=' . rawurlencode($item['id'])), 'tangible_hub_dismiss')); ?>">×</a>
              <span class="lbl" style="font-size:9px"><?php echo esc_html($item['kind']); ?></span>
              <h4><?php echo esc_html($item['title']); ?></h4>
              <?php if (!empty($item['body'])) : ?><p><?php echo esc_html($item['body']); ?></p><?php endif; ?>
              <?php if (!empty($item['action']['url'])) : ?>
                <p style="margin-top:7px"><a href="<?php echo esc_url($item['action']['url']); ?>"><?php echo esc_html($item['action']['label'] ?? 'Open'); ?> →</a></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (empty($items)) : ?>
            <div class="tgbl-ncard" style="border-style:dashed"><p>Nothing new. We'll put release notes and heads-ups here — never admin notices.</p></div>
          <?php endif; ?>
          <div class="tgbl-nfoot"><span class="lbl" style="font-size:9px">one channel · tangible never posts admin notices</span></div>
        </div>
      </aside>
      <?php endif; ?>
    </div>
  </div>
  <?php
}
