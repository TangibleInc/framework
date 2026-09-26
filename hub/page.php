<?php
/**
 * Tangible Home — the page. Server-rendered, no JS; every control is a link
 * or a POST form to admin-post.php. What each row says is decided in
 * rows.php; this file only draws it.
 */
namespace tangible\hub;

use tangible\framework;
use tangible\hub;

function render_page() {
  if (!current_user_can('manage_options')) wp_die('Nope', 403);

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

  $css_url = set_url_scheme(trailingslashit(framework\module_url(__FILE__)) . 'hub.css')
    . '?v=' . rawurlencode(framework::$state->version ?? '1');
  ?>
  <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>" />
  <style><?php echo font_faces_css(); ?></style>
  <div class="tgbl-hub">
    <div class="hy-well">
      <header class="hy-top">
        <?php render_mark(); ?>
        <div class="hy-lock"><span class="a">Tangible</span><h1>Home</h1></div>
        <div class="hy-site">
          <span class="site-host">Site <b><?php echo esc_html(wp_parse_url(home_url(), PHP_URL_HOST)); ?></b></span>
          <span class="sep"></span>
          <span>Looked after by <b><?php echo esc_html(steward_label($steward)); ?></b>
            <a class="hy-link" href="#tgbl-hub-site">change</a></span>
          <span class="sep"></span>
          <span><?php echo esc_html(connection_line($connect)); ?></span>
        </div>
      </header>

      <div class="hy-content">
        <?php if ($flash) : ?><div class="hy-flash" role="status"><?php echo esc_html($flash); ?></div><?php endif; ?>
        <?php if ($band) render_band($band); ?>

        <div class="hy-grid">
          <section class="hy-card hy-plugins" aria-labelledby="tgbl-hub-installed">
            <div class="head">
              <div><div class="hy-lbl">Installed</div><h2 class="hy-h2" id="tgbl-hub-installed">Your Tangible plugins</h2></div>
              <span class="meta"><?php
                echo esc_html(count($rows) . ' installed' . ($updates ? ' · ' . $updates . ' update' . ($updates === 1 ? '' : 's') : ''));
              ?></span>
            </div>
            <?php foreach ($rows as $row) render_row($row['plugin'], $row['facts'], $row['state']); ?>
            <?php if (!$rows) : ?><div class="prow empty">No Tangible plugins registered on this site.</div><?php endif; ?>
          </section>

          <aside class="hy-card rail" aria-label="Notifications and site">
            <?php render_notifications($items); ?>
            <?php render_site_block($steward, $connect); ?>
          </aside>
        </div>
        <?php
        // Cards contributed by other modules, under the grid.
        do_action('tangible_hub_main_cards');
        ?>
      </div>
    </div>
  </div>
  <?php
}

// ── pieces ────────────────────────────────────────────────────────────────

function render_mark() {
  echo '<span class="tmark" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>';
}

function render_band($band) {
  $pending = $band['status'] === 'pending';
  if ($pending) {
    $title = 'Finishing the connection to tangible.one';
    $body = 'tangible.one has not verified this site yet. It calls back to this site\'s REST API; check that it is reachable, then try again.';
  } elseif ($band['has_free']) {
    $title = 'Connect this site to your tangible.one account';
    $body = 'Optional, and free. One connection covers every free Tangible plugin on this site, now and later. '
          . 'Licensed plugins keep working through their keys. If you are already signed in at tangible.one, it is one click.';
  } else {
    $title = 'Connect this site to your tangible.one account';
    $body = 'Get free Tangible plugins and their updates without a licence key. '
          . 'Your licensed plugins already work through their keys; connecting changes nothing for them.';
  }
  ?>
  <section class="hy-connect" aria-labelledby="tgbl-hub-connect">
    <span class="glyph"><?php render_mark(); ?></span>
    <div>
      <h2 id="tgbl-hub-connect"><?php echo esc_html($title); ?></h2>
      <p><?php echo esc_html($body); ?></p>
    </div>
    <div class="acts">
      <?php if ($pending) : ?>
        <?php post_form('tangible_connect_disconnect', 'Cancel', 'hy-link'); ?>
        <?php post_form('tangible_connect_confirm', 'Check again', 'hy-btn'); ?>
      <?php else : ?>
        <?php post_form('tangible_hub_connect_snooze', 'Not now', 'hy-link'); ?>
        <?php connect_form('Connect', 'hy-btn'); ?>
      <?php endif; ?>
    </div>
  </section>
  <?php
}

