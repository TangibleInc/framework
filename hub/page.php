<?php
/**
 * Tangible Hub — the page. Server-rendered, no JS; every control is a link
 * or a POST form to admin-post.php. What each row says is decided in
 * rows.php; this file only draws it.
 *
 * It is a wp-admin page and looks like one: a .wrap with core's <h1>, and
 * @tangible/ui's own DOM (Card, Chip, Notice, Progress, Button) wearing the
 * WordPress admin theme (wp-admin-theme.css) — the admin colour scheme, the
 * system font, 13px controls. No brand faces, no skin of its own.
 */
namespace tangible\hub;

use tangible\framework;
use tangible\hub;

function render_page() {
  if (!current_user_can('manage_options')) wp_die(esc_html__('Nope', 'tangible-framework'), 403);

  $plugins = array_values(framework::$state->plugins ?? []);
  $rows = [];
  $has_free = false;
  $updates = 0;
  foreach ($plugins as $plugin) {
    $facts = gather_facts($plugin);
    $rows[] = [ 'plugin' => $plugin, 'facts' => $facts, 'state' => row_state($facts) ];
    if ($facts['free'] === true && !$facts['has_key']) $has_free = true;
    if ($facts['update']) $updates++;
  }

  $connect = connect_status();
  $snoozed = (int) get_user_meta(get_current_user_id(), BAND_SNOOZE_META, true);
  $band = band_state($connect, $has_free, $snoozed, time());
  $steward = function_exists('tangible\\onboarding\\get_steward') ? \tangible\onboarding\get_steward() : null;
  $items = hub\get_notifications();
  $flash = connect_flash();

  foreach (['tui.css', 'wp-admin-theme.css', 'hub.css'] as $file) : ?>
    <link rel="stylesheet" href="<?php echo esc_url(asset_url($file)); ?>" />
  <?php endforeach; ?>
  <div class="wrap tui-interface tgbl-hub">
    <h1><?php esc_html_e('Tangible Hub', 'tangible-framework'); ?></h1>
    <p class="tgbl-hub__site"><?php echo wp_kses(site_line($steward, $connect), [ 'b' => [] ]); ?></p>
    <?php // Core moves admin notices to after this marker, not between <h1> and the site line. ?>
    <hr class="wp-header-end" />

    <?php if ($flash) : ?>
      <section class="tui-notice is-theme-success" role="status">
        <div class="tui-notice__inner"><div class="tui-notice__body"><?php echo esc_html($flash); ?></div></div>
      </section>
    <?php endif; ?>
    <?php if ($band) render_band($band); ?>

    <div class="tgbl-hub__grid">
      <article class="tui-card is-style-solid tgbl-hub__plugins" aria-labelledby="tgbl-hub-installed">
        <div class="tui-card__inner">
          <div class="tui-card__head">
            <h2 id="tgbl-hub-installed"><?php esc_html_e('Your Tangible plugins', 'tangible-framework'); ?></h2>
            <span class="tgbl-hub__meta"><?php echo esc_html(installed_line(count($rows), $updates)); ?></span>
          </div>
          <div class="tui-card__body">
            <?php if ($rows) : ?>
              <ul class="tgbl-hub__rows">
                <?php foreach ($rows as $row) render_row($row['plugin'], $row['facts'], $row['state']); ?>
              </ul>
            <?php else : ?>
              <p><?php esc_html_e('No Tangible plugins registered on this site.', 'tangible-framework'); ?></p>
            <?php endif; ?>
          </div>
        </div>
      </article>

      <div class="tgbl-hub__aside">
        <?php render_notifications($items); ?>
        <?php render_site_block($steward, $connect); ?>
      </div>
    </div>
    <?php
    // Cards contributed by other modules, under the grid.
    do_action('tangible_hub_main_cards');
    ?>
  </div>
  <?php
}

/**
 * URL of a stylesheet in this module, versioned by the framework version AND
 * the file's mtime: hosts cache CSS for years, so an edit without a framework
 * version bump would otherwise never reach a browser.
 */
function asset_url($file) {
  $mtime = @filemtime(__DIR__ . '/' . $file);
  return set_url_scheme(trailingslashit(framework\module_url(__FILE__)) . $file)
    . '?v=' . rawurlencode((framework::$state->version ?? '1') . ($mtime ? '.' . $mtime : ''));
}

// ── pieces ────────────────────────────────────────────────────────────────

