<?php
/**
 * Tangible Hub — the page. Server-rendered, no JS; every control is a link
 * or a POST form to admin-post.php. What each row says is decided in
 * rows.php; this file only draws it.
 *
 * The design is the Plugin Wizard Figma's "Tangible Hub" section (busy site,
 * fresh free site, client site, license problem): a white page with a header
 * band, a main column (Finish setting up, Your Tangible plugins, More from
 * Tangible) and a rail (Notifications, This site, tangible.one). It is drawn
 * with @tangible/ui's own DOM (Card, Chip, Notice, Progress, Button,
 * IconButton, Switch) wearing the WordPress admin theme (wp-admin-theme.css):
 * the admin colour scheme and the system font. No brand faces.
 */
namespace tangible\hub;

use tangible\framework;
use tangible\hub;

const SEEN_META = 'tangible_hub_notifications_seen_at';
const MORE_DISMISSED_META = 'tangible_hub_more_dismissed';

function render_page() {
  if (!current_user_can('manage_options')) wp_die(esc_html__('Nope', 'tangible-framework'), 403);

  $plugins = array_values(framework::$state->plugins ?? []);
  $rows = [];
  $has_free = false;
  $updates = 0;
  $attention = 0;
  foreach ($plugins as $plugin) {
    $facts = gather_facts($plugin);
    $state = row_state($facts);
    $rows[] = [ 'plugin' => $plugin, 'facts' => $facts, 'state' => $state ];
    if ($facts['free'] === true && !$facts['has_key']) $has_free = true;
    if ($facts['update']) $updates++;
    if (in_array($state['account']['chip'], ['need', 'bad'], true)) $attention++;
  }

  $connect = connect_status();
  $snoozed = (int) get_user_meta(get_current_user_id(), BAND_SNOOZE_META, true);
  $band = band_state($connect, $has_free, $snoozed, time());
  $steward = function_exists('tangible\\onboarding\\get_steward') ? \tangible\onboarding\get_steward() : null;
  // A client's site keeps licences and renewals off this dashboard (they go
  // to the account owner by email), so the plugin list drops that column.
  $client = $steward === 'client';
  $items = hub\get_notifications();
  $flash = connect_flash();
  $pending = array_values(array_filter($rows, function ($row) {
    return $row['state']['setup'] && !$row['state']['setup']['done'];
  }));

  // Unread is "arrived since this admin last opened the Hub".
  $seen_at = (int) get_user_meta(get_current_user_id(), SEEN_META, true);
  update_user_meta(get_current_user_id(), SEEN_META, time());

  foreach (['tui.css', 'wp-admin-theme.css', 'hub.css'] as $file) : ?>
    <link rel="stylesheet" href="<?php echo esc_url(asset_url($file)); ?>" />
  <?php endforeach; ?>
  <?php // Core moves admin notices to after this marker: above the page, on
  // the admin grey, where core's own update nag sits — not inside the Hub. ?>
  <hr class="wp-header-end tgbl-hub-notices" />
  <div class="wrap tui-interface tgbl-hub">
    <header class="tgbl-hub__header">
      <h1><?php esc_html_e('Tangible Hub', 'tangible-framework'); ?></h1>
    </header>
    <div class="tgbl-hub__content">

      <?php // The round trip's outcome, in its own tone. "Pending" is left to the
      // band, which says the same thing and offers the way on.
      if ($flash && !($flash['key'] === 'pending' && $band && $band['status'] === 'pending')) : ?>
        <section class="tui-notice is-theme-<?php echo esc_attr(flash_theme($flash['key'])); ?> tgbl-hub__flash" role="<?php echo in_array($flash['key'], ['error', 'revoked'], true) ? 'alert' : 'status'; ?>">
          <div class="tui-notice__inner"><div class="tui-notice__body"><?php echo esc_html($flash['text']); ?></div></div>
        </section>
      <?php endif; ?>
      <?php if ($band) render_band($band); ?>

      <div class="tgbl-hub__grid">
        <div class="tgbl-hub__main">
          <?php if ($pending) render_setup_card($pending); ?>
          <?php render_plugins_card($rows, $updates, $client ? 0 : $attention, $client); ?>
          <?php render_more_card($rows); ?>
          <?php
          // Cards contributed by other modules, under the main column.
          do_action('tangible_hub_main_cards');
          ?>
        </div>
        <div class="tgbl-hub__rail">
          <?php render_notifications($items, $seen_at); ?>
          <?php render_site_block($steward); ?>
          <?php render_one_block($connect); ?>
        </div>
      </div>
    </div>
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

// ── the main column ───────────────────────────────────────────────────────

function render_band($band) {
  $pending = $band['status'] === 'pending';
  if ($pending) {
    $title = __('Finishing the connection to tangible.one', 'tangible-framework');
    $body = __('tangible.one has not verified this site yet. It calls back to this site\'s REST API; check that it is reachable, then try again.', 'tangible-framework');
  } elseif ($band['has_free']) {
    $title = __('Connect this site to tangible.one', 'tangible-framework');
    $body = __('Optional, and free. One connection covers every free Tangible plugin on this site, now and later, so your setup answers follow your account instead of being asked again.', 'tangible-framework');
  } else {
    $title = __('Connect this site to tangible.one', 'tangible-framework');
    $body = __('See this site in your tangible.one account, and any free Tangible plugin you add later takes its setup answers from it. Your licensed plugins already work through their keys; connecting changes nothing for them.', 'tangible-framework');
  }
  ?>
  <section class="tui-notice is-theme-info tgbl-hub__band" aria-labelledby="tgbl-hub-connect">
    <div class="tui-notice__inner">
      <div class="tgbl-hub__band-text">
        <h2 id="tgbl-hub-connect" class="tui-notice__title"><?php echo esc_html($title); ?></h2>
        <div class="tui-notice__body"><?php echo esc_html($body); ?></div>
      </div>
      <div class="tgbl-hub__band-actions">
        <?php if ($pending) : ?>
          <?php post_form('tangible_connect_disconnect', __('Cancel', 'tangible-framework'), button_class('link')); ?>
          <?php post_form('tangible_connect_confirm', __('Check again', 'tangible-framework'), button_class('primary')); ?>
        <?php else : ?>
          <?php post_form('tangible_hub_connect_snooze', __('Not now', 'tangible-framework'), button_class('link')); ?>
          <?php connect_form(__('Connect', 'tangible-framework'), button_class('primary')); ?>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php
}

/** Plugins whose setup is not finished: where they are, and the way back in. */
function render_setup_card($pending) {
  ?>
  <article class="tui-card is-style-solid" aria-labelledby="tgbl-hub-setup">
    <div class="tui-card__inner">
      <div class="tui-card__head"><h2 id="tgbl-hub-setup"><?php esc_html_e('Finish setting up', 'tangible-framework'); ?></h2></div>
      <div class="tui-card__body">
        <ul class="tgbl-hub__list">
          <?php foreach ($pending as $row) :
            $f = $row['facts'];
            $setup = $row['state']['setup'];
            $total = max(1, (int) $f['steps_total']);
            $step = min($total, $total - (int) $setup['left'] + 1);
            /* translators: 1: current step number, 2: number of steps */
            $where = sprintf(__('Step %1$s of %2$s', 'tangible-framework'), number_format_i18n($step), number_format_i18n($total));
            /* translators: 1: "Step 2 of 3"; 2: the step's name */
            if ($f['next_step'] !== '') $where = sprintf(__('%1$s: %2$s', 'tangible-framework'), $where, $f['next_step']); ?>
            <li class="tgbl-hub__setup">
              <?php render_tile($f['icon']); ?>
              <div class="tgbl-hub__text">
                <h3><?php echo esc_html($f['title']); ?></h3>
                <p class="tgbl-hub__small"><?php echo esc_html($where); ?></p>
              </div>
              <div role="progressbar" aria-valuenow="<?php echo (int) $setup['pct']; ?>" aria-valuemin="0" aria-valuemax="100"
                   aria-label="<?php echo esc_attr(sprintf(__('%s setup', 'tangible-framework'), $f['title'])); ?>"
                   class="tui-progress is-mode-line is-size-md tgbl-hub__progress"
                   style="--tui-progress-value:<?php echo (int) $setup['pct']; ?>;--tui-progress-max:100;--tui-progress-pct:<?php echo (int) $setup['pct']; ?>">
                <div class="tui-progress__track"><div class="tui-progress__bar"></div></div>
              </div>
              <?php if (function_exists('tangible\\onboarding\\get_setup_url')) : ?>
                <a class="<?php echo esc_attr(button_class('primary')); ?>" href="<?php echo esc_url(\tangible\onboarding\get_setup_url($row['plugin'])); ?>"><span><?php
                  esc_html_e('Resume setup', 'tangible-framework'); ?></span><span class="tui-visually-hidden"> <?php echo esc_html($f['title']); ?></span></a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </article>
  <?php
}

function render_plugins_card($rows, $updates, $attention, $client) {
  $state = function_exists('tangible\\connect\\get_state') ? \tangible\connect\get_state() : [];
  ?>
  <article class="tui-card is-style-solid tgbl-hub__plugins" aria-labelledby="tgbl-hub-installed">
    <div class="tui-card__inner">
      <div class="tui-card__head">
        <h2 id="tgbl-hub-installed"><?php esc_html_e('Your Tangible plugins', 'tangible-framework'); ?></h2>
        <span class="tgbl-hub__small"><?php echo esc_html(installed_line(count($rows), $updates, $attention)); ?></span>
        <?php if (current_user_can('update_plugins')) : ?>
          <a class="<?php echo esc_attr(button_class('link')); ?> tgbl-hub__end" href="<?php echo esc_url(admin_url('update-core.php?force-check=1')); ?>"><span><?php
            esc_html_e('Check for updates', 'tangible-framework'); ?></span></a>
        <?php endif; ?>
      </div>
      <?php if ($client) : ?>
        <p class="tgbl-hub__small tgbl-hub__client"><?php
          $owner = (string) ($state['owner_email_masked'] ?? '');
          echo esc_html($owner !== ''
            /* translators: %s: the account owner's email address, partly masked */
            ? sprintf(__('Licenses and renewals go to the account owner, %s, by email. They do not appear on this site.', 'tangible-framework'), $owner)
            : __('Licenses and renewals go to the account owner by email. They do not appear on this site.', 'tangible-framework')); ?></p>
      <?php endif; ?>
      <?php if ($rows) : ?>
        <table class="tgbl-hub__table">
          <thead>
            <tr>
              <th scope="col"><?php esc_html_e('Plugin', 'tangible-framework'); ?></th>
              <th scope="col"><?php esc_html_e('Version', 'tangible-framework'); ?></th>
              <?php if (!$client) : ?><th scope="col"><?php esc_html_e('License', 'tangible-framework'); ?></th><?php endif; ?>
              <th scope="col"><span class="tui-visually-hidden"><?php esc_html_e('Action', 'tangible-framework'); ?></span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row) render_row($row['plugin'], $row['facts'], $row['state'], $client); ?>
          </tbody>
        </table>
      <?php else : ?>
        <div class="tui-card__body"><p><?php esc_html_e('No Tangible plugins registered on this site.', 'tangible-framework'); ?></p></div>
      <?php endif; ?>
    </div>
  </article>
  <?php
}

