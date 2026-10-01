<?php
/**
 * The `connect` core onboarder step (plugin-onboarder spec §3).
 *
 * Shown only when the plugin's distribution is FREE (the server's verdict,
 * cached by the updater) and the site is not connected. Site scope: one
 * answer per site — connecting (or skipping) from one free Tangible plugin
 * covers every other one. Optional: Skip keeps the plugin Anonymous.
 *
 * Continue is "Connect to tangible.one": the handler starts the grant and
 * leaves wp-admin by top-level navigation. It returns false so nothing is
 * recorded yet; the return handler marks the step done once the site is
 * actually connected.
 *
 * Pending — approved on tangible.one, but the platform could not reach this
 * site to verify it (a local dev site, a blocked REST API) — is its own state:
 * the step says so plainly, offers "Check again", and Skip moves on without
 * dropping the pending connection, which completes whenever verification does.
 *
 * The id is `tangible_connect`, not `connect`: plugins own the short names, and
 * SearchSync's backend step is already called `connect` (two steps with one id
 * is a fatal in resolve_plan).
 */
namespace tangible\connect;

use tangible\framework;
use tangible\onboarding;

const STEP_ID = 'tangible_connect';

/** Why a pending connection is still pending, in words. */
function pending_reason() {
  $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
  $local = function_exists('tangible\\onboarding\\is_dev_site') && onboarding\is_dev_site();
  if ($local) {
    return sprintf('tangible.one has to reach %s to verify it, and a local development site is not reachable from the internet.', $host);
  }
  return sprintf('tangible.one could not reach %s/wp-json/ to verify it. Check that the site is public and its REST API is not blocked.', $host);
}

add_filter('tangible_onboarding_steps', function ($steps, $facts, $plugin_name = '') {
  if (!function_exists('tangible\\onboarding\\registered_wizards')) return $steps;
  if (empty(onboarding\registered_wizards()[$plugin_name])) return $steps;
  $plugin = function_exists('tangible\\framework\\get_plugin') ? framework\get_plugin($plugin_name) : null;
  if (!onboarding\is_free_distribution($plugin)) return $steps;

  $steps[] = [
    'id'           => STEP_ID,
    'label'        => 'tangible.one',
    'weight'       => 15,               // after licence (10), before consent (20)
    'scope'        => 'site',
    'skippable'    => true,
    'needed'       => function () { return !is_connected(); },
    'skip_note'    => 'connected',
    'submit_label' => 'Connect to tangible.one',
    'render'       => function () {
      $pending = get_token() !== '' && !is_connected();
      if ($pending) :
        $account = (string) (get_state()['account_name'] ?? '');
        ?>
        <h2>Approved — waiting for tangible.one to verify this site</h2>
        <p class="step-intro">You approved the connection<?php
          echo $account !== '' ? ' to <b>' . esc_html($account) . '</b>' : ''; ?>. One step is left:
          <?php echo esc_html(pending_reason()); ?></p>
        <div class="tgbl-dbl"></div>
        <ul class="tgbl-next">
          <li>Check again once the site is reachable.</li>
          <li>Or skip for now: the connection stays waiting and finishes by itself when verification succeeds.</li>
          <li>Connecting again starts a fresh request.</li>
        </ul>
        <p><button type="submit" class="button" name="connect_check" value="1">Check again</button></p>
        <?php
        return;
      endif;
      ?>
      <h2>Connect this site to tangible.one</h2>
      <p class="step-intro">Optional. Connecting links this site — and every free Tangible plugin
         on it — to your tangible.one account. Your answers then follow the account, so other
         sites and plugins don't ask again.</p>
      <div class="tgbl-dbl"></div>
      <ul class="tgbl-next">
        <li>You approve it on tangible.one, signed in as yourself.</li>
        <li>No licence key, and licensed plugins are not affected.</li>
        <li>Disconnect any time from the Tangible Home page or your tangible.one account.</li>
      </ul>
      <?php
    },
    'handle' => function ($plugin) use ($plugin_name) {
      if (!current_user_can('manage_options')) return false;
      // "Check again" on a pending connection: ask the platform to verify.
      if (!empty($_POST['connect_check']) && get_token() !== '' && !is_connected()) {
        if (confirm($plugin_name) === 'active') {
          after_connected($plugin_name);
          return true;
        }
        return new \WP_Error('tangible_connect_pending', 'Still waiting: ' . pending_reason());
      }
      $return_to = function_exists('tangible\\onboarding\\get_setup_url') && $plugin
        ? onboarding\get_setup_url($plugin) : admin_url('admin.php?page=tangible-home');
      onboarding\pending_redirect(start_flow(get_current_user_id(), $return_to, $plugin_name));
      return false;   // recorded by the return handler once actually connected
    },
  ];
  return $steps;
}, 6, 3);