function render_band($band) {
  $pending = $band['status'] === 'pending';
  if ($pending) {
    $title = __('Finishing the connection to tangible.one', 'tangible-framework');
    $body = __('tangible.one has not verified this site yet. It calls back to this site\'s REST API; check that it is reachable, then try again.', 'tangible-framework');
  } elseif ($band['has_free']) {
    $title = __('Connect this site to your tangible.one account', 'tangible-framework');
    $body = __('Optional, and free. One connection covers every free Tangible plugin on this site, now and later, so setup answers follow your account instead of being asked again here. Licensed plugins keep working through their keys. If you are already signed in at tangible.one, it is one click.', 'tangible-framework');
  } else {
    $title = __('Connect this site to your tangible.one account', 'tangible-framework');
    $body = __('See this site in your tangible.one account, and any free Tangible plugin you add later takes its setup answers from it. Your licensed plugins already work through their keys; connecting changes nothing for them.', 'tangible-framework');
  }
  ?>
  <section class="tui-notice is-theme-info tgbl-hub__band" aria-labelledby="tgbl-hub-connect">
    <div class="tui-notice__inner">
      <div class="tui-notice__head"><h2 id="tgbl-hub-connect" class="tui-notice__title"><?php echo esc_html($title); ?></h2></div>
      <div class="tui-notice__body"><?php echo esc_html($body); ?></div>
      <div class="tui-notice__foot">
        <?php if ($pending) : ?>
          <?php post_form('tangible_connect_confirm', __('Check again', 'tangible-framework'), button_class('primary')); ?>
          <?php post_form('tangible_connect_disconnect', __('Cancel', 'tangible-framework'), button_class('link')); ?>
        <?php else : ?>
          <?php connect_form(__('Connect', 'tangible-framework'), button_class('primary')); ?>
          <?php post_form('tangible_hub_connect_snooze', __('Not now', 'tangible-framework'), button_class('link')); ?>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php
}

function render_row($plugin, $f, $s) {
  $a = $s['account'];
  $setup = $s['setup'];
  $settings_url = function_exists('tangible\\framework\\get_plugin_settings_page_url')
    ? framework\get_plugin_settings_page_url($plugin) : '';
  ?>
  <li class="tgbl-hub__row">
    <div class="tgbl-hub__name">
      <h3><?php echo esc_html($f['title']); ?></h3>
      <p class="tgbl-hub__meta"><?php
        $meta = [];
        if ($f['version'] !== '') $meta[] = esc_html($f['version']);
        if ($f['free'] === true && !$f['has_key']) $meta[] = esc_html__('free', 'tangible-framework');
        /* translators: %s: version number */
        if ($f['update']) $meta[] = '<b>' . esc_html(sprintf(__('%s available', 'tangible-framework'), $f['update'])) . '</b>';
        echo implode(' · ', $meta);
      ?></p>
    </div>

    <div class="tgbl-hub__account">
      <span class="tui-visually-hidden"><?php echo esc_html($a['key']); ?>:</span>
      <?php render_chip($a); ?>
      <?php if ($a['sub'] !== '' || $a['action'] === 'connect') : ?>
        <p class="tgbl-hub__meta"><?php echo esc_html($a['sub']); ?>
          <?php if ($a['action'] === 'connect') connect_form(__('Connect', 'tangible-framework'), button_class('link')); ?></p>
      <?php endif; ?>
    </div>

    <div class="tgbl-hub__actions">
      <?php if ($setup && $setup['done']) : ?>
        <span class="tgbl-hub__meta"><?php esc_html_e('Set up', 'tangible-framework'); ?></span>
      <?php elseif ($setup) :
        /* translators: %s: number of setup steps left */
        $left = sprintf(_n('%s step left', '%s steps left', $setup['left'], 'tangible-framework'), number_format_i18n($setup['left'])); ?>
        <div class="tgbl-hub__setup">
          <div role="progressbar" aria-valuenow="<?php echo (int) $setup['pct']; ?>" aria-valuemin="0" aria-valuemax="100"
               aria-label="<?php esc_attr_e('Setup', 'tangible-framework'); ?>"
               class="tui-progress is-mode-line is-size-sm"
               style="--tui-progress-value:<?php echo (int) $setup['pct']; ?>;--tui-progress-max:100;--tui-progress-pct:<?php echo (int) $setup['pct']; ?>">
            <div class="tui-progress__track"><div class="tui-progress__bar"></div></div>
          </div>
          <span class="tgbl-hub__meta"><?php echo esc_html($left); ?></span>
        </div>
      <?php endif; ?>
      <?php render_primary($plugin, $f, $s['primary'], $settings_url); ?>
    </div>
  </li>
  <?php
}