function render_row($plugin, $f, $s, $client) {
  $a = $s['account'];
  $settings_url = function_exists('tangible\\framework\\get_plugin_settings_page_url')
    ? framework\get_plugin_settings_page_url($plugin) : '';
  // A client's site shows no licence actions; an update is still the site's.
  // row_state ranks a licence action above an update, so on a client's site
  // an expired plugin with a pending update falls back to its Update.
  $action = $client && $s['action'] !== 'update' ? ($f['update'] ? 'update' : null) : $s['action'];
  ?>
  <tr>
    <th scope="row">
      <div class="tgbl-hub__plugin">
        <?php render_tile($f['icon']); ?>
        <div class="tgbl-hub__text">
          <span class="tgbl-hub__name"><?php echo esc_html($f['title']); ?></span>
          <?php if ($settings_url || $f['release_notes'] !== '') : ?>
            <span class="tgbl-hub__links"><?php
              $links = [];
              if ($settings_url) $links[] = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'tangible-framework')
                . '<span class="tui-visually-hidden"> ' . esc_html($f['title']) . '</span></a>';
              if ($f['release_notes'] !== '') $links[] = '<a href="' . esc_url($f['release_notes']) . '" target="_blank" rel="noopener">'
                . esc_html__('Release notes', 'tangible-framework') . '<span class="tui-visually-hidden"> ' . esc_html($f['title'])
                . ' ' . esc_html__('(opens in a new tab)', 'tangible-framework') . '</span></a>';
              echo implode(' · ', $links); ?></span>
          <?php endif; ?>
        </div>
      </div>
    </th>
    <td>
      <span class="tgbl-hub__value"><?php echo esc_html($f['version']); ?></span>
      <?php if ($f['update']) : ?>
        <span class="tgbl-hub__small"><?php
          /* translators: %s: version number */
          echo esc_html(sprintf(__('%s available', 'tangible-framework'), $f['update'])); ?></span>
      <?php endif; ?>
    </td>
    <?php if (!$client) : ?>
      <td>
        <?php render_chip($a); ?>
        <?php if ($a['sub'] !== '') : ?><span class="tgbl-hub__small"><?php echo esc_html($a['sub']); ?></span><?php endif; ?>
      </td>
    <?php endif; ?>
    <td class="tgbl-hub__action"><?php render_action($plugin, $f, $action); ?></td>
  </tr>
  <?php
}

