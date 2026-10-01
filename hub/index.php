<?php
/**
 * Tangible Hub — the landing page the shared "Tangible" menu never had.
 * It sits under Settings → Tangible Hub; the admin-bar mark that used to lead
 * here is switched off for now (filter tangible_hub_show_admin_bar_mark).
 *
 * One page for everything Tangible on a site (design-preview/tangible-hub-hybrid.html):
 * installed plugins with their licence or connection state, usage-data
 * answer and setup progress; the site's Connect band; the notification rail
 * (the one owned channel — notifications.php); the site's steward answer.
 *
 *   rows.php           facts → what each row says (pure, tested)
 *   page.php           the page, server-rendered
 *   hub.css            the hybrid skin
 *   notifications.php  the rail's store
 *
 * The Hub renders facts and stores nothing of its own, beyond dismissals
 * (notifications, and one admin's "Not now" on the Connect band). Sources:
 *   plugins   framework::$state->plugins (whatever register_plugin() saw)
 *   licences  the updater's licence status + distribution verdict
 *   connect   connect/ — one site token, shared by every free plugin
 *   setup     onboarding\resolve_plan(), resolved from facts on every load
 *   updates   core's update_plugins transient, hydrated by the updater
 *
 * Steward rule: the answer is recorded (onboarding\get_steward) and shown with
 * a switch, but agency-mode muting is deferred — see is_client_managed_site().
 */
namespace tangible\hub;

use tangible\framework;
use tangible\hub;

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/rows.php';
require_once __DIR__ . '/page.php';

const PAGE = 'tangible-home';

/**
 * The steward answer, recorded by onboarding. DELIBERATELY NOT ENFORCED here
 * yet: the first live test showed a wizard answer silently amputating the bar
 * mark and the rail with no in-place explanation or undo — so the hub stays
 * whole for both answers until agency-mode muting is designed properly
 * (per-surface policy, a visible "client-managed" state line, and the
 * dashboard-side control to flip it). The flag keeps being recorded; only
 * the muting is deferred.
 */
function is_client_managed_site() {
  // One site answer, shared by every Tangible plugin (onboarding/steps.php).
  if (function_exists('tangible\\onboarding\\get_steward')) {
    return \tangible\onboarding\get_steward() === 'client';
  }
  $value = get_option('tangible_site_steward', null);
  return $value === 'client';
}

// ── menu: Settings → Tangible Hub ─────────────────────────────────────────
// For now the Hub is not the first item under the shared Tangible top-level;
// it lives under Settings. The page slug is unchanged, so admin.php?page=… links keep working.
add_action('admin_menu', function () {
  add_submenu_page(
    'options-general.php',
    'Tangible Hub',
    'Tangible Hub',
    'manage_options',
    PAGE,
    'tangible\\hub\\render_page'
  );
});

// ── the admin-bar mark ─────────────────────────────────────────────────────
// Hidden for now. Return true from this filter to bring it back.
function show_admin_bar_mark() {
  return (bool) apply_filters('tangible_hub_show_admin_bar_mark', false);
}

add_action('admin_bar_menu', function ($bar) {
  if (!show_admin_bar_mark()) return;
  if (!current_user_can('manage_options')) return;

  $count = hub\get_unread_count();
  // The official six-tile logo (design/tangible-logo.svg geometry), inlined as
  // SVG: the admin bar's own item CSS scrambles a CSS grid, but has nothing to
  // break in an SVG. Grey at rest, brand colours on hover.
  $tiles = '<svg class="tgbl-bar-mark" width="16" height="16" viewBox="0 0 99 99" aria-hidden="true">'
         . '<path d="M0 0h33v33H0z"/><path d="M33 0h33v33H33z"/><path d="M66 0h33v33H66z"/>'
         . '<path d="M0 33h33v33H0z"/><path d="M66 33h33v33H66z"/><path d="M33 66h33v33H33z"/>'
         . '</svg>';
  // Inline pill, the bar's own vocabulary (the Yoast convention), in the
  // accent — never red: the mark is the quietest thing in the bar. No pill at
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
  if (!show_admin_bar_mark()) return;
  ?><style>
    #wpadminbar .tgbl-bar-mark { vertical-align:middle; margin-top:-2px; }
    #wpadminbar .tgbl-bar-mark path { fill:#c3c4c7; }
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(1){fill:#262262}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(2){fill:#662d91}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(3){fill:#9f1f63}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(4){fill:#2e3192}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(5){fill:#ec008c}
    #wpadminbar #wp-admin-bar-tangible-hub:hover .tgbl-bar-mark path:nth-child(6){fill:#02aeef}
    #wpadminbar .tgbl-bar-pill { background:#6359D6; color:#fff; font-size:10px; font-weight:600;
      line-height:16px; min-width:16px; padding:0 4px; border-radius:8px; display:inline-block;
      text-align:center; margin-left:6px; vertical-align:middle; }
  </style><?php
});

// ── actions — every one manage_options + its own nonce ─────────────────────
function back_to_hub() {
  wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . PAGE));
  exit;
}

add_action('admin_post_tangible_hub_dismiss', function () {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);
  check_admin_referer('tangible_hub_dismiss');
  hub\dismiss_notification(sanitize_text_field(wp_unslash($_GET['item'] ?? '')));
  back_to_hub();
});

add_action('admin_post_tangible_hub_dismiss_all', function () {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);
  check_admin_referer('tangible_hub_dismiss_all');
  foreach (hub\get_notifications() as $item) hub\dismiss_notification($item['id']);
  back_to_hub();
});

// The steward line's switch: the same site option the onboarder writes.
add_action('admin_post_tangible_hub_steward', function () {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);
  check_admin_referer('tangible_hub_steward');
  $value = sanitize_key(wp_unslash($_POST['steward'] ?? ''));
  if (function_exists('tangible\\onboarding\\set_steward')) \tangible\onboarding\set_steward($value);
  back_to_hub();
});

// "Not now" on the Connect band: this admin, 30 days.
add_action('admin_post_tangible_hub_connect_snooze', function () {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);
  check_admin_referer('tangible_hub_connect_snooze');
  update_user_meta(get_current_user_id(), BAND_SNOOZE_META, time() + BAND_SNOOZE_DAYS * DAY_IN_SECONDS);
  back_to_hub();
});