function render_primary($plugin, $f, $primary, $settings_url) {
  switch ($primary) {
    case 'resume':
      if (function_exists('tangible\\onboarding\\get_setup_url')) {
        echo '<a class="' . esc_attr(button_class('primary', 'sm')) . '" href="' . esc_url(\tangible\onboarding\get_setup_url($plugin)) . '"><span>'
          . esc_html__('Resume setup', 'tangible-framework') . '</span></a>';
      }
      return;
    case 'enter_key':
      if ($settings_url) {
        echo '<a class="' . esc_attr(button_class('primary', 'sm')) . '" href="' . esc_url(framework\get_plugin_settings_page_url($plugin, 'license')) . '"><span>'
          . esc_html__('Enter key', 'tangible-framework') . '</span></a>';
      }
      return;
    case 'renew':
      echo '<a class="' . esc_attr(button_class('outline', 'sm')) . '" href="' . esc_url(app_url('/licensing')) . '" target="_blank" rel="noopener"><span>'
        . esc_html__('Renew', 'tangible-framework') . '</span><span class="tui-visually-hidden"> ' . esc_html__('(opens in a new tab)', 'tangible-framework') . '</span></a>';
      return;
    case 'update':
      $url = update_url($plugin);
      if ($url) {
        echo '<a class="' . esc_attr(button_class('outline', 'sm')) . '" href="' . esc_url($url) . '"><span>' . esc_html__('Update', 'tangible-framework') . '</span></a>';
        return;
      }
      // fall through to Open when this user may not update plugins
    default:
      if ($settings_url) {
        /* translators: %s: plugin name — completes the visible "Open" for screen readers */
        echo '<a class="' . esc_attr(button_class('link', 'sm')) . '" href="' . esc_url($settings_url) . '"><span>' . esc_html__('Open', 'tangible-framework')
          . '</span><span class="tui-visually-hidden"> ' . esc_html($f['title']) . '</span></a>';
      }
  }
}

/** The licence state as a TUI Chip; rows.php's five states onto TUI's themes. */
function render_chip($c) {
  $theme = [
    'ok'   => 'success',
    'need' => 'warning',
    'bad'  => 'danger',
    'free' => 'primary',
    'off'  => 'secondary',
  ][ $c['chip'] ] ?? 'secondary';
  echo '<span class="tui-chip is-size-sm is-theme-' . esc_attr($theme) . '"><span class="tui-chip__text">' . esc_html($c['text']) . '</span></span>';
}

function render_notifications($items) {
  ?>
  <article class="tui-card is-style-solid" aria-labelledby="tgbl-hub-notifications">
    <div class="tui-card__inner">
      <div class="tui-card__head">
        <h2 id="tgbl-hub-notifications"><?php esc_html_e('Notifications', 'tangible-framework'); ?></h2>
        <?php if ($items) : ?>
          <span class="tui-chip is-size-sm is-theme-primary"><span class="tui-chip__text"><?php echo esc_html(number_format_i18n(count($items))); ?></span></span>
          <?php post_form('tangible_hub_dismiss_all', __('Dismiss all', 'tangible-framework'), button_class('link', 'sm') . ' tgbl-hub__end'); ?>
        <?php endif; ?>
      </div>
      <div class="tui-card__body">
        <?php if ($items) : ?>
          <ul class="tgbl-hub__notes">
            <?php foreach ($items as $item) : ?>
              <li class="tgbl-hub__note">
                <h3><?php echo esc_html($item['title']); ?></h3>
                <?php if (!empty($item['body']) || !empty($item['action']['url'])) : ?>
                  <p><?php echo esc_html($item['body'] ?? ''); ?>
                    <?php if (!empty($item['action']['url'])) : ?>
                      <a href="<?php echo esc_url($item['action']['url']); ?>"><?php echo esc_html($item['action']['label'] ?? __('Open', 'tangible-framework')); ?></a>
                    <?php endif; ?></p>
                <?php endif; ?>
                <p class="tgbl-hub__meta"><?php
                  /* translators: 1: notification kind, e.g. "tip"; 2: how long ago, e.g. "3 days" */
                  echo esc_html(sprintf(__('%1$s · %2$s ago', 'tangible-framework'), kind_label($item['kind']), human_time_diff((int) $item['created']))); ?></p>
                <a class="<?php echo esc_attr(button_class('link', 'sm')); ?> tgbl-hub__dismiss"
                   href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tangible_hub_dismiss&item=' . rawurlencode($item['id'])), 'tangible_hub_dismiss')); ?>"><span><?php
                  esc_html_e('Dismiss', 'tangible-framework'); ?></span><span class="tui-visually-hidden"> <?php echo esc_html($item['title']); ?></span></a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else : ?>
          <p class="tgbl-hub__meta"><?php esc_html_e('Nothing new. Release notes and heads-ups land here — Tangible never posts admin notices.', 'tangible-framework'); ?></p>
        <?php endif; ?>
      </div>
    </div>
  </article>
  <?php
  // "From tangible.one" (a releases feed per installed asset) is deliberately
  // absent: there is no endpoint for it yet. TODO(hub): add the block when
  // the platform serves one — not before (no ghost UI).
}