/** The row's one action: what the plugin itself needs. Setup has its own card. */
function render_action($plugin, $f, $action) {
  $title = '<span class="tui-visually-hidden"> ' . esc_html($f['title']) . '</span>';
  switch ($action) {
    case 'enter_key':
      if (function_exists('tangible\\framework\\get_plugin_settings_page_url')) {
        echo '<a class="' . esc_attr(button_class('outline')) . '" href="' . esc_url(framework\get_plugin_settings_page_url($plugin, 'license')) . '"><span>'
          . esc_html__('Enter key', 'tangible-framework') . '</span>' . $title . '</a>';
      }
      return;
    case 'renew':
      echo '<a class="' . esc_attr(button_class('outline')) . '" href="' . esc_url(app_url('/licensing')) . '" target="_blank" rel="noopener"><span>'
        . esc_html__('Renew', 'tangible-framework') . ' <span aria-hidden="true">↗</span></span>' . $title
        . '<span class="tui-visually-hidden"> ' . esc_html__('(opens in a new tab)', 'tangible-framework') . '</span></a>';
      return;
    case 'update':
      $url = update_url($plugin);
      if ($url) {
        echo '<a class="' . esc_attr(button_class('outline')) . '" href="' . esc_url($url) . '"><span>' . esc_html__('Update', 'tangible-framework') . '</span>' . $title . '</a>';
      }
      return;
  }
}