function render_row($plugin, $f, $s) {
  $a = $s['account'];
  $u = $s['usage'];
  $setup = $s['setup'];
  $settings_url = function_exists('tangible\\framework\\get_plugin_settings_page_url')
    ? framework\get_plugin_settings_page_url($plugin) : '';
  ?>
  <div class="prow">
    <span class="picon" style="background:<?php echo esc_attr(icon_colour($f['name'])); ?>" aria-hidden="true"><?php
      echo esc_html(initials($f['title'])); ?></span>
    <div>
      <div class="pname"><?php echo esc_html($f['title']); ?></div>
      <div class="pmeta"><?php
        $meta = [];
        if ($f['version'] !== '') $meta[] = esc_html($f['version']);
        if ($f['free'] === true && !$f['has_key']) $meta[] = 'free';
        if ($f['update']) $meta[] = '<span class="up">' . esc_html($f['update']) . ' available</span>';
        echo implode(' · ', $meta);
      ?></div>
    </div>

    <div class="cell"><span class="k"><?php echo esc_html($a['key']); ?></span><?php render_chip($a); ?>
      <?php if ($a['sub'] !== '' || $a['action'] === 'connect') : ?>
        <div class="sub"><?php echo esc_html($a['sub']); ?>
          <?php if ($a['action'] === 'connect') : ?> · <?php connect_form('Connect', 'hy-link'); ?><?php endif; ?></div>
      <?php endif; ?>
    </div>

    <div class="cell"><?php if ($u) : ?><span class="k">Usage data</span><?php render_chip($u); ?>
      <?php if ($u['sub'] !== '') : ?><div class="sub"><?php echo esc_html($u['sub']); ?></div><?php endif; ?>
    <?php endif; ?></div>

    <div class="pacts">
      <?php if ($setup && $setup['done']) : ?>
        <span class="setup done">✓ Set up</span>
      <?php elseif ($setup) : ?>
        <span class="setup"><span class="bar" aria-hidden="true"><i style="width:<?php echo (int) $setup['pct']; ?>%"></i></span><?php
          echo esc_html($setup['left'] . ' step' . ($setup['left'] === 1 ? '' : 's') . ' left'); ?></span>
      <?php endif; ?>
      <?php render_primary($plugin, $f, $s['primary'], $settings_url); ?>
    </div>
  </div>
  <?php
}

function render_primary($plugin, $f, $primary, $settings_url) {
  switch ($primary) {
    case 'resume':
      if (function_exists('tangible\\onboarding\\get_setup_url')) {
        echo '<a class="hy-btn sm" href="' . esc_url(\tangible\onboarding\get_setup_url($plugin)) . '">Resume setup</a>';
      }
      return;
    case 'enter_key':
      if ($settings_url) {
        echo '<a class="hy-btn sm" href="' . esc_url(framework\get_plugin_settings_page_url($plugin, 'license')) . '">Enter key</a>';
      }
      return;
    case 'renew':
      echo '<a class="hy-btn sm ghost" href="' . esc_url(app_url('/licensing')) . '" target="_blank" rel="noopener">Renew ↗</a>';
      return;
    case 'update':
      $url = update_url($plugin);
      if ($url) {
        echo '<a class="hy-btn sm ghost" href="' . esc_url($url) . '">Update</a>';
        return;
      }
      // fall through to Open when this user may not update plugins
    default:
      if ($settings_url) echo '<a class="hy-link" href="' . esc_url($settings_url) . '">Open</a>';
  }
}