function render_site_block($steward, $connect) {
  $state = function_exists('tangible\\connect\\get_state') ? \tangible\connect\get_state() : [];
  ?>
  <article class="tui-card is-style-solid" id="tgbl-hub-site" aria-labelledby="tgbl-hub-site-title">
    <div class="tui-card__inner">
      <div class="tui-card__head"><h2 id="tgbl-hub-site-title"><?php esc_html_e('This site', 'tangible-framework'); ?></h2></div>
      <div class="tui-card__body">
        <?php // The steward answer lives on this site only (never on tangible.one) and nothing acts on it yet
        // (see is_client_managed_site), so the copy records the answer and promises no effect. ?>
        <?php if ($steward === 'client') : ?>
          <p><?php echo wp_kses(__('You told us this site is <b>built for a client</b>. One answer for every Tangible plugin here.', 'tangible-framework'), [ 'b' => [] ]); ?></p>
          <p><?php post_form('tangible_hub_steward', __('Me or my team look after it', 'tangible-framework'), button_class('link'), [ 'steward' => 'team' ]); ?></p>
        <?php elseif ($steward === 'team') : ?>
          <p><?php echo wp_kses(__('You told us <b>you or your team</b> look after this site. One answer for every Tangible plugin here.', 'tangible-framework'), [ 'b' => [] ]); ?></p>
          <p><?php post_form('tangible_hub_steward', __('I\'m building this for a client', 'tangible-framework'), button_class('link'), [ 'steward' => 'client' ]); ?></p>
        <?php else : ?>
          <p><?php esc_html_e('Who looks after this site? One answer for every Tangible plugin here.', 'tangible-framework'); ?></p>
          <p class="tgbl-hub__inline">
            <?php post_form('tangible_hub_steward', __('Me or my team', 'tangible-framework'), button_class('outline', 'sm'), [ 'steward' => 'team' ]); ?>
            <?php post_form('tangible_hub_steward', __('A client', 'tangible-framework'), button_class('outline', 'sm'), [ 'steward' => 'client' ]); ?>
          </p>
        <?php endif; ?>
        <?php // The per-site usage-data refusal (ADR-011 §3), one answer for every Tangible plugin here.
        if (function_exists('tangible\\onboarding\\render_site_telemetry_setting')) {
          \tangible\onboarding\render_site_telemetry_setting('tgbl-hub__telemetry', button_class('link'));
        } ?>
      </div>
    </div>
  </article>

  <article class="tui-card is-style-solid" aria-labelledby="tgbl-hub-one">
    <div class="tui-card__inner">
      <div class="tui-card__head"><h2 id="tgbl-hub-one">tangible.one</h2></div>
      <div class="tui-card__body">
        <?php if ($connect === 'active') : ?>
          <p><?php
            $who = '<b>' . esc_html(($state['account_name'] ?? '') ?: __('your account', 'tangible-framework')) . '</b>';
            if (!empty($state['owner_email_masked'])) $who .= ' (' . esc_html($state['owner_email_masked']) . ')';
            /* translators: %s: the tangible.one account name, in bold, and its masked email */
            echo sprintf(esc_html__('Connected as %s. Free Tangible plugins here take their setup answers from this account.', 'tangible-framework'), $who); ?></p>
          <p class="tgbl-hub__inline">
            <a class="<?php echo esc_attr(button_class('link')); ?>" href="<?php echo esc_url(app_url('/websites/connected')); ?>" target="_blank" rel="noopener"><span><?php
              esc_html_e('Manage', 'tangible-framework'); ?></span><span class="tui-visually-hidden"> <?php esc_html_e('(opens in a new tab)', 'tangible-framework'); ?></span></a>
            <?php post_form('tangible_connect_disconnect', __('Disconnect', 'tangible-framework'), button_class('link')); ?>
          </p>
        <?php elseif ($connect === 'pending') : ?>
          <p><?php esc_html_e('Waiting for tangible.one to verify this site.', 'tangible-framework'); ?></p>
        <?php else : ?>
          <p><?php esc_html_e('Not connected. Licensed plugins work through their keys either way.', 'tangible-framework'); ?></p>
          <p><?php connect_form(__('Connect this site', 'tangible-framework'), button_class('link')); ?></p>
        <?php endif; ?>
      </div>
    </div>
  </article>
  <?php
}