/**
 * The status chip: a TUI Chip with the design's dot. rows.php's states onto
 * the design's tones — success active, warning needs a key, danger expired
 * or invalid, info free and connected, neutral not asked or not applicable.
 */
function render_chip($c) {
  $theme = [
    'ok'   => 'success',
    'need' => 'warning',
    'bad'  => 'danger',
    'info' => 'primary',
    'free' => 'primary',
    'off'  => 'secondary',
  ][ $c['chip'] ] ?? 'secondary';
  echo '<span class="tui-chip is-size-sm is-theme-' . esc_attr($theme) . ' tgbl-hub__chip"><span class="tgbl-hub__dot" aria-hidden="true"></span>'
    . '<span class="tui-chip__text">' . esc_html($c['text']) . '</span></span>';
}

/**
 * Other Tangible products, for sites that do not have them. The catalogue is
 * the filter's to fill — `tangible_hub_catalog`, a list of
 * [ name, plugin (slug, to skip when installed), description, url, icon ] —
 * and the card stays away while it is empty (no ghost UI), or once this admin
 * dismissed it.
 */
function render_more_card($rows) {
  if (get_user_meta(get_current_user_id(), MORE_DISMISSED_META, true)) return;
  $installed = array_map(function ($row) { return $row['facts']['name']; }, $rows);
  $catalog = array_values(array_filter((array) apply_filters('tangible_hub_catalog', []), function ($item) use ($installed) {
    return is_array($item) && !empty($item['name']) && !empty($item['url'])
      && !in_array($item['plugin'] ?? '', $installed, true);
  }));
  if (!$catalog) return;
  ?>
  <article class="tui-card is-style-solid" aria-labelledby="tgbl-hub-more">
    <div class="tui-card__inner">
      <div class="tui-card__head">
        <h2 id="tgbl-hub-more"><?php esc_html_e('More from Tangible', 'tangible-framework'); ?></h2>
        <?php dismiss_form('tangible_hub_more_dismiss', __('Dismiss More from Tangible', 'tangible-framework')); ?>
      </div>
      <div class="tui-card__body">
        <ul class="tgbl-hub__more">
          <?php foreach ($catalog as $item) : ?>
            <li>
              <?php render_tile($item['icon'] ?? ''); ?>
              <div class="tgbl-hub__text">
                <h3><?php echo esc_html($item['name']); ?></h3>
                <?php if (!empty($item['description'])) : ?><p class="tgbl-hub__small"><?php echo esc_html($item['description']); ?></p><?php endif; ?>
                <a class="tgbl-hub__small" href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener"><?php
                  /* translators: %s: product name */
                  echo esc_html(sprintf(__('See %s', 'tangible-framework'), $item['name'])); ?> <span aria-hidden="true">↗</span><span class="tui-visually-hidden"> <?php
                  esc_html_e('(opens in a new tab)', 'tangible-framework'); ?></span></a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </article>
  <?php
}