function render_chip($c) {
  echo '<span class="chip ' . esc_attr($c['chip']) . '"><span class="d" aria-hidden="true"></span>' . esc_html($c['text']) . '</span>';
}

function render_notifications($items) {
  ?>
  <div class="blk">
    <div class="top">
      <span class="hy-lbl">Notifications</span>
      <?php if ($items) : ?><span class="c"><?php echo count($items); ?></span>
        <span class="end"><?php post_form('tangible_hub_dismiss_all', 'Dismiss all', 'hy-link'); ?></span>
      <?php endif; ?>
    </div>
    <?php foreach ($items as $item) : ?>
      <div class="note-item">
        <span class="dot <?php echo esc_attr($item['kind']); ?>" aria-hidden="true"></span>
        <div>
          <div class="t"><?php echo esc_html($item['title']); ?></div>
          <?php if (!empty($item['body']) || !empty($item['action']['url'])) : ?>
            <div class="d"><?php echo esc_html($item['body'] ?? ''); ?>
              <?php if (!empty($item['action']['url'])) : ?>
                <a class="hy-link" href="<?php echo esc_url($item['action']['url']); ?>"><?php echo esc_html($item['action']['label'] ?? 'Open'); ?></a>
              <?php endif; ?></div>
          <?php endif; ?>
          <div class="w"><?php echo esc_html(str_replace('-', ' ', $item['kind']) . ' · ' . human_time_diff((int) $item['created']) . ' ago'); ?></div>
        </div>
        <a class="x" title="Dismiss" aria-label="Dismiss <?php echo esc_attr($item['title']); ?>"
           href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tangible_hub_dismiss&item=' . rawurlencode($item['id'])), 'tangible_hub_dismiss')); ?>">×</a>
      </div>
    <?php endforeach; ?>
    <?php if (!$items) : ?>
      <p class="quiet">Nothing new. Release notes and heads-ups land here — Tangible never posts admin notices.</p>
    <?php endif; ?>
  </div>
  <?php
  // "From tangible.one" (a releases feed per installed asset) is deliberately
  // absent: there is no endpoint for it yet. TODO(hub): add the block when
  // the platform serves one — not before (no ghost UI).
}

function render_site_block($steward, $connect) {
  $state = function_exists('tangible\\connect\\get_state') ? \tangible\connect\get_state() : [];
  ?>
  <div class="blk" id="tgbl-hub-site">
    <div class="top"><span class="hy-lbl">This site</span></div>
    <?php if ($steward === 'client') : ?>
      <p class="steward">You told us this site is <b>built for a client</b>. Licence and renewal news goes to
        the account owner by email rather than to this admin.</p>
      <div class="acts"><?php post_form('tangible_hub_steward', 'Me or my team look after it', 'hy-link', [ 'steward' => 'team' ]); ?></div>
    <?php elseif ($steward === 'team') : ?>
      <p class="steward">You told us <b>you or your team</b> look after this site, so licence and renewal
        details show here. Building it for a client? Switch it and that news goes to the account owner by email.</p>
      <div class="acts"><?php post_form('tangible_hub_steward', 'I\'m building this for a client', 'hy-link', [ 'steward' => 'client' ]); ?></div>
    <?php else : ?>
      <p class="steward">Who looks after this site? It decides who hears about licences and renewals.</p>
      <div class="acts">
        <?php post_form('tangible_hub_steward', 'Me or my team', 'hy-link', [ 'steward' => 'team' ]); ?>
        <?php post_form('tangible_hub_steward', 'A client', 'hy-link', [ 'steward' => 'client' ]); ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="blk">
    <div class="top"><span class="hy-lbl">tangible.one</span></div>
    <?php if ($connect === 'active') : ?>
      <p class="steward">Connected as <b><?php echo esc_html(($state['account_name'] ?? '') ?: 'your account'); ?></b><?php
        if (!empty($state['owner_email_masked'])) echo ' (' . esc_html($state['owner_email_masked']) . ')'; ?>.
        Free Tangible plugins here update through this connection.</p>
      <div class="acts">
        <a class="hy-link" href="<?php echo esc_url(app_url('/websites/connected')); ?>" target="_blank" rel="noopener">Manage ↗</a>
        <?php post_form('tangible_connect_disconnect', 'Disconnect', 'hy-link'); ?>
      </div>
    <?php elseif ($connect === 'pending') : ?>
      <p class="steward">Waiting for tangible.one to verify this site.</p>
    <?php else : ?>
      <p class="steward">Not connected. Licensed plugins work through their keys either way.</p>
      <div class="acts"><?php connect_form('Connect this site', 'hy-link'); ?></div>
    <?php endif; ?>
  </div>
  <?php
}

