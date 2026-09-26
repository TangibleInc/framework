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
 */
namespace tangible\connect;

use tangible\framework;
use tangible\onboarding;

add_filter('tangible_onboarding_steps', function ($steps, $facts, $plugin_name = '') {
  if (!function_exists('tangible\\onboarding\\registered_wizards')) return $steps;
  if (empty(onboarding\registered_wizards()[$plugin_name])) return $steps;
  $plugin = function_exists('tangible\\framework\\get_plugin') ? framework\get_plugin($plugin_name) : null;
  if (!onboarding\is_free_distribution($plugin)) return $steps;

  $steps[] = [
    'id'           => 'connect',
    'label'        => 'connect',
    'weight'       => 15,               // after licence (10), before consent (20)
    'scope'        => 'site',
    'skippable'    => true,
    'needed'       => function () { return !is_connected(); },
    'skip_note'    => 'connected',
    'submit_label' => 'Connect to tangible.one',
    'render'       => function () {
      $pending = get_token() !== '' && !is_connected();
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
      <?php if ($pending) : ?>
        <p class="whisper">A connection is waiting for tangible.one to verify this site. Continuing starts a fresh one.</p>
      <?php endif;
    },
    'handle' => function ($plugin) use ($plugin_name) {
      if (!current_user_can('manage_options')) return false;
      $return_to = function_exists('tangible\\onboarding\\get_setup_url') && $plugin
        ? onboarding\get_setup_url($plugin) : admin_url('admin.php?page=tangible-home');
      onboarding\pending_redirect(start_flow(get_current_user_id(), $return_to, $plugin_name));
      return false;   // recorded by the return handler once actually connected
    },
  ];
  return $steps;
}, 6, 3);