// ── the rail ──────────────────────────────────────────────────────────────

/** Release notes and news only — never system prompts (the design's rule). */
function render_notifications($items, $seen_at) {
  $new = count(array_filter($items, function ($item) use ($seen_at) { return (int) $item['created'] > $seen_at; }));
  ?>
  <article class="tui-card is-style-solid" aria-labelledby="tgbl-hub-notifications">
    <div class="tui-card__inner">
      <div class="tui-card__head">
        <h2 id="tgbl-hub-notifications"><?php esc_html_e('Notifications', 'tangible-framework'); ?></h2>
        <?php if ($new) : ?>
          <span class="tui-chip is-size-sm is-theme-secondary"><span class="tui-chip__text"><?php
            /* translators: %s: number of unread notifications */
            echo esc_html(sprintf(_n('%s new', '%s new', $new, 'tangible-framework'), number_format_i18n($new))); ?></span></span>
        <?php endif; ?>
        <?php if ($items) post_form('tangible_hub_dismiss_all', __('Dismiss all', 'tangible-framework'), button_class('link') . ' tgbl-hub__end'); ?>
      </div>
      <div class="tui-card__body">
        <?php if ($items) : ?>
          <ul class="tgbl-hub__notes">
            <?php foreach ($items as $item) :
              $unread = (int) $item['created'] > $seen_at; ?>
              <li class="tgbl-hub__note<?php echo $unread ? ' is-unread' : ''; ?>">
                <div class="tgbl-hub__text">
                  <h3><?php if ($unread) : ?><span class="tui-visually-hidden"><?php esc_html_e('New:', 'tangible-framework'); ?> </span><?php endif;
                    echo esc_html($item['title']); ?></h3>
                  <?php if (!empty($item['body']) || !empty($item['action']['url'])) : ?>
                    <p><?php echo esc_html($item['body'] ?? ''); ?>
                      <?php if (!empty($item['action']['url'])) : ?>
                        <a href="<?php echo esc_url($item['action']['url']); ?>"><?php echo esc_html($item['action']['label'] ?? __('Open', 'tangible-framework')); ?></a>
                      <?php endif; ?></p>
                  <?php endif; ?>
                  <p class="tgbl-hub__small"><?php
                    /* translators: 1: notification kind, e.g. "Release notes"; 2: how long ago, e.g. "3 days" */
                    echo esc_html(sprintf(__('%1$s · %2$s ago', 'tangible-framework'), kind_label($item['kind']), human_time_diff((int) $item['created']))); ?></p>
                </div>
                <a class="tui-icon-button is-size-sm is-theme-secondary is-style-ghost"
                   href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tangible_hub_dismiss&item=' . rawurlencode($item['id'])), 'tangible_hub_dismiss')); ?>"
                   aria-label="<?php
                     /* translators: %s: notification title */
                     echo esc_attr(sprintf(__('Dismiss %s', 'tangible-framework'), $item['title'])); ?>"><?php echo close_icon(); ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else : ?>
          <p class="tgbl-hub__small"><?php esc_html_e('Nothing new. Release notes and news from Tangible show up here, never as notices across your dashboard.', 'tangible-framework'); ?></p>
        <?php endif; ?>
      </div>
    </div>
  </article>
  <?php
  // "From tangible.one" (a releases feed per installed asset) is deliberately
  // absent: there is no endpoint for it yet. TODO(hub): add the block when
  // the platform serves one — not before (no ghost UI).
}