// ── small helpers ─────────────────────────────────────────────────────────

/** A POST to admin-post.php, nonce'd by its own action name. */
function post_form($action, $label, $class, $fields = []) {
  ?><form class="inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>" />
    <?php foreach ($fields as $k => $v) : ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" /><?php endforeach; ?>
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce($action)); ?>" />
    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'] ?? '')); ?>" />
    <button type="submit" class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
  </form><?php
}

/** Connect's own start action (connect/flow.php), returning here. */
function connect_form($label, $class) {
  post_form('tangible_connect_start', $label, $class, [ 'return_to' => admin_url('admin.php?page=' . PAGE) ]);
}

function connect_flash() {
  if (!function_exists('tangible\\connect\\notice_key')) return '';
  $key = \tangible\connect\notice_key(get_current_user_id());
  $notice = get_transient($key);
  if (!$notice) return '';
  delete_transient($key);
  return \tangible\connect\NOTICE_COPY[$notice] ?? '';
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

function steward_label($steward) {
  if ($steward === 'client') return 'a client';
  if ($steward === 'team') return 'me / my team';
  return 'not answered yet';
}

function connection_line($connect) {
  if ($connect === 'active') return 'Connected to tangible.one';
  if ($connect === 'pending') return 'Connecting to tangible.one';
  return 'Not connected to tangible.one';
}

/** Two letters for the tile: "Design Upgrade Pro" → "Du", "SearchSync" → "Se". */
function initials($title) {
  $words = array_values(array_filter(preg_split('/\s+/', (string) $title), function ($w) {
    return $w !== '' && strcasecmp($w, 'Tangible') !== 0;
  }));
  if (!$words) return 'T';
  $first = mb_substr($words[0], 0, 1);
  $second = count($words) > 1 ? mb_substr($words[1], 0, 1) : mb_substr($words[0], 1, 1);
  return mb_strtoupper($first) . mb_strtolower($second);
}

function icon_colour($name) {
  $palette = ['#433BA5', '#6359D6', '#16131c', '#507F06', '#936C21', '#9f1f63', '#2e3192'];
  return $palette[ abs(crc32((string) $name)) % count($palette) ];
}

/**
 * League Spartan (headings) and Space Mono (labels), both OFL and shipped in
 * design/fonts. Not design\font_faces_css(): that one also declares Recoleta,
 * which must not reach a customer site.
 */
function font_faces_css() {
  $base = set_url_scheme(trailingslashit(framework\module_url(dirname(__DIR__) . '/design/index.php')) . 'fonts/');
  $faces = [
    [ 'League Spartan', 600, 'league-spartan-600' ],
    [ 'League Spartan', 700, 'league-spartan-700' ],
    [ 'Space Mono', 400, 'space-mono-400' ],
    [ 'Space Mono', 700, 'space-mono-700' ],
  ];
  $css = '';
  foreach ($faces as [$family, $weight, $file]) {
    $css .= "@font-face{font-family:'$family';font-weight:$weight;font-style:normal;font-display:swap;"
          . "src:url('" . esc_url($base . $file . '.woff2') . "') format('woff2');}";
  }
  return $css;
}
