<?php
/**
 * Connect, inside the onboarder's Welcome step (Figma "[Core] Free 01
 * Welcome" and "01b Welcome connected").
 *
 * Connect used to be a rail step of its own (`tangible_connect`). Cristian's
 * redesign puts it in Welcome's form card instead: optional, one button, and
 * "Get started" moves on whether or not the site connects. So this file no
 * longer registers a step; Welcome calls the two functions below when the
 * plugin's distribution is FREE (the server's verdict, cached by the
 * updater). One connection per site covers every free Tangible plugin on it.
 *
 * The card has three states:
 *
 *   not connected  title, one sentence, "Connect to tangible.one". The button
 *                  posts Welcome with answer=connect; the handler starts the
 *                  grant and leaves wp-admin by top-level navigation, and the
 *                  return handler (flow.php) brings the admin back to setup.
 *   connected      the green box: "Connected as <account>", Disconnect.
 *   pending        approved on tangible.one, but the platform could not reach
 *                  this site to verify it (a local dev site, a blocked REST
 *                  API). Says why, offers "Check again"; Get started moves on
 *                  without dropping the pending connection, which completes
 *                  whenever verification does.
 */
namespace tangible\connect;

use tangible\onboarding;

/** Why a pending connection is still pending, in words. */
function pending_reason() {
  $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
  $local = function_exists('tangible\\onboarding\\is_dev_site') && onboarding\is_dev_site();
  if ($local) {
    return sprintf('tangible.one has to reach %s to verify it, and a local development site is not reachable from the internet.', $host);
  }
  return sprintf('tangible.one could not reach %s/wp-json/ to verify it. Check that the site is public and its REST API is not blocked.', $host);
}

/** The Connect half of Welcome's form card. */
function render_welcome_section($plugin) {
  $state = get_state();
  $account = (string) ($state['account_name'] ?? '');

  if (is_connected()) :
    ?>
    <h3 class="tgbl-card__title">Connect this site to tangible.one</h3>
    <div class="tgbl-connected" role="status">
      <span class="tgbl-connected__icon"><?php echo onboarding\check_svg('16px'); ?></span>
      <div class="tgbl-connected__body">
        <p class="tgbl-connected__title">Connected as <?php echo esc_html($account !== '' ? $account : 'your tangible.one account'); ?></p>
        <p>Every free Tangible plugin on this site now uses this connection, and your setup answers follow the account.<?php
          // Connected sites are never asked about usage data (the account's Terms
          // are the grant, ADR-011), so this is where they are told (Figma "Free 01b").
          if (defined('tangible\\onboarding\\TERMS_USAGE_DISCLOSURE') && !onboarding\site_telemetry_refused()) {
            echo ' ' . esc_html(onboarding\TERMS_USAGE_DISCLOSURE);
          } ?></p>
        <button type="submit" class="tgbl-textlink" name="do" value="answer:disconnect" formnovalidate>Disconnect</button>
      </div>
    </div>
    <?php
    return;
  endif;

  if (get_token() !== '') :
    ?>
    <h3 class="tgbl-card__title">Waiting for tangible.one to verify this site</h3>
    <p class="tgbl-card__text">You approved the connection<?php
      echo $account !== '' ? ' to <b>' . esc_html($account) . '</b>' : ''; ?>. <?php echo esc_html(pending_reason()); ?>
      It finishes by itself once verification works, so you can get started now.</p>
    <button type="submit" class="tui-button is-style-outline tgbl-card__button" name="do" value="answer:connect_check" formnovalidate>Check again</button>
    <?php
    return;
  endif;
  ?>
  <h3 class="tgbl-card__title">Connect this site to tangible.one</h3>
  <p class="tgbl-card__text">Optional and free. One connection covers every free Tangible plugin on this site,
     so you answer setup questions once instead of once per plugin.</p>
  <button type="submit" class="tui-button is-style-outline tgbl-card__button" name="do" value="answer:connect" formnovalidate>Connect to tangible.one</button>
  <?php
}

/**
 * Welcome's Connect buttons. Every outcome returns false or a WP_Error:
 * none of them is "Get started", so Welcome stays the current step and the
 * card re-renders in its new state.
 */
function handle_welcome_answer($plugin, $answer) {
  if (!current_user_can('manage_options')) return false;
  $plugin_name = $plugin->name ?? '';

  if ($answer === 'connect') {
    $return_to = function_exists('tangible\\onboarding\\get_setup_url') && $plugin
      ? onboarding\get_setup_url($plugin) : admin_url('admin.php?page=tangible-home');
    onboarding\pending_redirect(start_flow(get_current_user_id(), $return_to, $plugin_name));
    return false;
  }

  if ($answer === 'connect_check' && get_token() !== '' && !is_connected()) {
    if (confirm($plugin_name) === 'active') {
      after_connected($plugin_name);
      return false;
    }
    return new \WP_Error('tangible_connect_pending', 'Still waiting: ' . pending_reason());
  }

  if ($answer === 'disconnect') {
    disconnect();
    return false;
  }
  return false;
}