function render_site_block($steward) {
  $client = $steward === 'client';
  ?>
  <article class="tui-card is-style-solid" id="tgbl-hub-site" aria-labelledby="tgbl-hub-site-title">
    <div class="tui-card__inner">
      <div class="tui-card__head"><h2 id="tgbl-hub-site-title"><?php esc_html_e('This site', 'tangible-framework'); ?></h2></div>
      <div class="tui-card__body">
        <?php // The steward answer, as a switch: TUI's Switch DOM, its button the
        // form's submit (no JS). It lives on this site only, never on tangible.one. ?>
        <form class="tgbl-hub__toggle" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php hidden_fields('tangible_hub_steward', [ 'steward' => $client ? 'team' : 'client' ]); ?>
          <label class="tui-switch">
            <button type="submit" role="switch" aria-checked="<?php echo $client ? 'true' : 'false'; ?>" class="tui-switch__track"
                    aria-describedby="tgbl-hub-steward-help"><span class="tui-switch__thumb"></span></button>
            <span class="tui-switch__label"><?php esc_html_e('Built for a client', 'tangible-framework'); ?></span>
          </label>
          <p class="tgbl-hub__small" id="tgbl-hub-steward-help"><?php
            esc_html_e('License and renewal news goes to the account owner by email instead of this dashboard. Covers every Tangible plugin on this site.', 'tangible-framework'); ?></p>
        </form>
        <hr class="tgbl-hub__divider" />
        <?php render_usage_data(); ?>
      </div>
    </div>
  </article>
  <?php
}