// ── small helpers ─────────────────────────────────────────────────────────

/** A TUI Button's classes: primary (filled), outline, or link; md or sm. */
function button_class($style, $size = 'md') {
  return 'tui-button is-size-' . $size . ' is-theme-primary'
    . ($style === 'outline' ? ' is-style-outline' : ($style === 'link' ? ' is-style-link' : ''));
}

/** A POST to admin-post.php, nonce'd by its own action name. */
function post_form($action, $label, $class, $fields = []) {
  ?><form class="tgbl-hub__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>" />
    <?php foreach ($fields as $k => $v) : ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" /><?php endforeach; ?>
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce($action)); ?>" />
    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'] ?? '')); ?>" />
    <button type="submit" class="<?php echo esc_attr($class); ?>"><span><?php echo esc_html($label); ?></span></button>
  </form><?php
}

/** Connect's own start action (connect/flow.php), returning here. */
function connect_form($label, $class) {
  post_form('tangible_connect_start', $label, $class, [ 'return_to' => url() ]);
}

function connect_flash() {
  if (!function_exists('tangible\\connect\\notice_key')) return '';
  $key = \tangible\connect\notice_key(get_current_user_id());
  $notice = get_transient($key);
  if (!$notice) return '';
  delete_transient($key);
  return \tangible\connect\notice_copy($notice);
}

function app_url($path) {
  $base = function_exists('tangible\\connect\\app_base') ? \tangible\connect\app_base() : 'https://tangible.one';
  return $base . $path;
}

/** Core's one-plugin update link, when this user may update plugins. */
function update_url($plugin) {
  if (empty($plugin->file_path) || !current_user_can('update_plugins')) return '';
  $basename = plugin_basename($plugin->file_path);
  return wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($basename)), 'upgrade-plugin_' . $basename);
}

/** The line under the heading: the site, who looks after it, the connection. */
function site_line($steward, $connect) {
  $host = '<b>' . esc_html(wp_parse_url(home_url(), PHP_URL_HOST)) . '</b>';
  if ($steward === 'client') $who = __('a client', 'tangible-framework');
  elseif ($steward === 'team') $who = __('you or your team', 'tangible-framework');
  else $who = __('not answered yet', 'tangible-framework');
  if ($connect === 'active') $line = __('Connected to tangible.one', 'tangible-framework');
  elseif ($connect === 'pending') $line = __('Connecting to tangible.one', 'tangible-framework');
  else $line = __('Not connected to tangible.one', 'tangible-framework');
  /* translators: 1: this site's host name, in bold; 2: who looks after it, in bold; 3: connection state */
  return sprintf(esc_html__('Site %1$s · Looked after by %2$s · %3$s', 'tangible-framework'),
    $host, '<b>' . esc_html($who) . '</b>', esc_html($line));
}

function installed_line($count, $updates) {
  /* translators: %s: number of installed Tangible plugins */
  $line = sprintf(_n('%s installed', '%s installed', $count, 'tangible-framework'), number_format_i18n($count));
  /* translators: %s: number of plugin updates available */
  if ($updates) $line .= ' · ' . sprintf(_n('%s update', '%s updates', $updates, 'tangible-framework'), number_format_i18n($updates));
  return $line;
}

function kind_label($kind) {
  return [
    'whats-new' => __('What\'s new', 'tangible-framework'),
    'tip'       => __('Tip', 'tangible-framework'),
    'notice'    => __('Notice', 'tangible-framework'),
  ][ $kind ] ?? (string) $kind;
}
