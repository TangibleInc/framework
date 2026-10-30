<?php
/**
 * "Don't send usage data from this site" — the per-site refusal (ADR-011 §3).
 *
 * One answer per site, shared by every Tangible plugin on it, kept in the same
 * site-wide consent outbox entry the onboarder's consent step writes
 * (`telemetry_extended`, the wire key's old name). Changing it takes effect
 * locally at once and is delivered to tangible.one like any consent answer:
 * keyed and connected sites report it as the site's exclusion for the account,
 * an anonymous site as evidence.
 *
 * Surfaces: the "This site" block in Tangible Hub, and any plugin's own
 * settings through `render_site_telemetry_setting()` — the refusal must be as
 * easy to find as the plugin's settings.
 */
namespace tangible\onboarding;

use tangible\framework;

const SITE_TELEMETRY_ACTION = 'tangible_site_telemetry';

const SITE_TELEMETRY_ON_TEXT =
  'Send usage data from this site to Tangible (site setting, telemetry v2)';
const SITE_TELEMETRY_OFF_TEXT =
  "Don't send usage data from this site to Tangible (site setting, telemetry v2)";

/** The site said no, in the wizard or in a setting. */
function site_telemetry_refused() {
  return get_consent_answer('telemetry_extended') === 'declined';
}

/**
 * What this site does with usage data — one answer for every Tangible plugin
 * on it (ADR-011), decided from the site's own answer and whether an account's
 * Terms cover the site. Pure, so the Hub's wording is testable.
 *
 *   refused  the site said no, in the wizard or a setting: nothing is sent,
 *            whatever covers it
 *   granted  the site said yes in the onboarder (an anonymous site's only grant)
 *   terms    a licence key or the tangible.one connection covers the site: the
 *            account's Terms are the grant, nobody here was asked
 *   none     anonymous and never asked: nothing is sent
 */
function site_telemetry_state($answer, $covered_by_terms) {
  if ($answer === 'declined') return 'refused';
  if ($answer === 'granted') return 'granted';
  return $covered_by_terms ? 'terms' : 'none';
}

/** An account's Terms cover this site: it is connected, or an onboarding plugin holds an active licence. */
function site_covered_by_terms() {
  if (function_exists('tangible\\connect\\get_active_token') && \tangible\connect\get_active_token() !== '') {
    return true;
  }
  if (!function_exists('tangible\\framework\\get_plugin') || !function_exists('tangible\\updater\\get_license_status')) {
    return false;
  }
  foreach (array_keys(registered_wizards()) as $name) {
    $plugin = framework\get_plugin($name);
    if ($plugin && in_array(\tangible\updater\get_license_status($plugin), ['valid', 'active'], true)) return true;
  }
  return false;
}

/**
 * Record the site's answer and try to deliver it now through any registered
 * Tangible plugin (the outbox is site-wide; the first delivery marks it synced).
 */
function set_site_telemetry($send) {
  record_consent_answer(
    'telemetry_extended',
    $send ? 'granted' : 'declined',
    $send ? SITE_TELEMETRY_ON_TEXT : SITE_TELEMETRY_OFF_TEXT
  );
  // Excluding the site clears its relationship on tangible.one, so turning
  // usage data back on must report it again.
  if (!$send) forget_relationship_report();
  deliver_site_answers();
}

/**
 * What usage data is, for a site that sends it (Allison's copy, 2026-10-30):
 * the Hub's "This site" block and the per-plugin setting both show it. The
 * last clause discloses the site relationship (the steward answer), reported
 * to tangible.one while usage data is on (ADR-014 there). `$terms`: the site
 * sends under an account's Terms (keyed or connected), so the description
 * says so first.
 */
function usage_data_description($terms) {
  return $terms
    ? __("Shared under your tangible.one terms. Helps us make Tangible plugins work better on sites like yours. Shares the basics about this site: the software versions it runs, its theme and other plugins, its hosting, its page speed, how many users and how much content it has, and whether you built it for a client. Never what's in your content, and never who your users or visitors are.", 'tangible-framework')
    : __("Helps us make Tangible plugins work better on sites like yours. Shares the basics about this site: the software versions it runs, its theme and other plugins, its hosting, its page speed, how many users and how much content it has, and whether you built it for a client. Never what's in your content, and never who your users or visitors are.", 'tangible-framework');
}

/**
 * The setting, for a plugin's settings page or Tangible Hub: one line of
 * state and one button that flips it. Posts to admin-post.php and returns to
 * the page it was rendered on.
 */
function render_site_telemetry_setting($text_class = 'tgbl-site-telemetry', $button_class = 'button-link') {
  $state = site_telemetry_state(get_consent_answer('telemetry_extended'), site_covered_by_terms());
  $sending = in_array($state, ['granted', 'terms'], true);
  $text = [
    /* translators: 1: start of the emphasis, 2: its end */
    'refused' => sprintf(__('Usage data is %1$snot sent%2$s from this site, by any Tangible plugin.', 'tangible-framework'), '<b>', '</b>'),
    /* translators: 1: start of the emphasis, 2: its end */
    'none'    => sprintf(__('Usage data is %1$snot sent%2$s from this site. Nobody has been asked yet.', 'tangible-framework'), '<b>', '</b>'),
    'granted' => usage_data_description(false),
    'terms'   => usage_data_description(true),
  ][ $state ];
  ?>
  <p class="<?php echo esc_attr($text_class); ?>"><?php echo wp_kses($text, ['b' => []]); ?></p>
  <form class="inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="<?php echo esc_attr(SITE_TELEMETRY_ACTION); ?>" />
    <input type="hidden" name="send" value="<?php echo $sending ? '0' : '1'; ?>" />
    <?php wp_nonce_field(SITE_TELEMETRY_ACTION); ?>
    <button type="submit" class="<?php echo esc_attr($button_class); ?>"><?php
      echo esc_html($sending
        ? __("Don't send usage data from this site", 'tangible-framework')
        : __('Send usage data from this site', 'tangible-framework')); ?></button>
  </form>
  <?php
}

add_action('admin_post_' . SITE_TELEMETRY_ACTION, function () {
  if (!current_user_can('manage_options')) wp_die(esc_html__('Nope', 'tangible-framework'), 403);
  check_admin_referer(SITE_TELEMETRY_ACTION);
  set_site_telemetry(($_POST['send'] ?? '') === '1');
  wp_safe_redirect(wp_get_referer() ?: admin_url());
  exit;
});