/** The per-site usage-data answer (ADR-011 §3), one for every Tangible plugin here. */
function render_usage_data() {
  if (!function_exists('tangible\\onboarding\\site_telemetry_state')) return;
  $state = \tangible\onboarding\site_telemetry_state(
    \tangible\onboarding\get_consent_answer('telemetry_extended'),
    \tangible\onboarding\site_covered_by_terms()
  );
  $sending = in_array($state, ['granted', 'terms'], true);
  $what = __('versions, theme, plugins, users and roles, content counts, hosting and page speed. Never your content, users or visitors.', 'tangible-framework');
  $text = [
    /* translators: %s: what usage data includes */
    'terms'   => sprintf(__('Sent under your tangible.one terms: %s', 'tangible-framework'), $what),
    /* translators: %s: what usage data includes */
    'granted' => sprintf(__('Sent from this site: %s', 'tangible-framework'), $what),
    'refused' => __('Not sent from this site, by any Tangible plugin.', 'tangible-framework'),
    'none'    => __('Not sent from this site. Nobody here has been asked, and nothing is sent until someone says yes.', 'tangible-framework'),
  ][ $state ];
  ?>
  <div class="tgbl-hub__usage">
    <h3><?php esc_html_e('Usage data', 'tangible-framework'); ?></h3>
    <p class="tgbl-hub__small"><?php echo esc_html($text); ?></p>
    <form class="tgbl-hub__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php hidden_fields(\tangible\onboarding\SITE_TELEMETRY_ACTION, [ 'send' => $sending ? '0' : '1' ]); ?>
      <button type="submit" class="<?php echo esc_attr(button_class('link')); ?>"><span><?php
        echo esc_html($sending ? __('Stop sending usage data', 'tangible-framework') : __('Send usage data', 'tangible-framework')); ?></span></button>
    </form>
  </div>
  <?php
}

function render_one_block($connect) {
  $state = function_exists('tangible\\connect\\get_state') ? \tangible\connect\get_state() : [];
  ?>
  <article class="tui-card is-style-solid" aria-labelledby="tgbl-hub-one">
    <div class="tui-card__inner">
      <div class="tui-card__head"><h2 id="tgbl-hub-one">tangible.one</h2></div>
      <div class="tui-card__body">
        <?php if ($connect === 'active') : ?>
          <h3><?php
            /* translators: %s: tangible.one account name */
            echo esc_html(sprintf(__('Connected as %s', 'tangible-framework'), ($state['account_name'] ?? '') ?: __('your account', 'tangible-framework'))); ?></h3>
          <p class="tgbl-hub__small"><?php
            $masked = (string) ($state['owner_email_masked'] ?? '');
            echo esc_html(($masked !== '' ? $masked . '. ' : '')
              . __('Free Tangible plugins on this site take their setup answers from this account.', 'tangible-framework')); ?></p>
          <p class="tgbl-hub__links">
            <a href="<?php echo esc_url(app_url('/websites/connected')); ?>" target="_blank" rel="noopener"><?php
              esc_html_e('Manage on tangible.one', 'tangible-framework'); ?> <span aria-hidden="true">↗</span><span class="tui-visually-hidden"> <?php
              esc_html_e('(opens in a new tab)', 'tangible-framework'); ?></span></a>
            <?php post_form('tangible_connect_disconnect', __('Disconnect', 'tangible-framework'), button_class('link')); ?>
          </p>
        <?php elseif ($connect === 'pending') : ?>
          <h3><?php esc_html_e('Connecting', 'tangible-framework'); ?></h3>
          <p class="tgbl-hub__small"><?php esc_html_e('Waiting for tangible.one to verify this site.', 'tangible-framework'); ?></p>
        <?php else : ?>
          <h3><?php esc_html_e('Not connected', 'tangible-framework'); ?></h3>
          <p class="tgbl-hub__small"><?php esc_html_e('Licensed plugins work through their keys either way. Connecting keeps your free plugins\' setup answers in your account.', 'tangible-framework'); ?></p>
          <p class="tgbl-hub__links"><?php connect_form(__('Connect this site', 'tangible-framework'), button_class('link')); ?></p>
        <?php endif; ?>
      </div>
    </div>
  </article>
  <?php
}

// ── small helpers ─────────────────────────────────────────────────────────

/** A TUI Button's classes: primary (filled), outline, or link. */
function button_class($style) {
  return 'tui-button is-size-md is-theme-primary'
    . ($style === 'outline' ? ' is-style-outline' : ($style === 'link' ? ' is-style-link' : ''));
}

