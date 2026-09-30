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
 * Surfaces: the "This site" block in Tangible Home, and any plugin's own
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
 * Record the site's answer and try to deliver it now through any registered
 * Tangible plugin (the outbox is site-wide; the first delivery marks it synced).
 */
function set_site_telemetry($send) {
  record_consent_answer(
    'telemetry_extended',
    $send ? 'granted' : 'declined',
    $send ? SITE_TELEMETRY_ON_TEXT : SITE_TELEMETRY_OFF_TEXT
  );
  if (!function_exists('tangible\\framework\\get_plugin')) return;
  foreach (array_keys(registered_wizards()) as $name) {
    $plugin = framework\get_plugin($name);
    if ($plugin) attempt_consent_sync($plugin);
  }
}

/**
 * The setting, for a plugin's settings page or Tangible Home: one line of
 * state and one button that flips it. Posts to admin-post.php and returns to
 * the page it was rendered on.
 */
function render_site_telemetry_setting($text_class = 'tgbl-site-telemetry', $button_class = 'button-link') {
  $refused = site_telemetry_refused();
  ?>
  <p class="<?php echo esc_attr($text_class); ?>">
    <?php echo $refused
      ? 'Usage data is <b>not sent</b> from this site, by any Tangible plugin.'
      : 'Tangible plugins on this site send usage data — performance timings, which features are used, content counts. Never content, users or visitors.'; ?>
  </p>
  <form class="inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="<?php echo esc_attr(SITE_TELEMETRY_ACTION); ?>" />
    <input type="hidden" name="send" value="<?php echo $refused ? '1' : '0'; ?>" />
    <?php wp_nonce_field(SITE_TELEMETRY_ACTION); ?>
    <button type="submit" class="<?php echo esc_attr($button_class); ?>"><?php
      echo esc_html($refused ? 'Send usage data from this site' : "Don't send usage data from this site"); ?></button>
  </form>
  <?php
}

add_action('admin_post_' . SITE_TELEMETRY_ACTION, function () {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);
  check_admin_referer(SITE_TELEMETRY_ACTION);
  set_site_telemetry(($_POST['send'] ?? '') === '1');
  wp_safe_redirect(wp_get_referer() ?: admin_url());
  exit;
});