/** The 40px tile: the plugin's icon, or the generic plugin glyph. */
function render_tile($icon) {
  echo '<span class="tgbl-hub__tile" aria-hidden="true">';
  // The catalog filter can hand over null or false.
  if (is_string($icon) && $icon !== '') {
    echo '<img src="' . esc_url(set_url_scheme($icon)) . '" alt="" width="24" height="24" />';
  } else {
    echo '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M10.5 4v4h3V4H15v4h1.5a1 1 0 0 1 1 1v4l-3 4v2a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1v-2l-3-4V9a1 1 0 0 1 1-1H9V4h1.5ZM11 16.5v2h2v-2l3-4v-3H8v3l3 4Z"/></svg>';
  }
  echo '</span>';
}

function close_icon() {
  return '<span class="tui-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" focusable="false"><path fill-rule="evenodd" d="m12 12.868-6.462 6.655-1.076-1.046 6.493-6.685-6.089-6.27 1.077-1.045L12 10.715l6.057-6.238 1.076 1.045-6.088 6.27 6.493 6.685-1.076 1.046z" clip-rule="evenodd"/></svg></span>';
}

/** An icon-only dismiss: a POST whose button is TUI's ghost IconButton. */
function dismiss_form($action, $label) {
  ?><form class="tgbl-hub__form tgbl-hub__end" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php hidden_fields($action); ?>
    <button type="submit" class="tui-icon-button is-size-sm is-theme-secondary is-style-ghost" aria-label="<?php echo esc_attr($label); ?>"><?php echo close_icon(); ?></button>
  </form><?php
}

function hidden_fields($action, $fields = []) {
  ?><input type="hidden" name="action" value="<?php echo esc_attr($action); ?>" />
  <?php foreach ($fields as $k => $v) : ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" /><?php endforeach; ?>
  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce($action)); ?>" />
  <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'] ?? '')); ?>" /><?php
}

/** A POST to admin-post.php, nonce'd by its own action name. */
function post_form($action, $label, $class, $fields = []) {
  ?><form class="tgbl-hub__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php hidden_fields($action, $fields); ?>
    <button type="submit" class="<?php echo esc_attr($class); ?>"><span><?php echo esc_html($label); ?></span></button>
  </form><?php
}

/** Connect's own start action (connect/flow.php), returning here. */
function connect_form($label, $class) {
  post_form('tangible_connect_start', $label, $class, [ 'return_to' => url() ]);
}

/** The Connect round trip's outcome, once: [ key, text ] or null. */
function connect_flash() {
  if (!function_exists('tangible\\connect\\notice_key')) return null;
  $key = \tangible\connect\notice_key(get_current_user_id());
  $notice = get_transient($key);
  if (!$notice) return null;
  delete_transient($key);
  return [ 'key' => (string) $notice, 'text' => \tangible\connect\notice_copy($notice) ];
}

function flash_theme($key) {
  return [
    'connected'    => 'success',
    'disconnected' => 'success',
    'pending'      => 'warning',
    'denied'       => 'neutral',
    'error'        => 'danger',
    'revoked'      => 'danger',
  ][ $key ] ?? 'neutral';
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

/** "4 installed · 1 update", or "· 2 need attention" when licences do. */
function installed_line($count, $updates, $attention = 0) {
  /* translators: %s: number of installed Tangible plugins */
  $line = sprintf(_n('%s installed', '%s installed', $count, 'tangible-framework'), number_format_i18n($count));
  /* translators: %s: number of plugins whose license needs attention */
  if ($attention) $line .= ' · ' . sprintf(_n('%s needs attention', '%s need attention', $attention, 'tangible-framework'), number_format_i18n($attention));
  /* translators: %s: number of plugin updates available */
  elseif ($updates) $line .= ' · ' . sprintf(_n('%s update', '%s updates', $updates, 'tangible-framework'), number_format_i18n($updates));
  return $line;
}

function kind_label($kind) {
  return [
    'whats-new' => __('Release notes', 'tangible-framework'),
    'tip'       => __('Tip', 'tangible-framework'),
    'notice'    => __('News', 'tangible-framework'),
  ][ $kind ] ?? (string) $kind;
}
