<?php
/**
 * Welcome — the first screen of every Tangible plugin's setup (Figma
 * "[Core] Free 01 Welcome", "01b Welcome connected", "[Core] Pro 01
 * Welcome"; layout rules §7 "Welcome": 1200, a 720 media card beside a 448
 * form card, stacked below 1100).
 *
 * The media card is the plugin's: what it does, in the plugin's own colours.
 * The form card is core, and holds what used to be three rail steps:
 *
 *   paid     the licence key, activated for real on Get started (steps.php
 *            activate_licence). Mandatory: Get started refuses without it.
 *   free     the Connect card (connect/step.php). Optional.
 *   always   "I am installing this for a client" — the site's steward
 *            answer, one option for every Tangible plugin on the site.
 *
 * Shown once per plugin per site: its record is per plugin, so a second
 * Tangible plugin still opens on its own Welcome, with the site's answers
 * (connection, steward) already filled in.
 *
 * ## The media card
 *
 * A plugin describes it when it registers its wizard, or through the
 * `tangible_onboarding_welcome` filter — same array either way:
 *
 *   onboarding\register_wizard($plugin, [
 *     'short_title' => 'Design Upgrade',            // "Design Upgrade works…"
 *     'welcome' => [
 *       'intro'    => 'Restyles courses, lessons…', // subtitle under the heading
 *       'logo'     => $url,                         // on the hero
 *       'headline' => 'A cleaner LearnDash,',
 *       'accent'   => 'for free.',                  // the headline's coloured end
 *       'bullets'  => [ 'Focus mode, redesigned', … ],
 *       'inset'    => [ 'type' => 'features', 'items' => [ [ 'icon' => 'dashicons-…', 'label' => '…' ], … ] ],
 *                  // or [ 'type' => 'palette', 'rows' => [ [ 'label' => 'Primary', 'colors' => [ '#…', … ] ], … ] ]
 *                  // or a callable that echoes its own markup
 *       'video'    => $url,                         // the round play button
 *       'stats'    => [ [ 'value' => '7,000+', 'label' => 'active installs' ], … ],
 *       'background' => 'linear-gradient(…)',       // the hero's ground
 *     ],
 *   ]);
 *
 * Without any of it, the card falls back to the plugin's logo and the
 * description from its plugin header — honest, if plain.
 */
namespace tangible\onboarding;

/** The Description line of the plugin header, tags stripped. */
function plugin_description($plugin) {
  if (empty($plugin->file_path) || !is_readable($plugin->file_path)) return '';
  if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
  $data = get_plugin_data($plugin->file_path, false, false);
  return trim(wp_strip_all_tags((string) ($data['Description'] ?? '')));
}

function welcome_config($plugin) {
  $title = $plugin->title ?? $plugin->name;
  $config = array_merge([
    'heading'    => 'Welcome to ' . $title,
    'intro'      => '',
    'logo'       => !empty($plugin->logo) ? $plugin->logo : '',
    'headline'   => '',
    'accent'     => '',
    'bullets'    => [],
    'inset'      => null,
    'video'      => '',
    'stats'      => [],
    'background' => '',
  ], (array) (wizard_args($plugin->name)['welcome'] ?? []));
  if ($config['intro'] === '') $config['intro'] = plugin_description($plugin);
  $config = apply_filters('tangible_onboarding_welcome', $config, $plugin);
  return is_array($config) ? $config : [];
}

add_filter('tangible_onboarding_steps', function ($steps, $facts, $plugin_name = '') {
  if (empty(registered_wizards()[$plugin_name])) return $steps;

  $steps[] = [
    'id'     => 'welcome',
    'label'  => 'welcome',
    'weight' => 5,
    'scope'  => 'plugin',
    'skippable' => false,
    'submit_label' => 'Get started',
    // Until answered — except on a site that finished this plugin's setup
    // before Welcome existed: its Done record says so, and asking again would
    // turn every finished setup back into "1 step left".
    'needed' => function () use ($plugin_name) {
      $state = get_option(get_state_key($plugin_name, 'plugin'), []);
      return empty($state['done']);
    },
    'skip_note' => 'set up',
    'render' => function ($plugin) {
      render_welcome($plugin);
    },
    'handle' => function ($plugin) {
      if (!$plugin) return false;
      // The checkbox rides along with every button on this screen, so a
      // tick given before Connect is not lost on the round trip.
      set_steward(!empty($_POST['steward_client']) ? 'client' : 'team');

      $answer = $_POST['answer'] ?? '';
      if ($answer !== '') {
        return is_free_distribution($plugin) && function_exists('tangible\\connect\\handle_welcome_answer')
          ? \tangible\connect\handle_welcome_answer($plugin, $answer)
          : false;
      }
      return licence_applies($plugin) ? handle_licence_field($plugin) : true;
    },
  ];
  return $steps;
}, 5, 3);

function render_welcome($plugin) {
  $config = welcome_config($plugin);
  $paid = licence_applies($plugin);
  $connect = !$paid && is_free_distribution($plugin) && function_exists('tangible\\connect\\render_welcome_section');
  ?>
  <h2><?php echo esc_html($config['heading']); ?></h2>
  <?php if ($config['intro'] !== '') : ?>
    <p class="step-intro"><?php echo esc_html($config['intro']); ?></p>
  <?php endif; ?>
  <div class="tgbl-welcome">
    <?php render_welcome_media($plugin, $config); ?>
    <section class="tgbl-card tgbl-welcome__form">
      <?php
      if ($paid) render_licence_field($plugin);
      elseif ($connect) \tangible\connect\render_welcome_section($plugin);
      if ($paid || $connect) echo '<hr class="tgbl-divider" />';
      render_steward_checkbox($paid);
      ?>
    </section>
  </div>
  <?php
}

/**
 * The steward answer as one checkbox. Unticked is "me or my team" — the
 * hub's other value — so the site always leaves Welcome with an answer.
 */
function render_steward_checkbox($paid) {
  $help = $paid
    ? 'Keeps license and renewal news out of this dashboard and emails the account owner instead.'
    : 'Keeps upgrade offers and account news out of this dashboard and emails the account owner instead.';
  ?>
  <label class="tgbl-check">
    <input type="checkbox" name="steward_client" value="1" <?php checked(get_steward() === 'client'); ?> />
    <span class="tgbl-check__body">
      <span class="tgbl-check__title">I am installing this for a client</span>
      <span class="tgbl-check__help"><?php echo esc_html($help); ?> Covers every Tangible plugin on this site.
        You can change this later in Settings.</span>
    </span>
  </label>
  <?php
}

function render_welcome_media($plugin, $config) {
  $logo = $config['logo'] ? set_url_scheme($config['logo']) : '';
  $style = $config['background'] !== '' ? ' style="background:' . esc_attr($config['background']) . '"' : '';
  $rich = $config['headline'] !== '';
  ?>
  <section class="tgbl-media">
    <div class="tgbl-hero<?php echo $rich ? '' : ' is-plain'; ?>"<?php echo $style; ?>>
      <div class="tgbl-hero__copy">
        <?php if ($logo) : ?>
          <img class="tgbl-hero__logo" src="<?php echo esc_url($logo); ?>" alt="" />
        <?php endif; ?>
        <?php if ($rich) : ?>
          <p class="tgbl-hero__headline"><?php echo esc_html($config['headline']);
            if ($config['accent'] !== '') echo ' <em>' . esc_html($config['accent']) . '</em>'; ?></p>
          <?php if ($config['bullets']) : ?>
            <ul class="tgbl-hero__bullets">
              <?php foreach ((array) $config['bullets'] as $b) : ?>
                <li><span class="tgbl-hero__tick"><?php echo check_svg('12px'); ?></span><?php echo esc_html($b); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php else : ?>
          <p class="tgbl-hero__headline"><?php echo esc_html($plugin->title ?? $plugin->name); ?></p>
        <?php endif; ?>
      </div>
      <?php if ($config['inset'] || $config['video']) : ?>
        <div class="tgbl-hero__inset">
          <?php render_inset($config['inset']); ?>
          <?php if ($config['video']) : ?>
            <a class="tgbl-play" href="<?php echo esc_url($config['video']); ?>" target="_blank" rel="noopener"
               aria-label="Watch the video">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($config['stats']) : ?>
      <dl class="tgbl-stats">
        <?php foreach ((array) $config['stats'] as $s) : ?>
          <div class="tgbl-stats__item"><dt><?php echo esc_html($s['value'] ?? ''); ?></dt><dd><?php echo esc_html($s['label'] ?? ''); ?></dd></div>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </section>
  <?php
}

/** The white card inside the hero: a feature grid, a palette, or the plugin's own. */
function render_inset($inset) {
  if (is_callable($inset)) { call_user_func($inset); return; }
  if (!is_array($inset)) return;
  if (($inset['type'] ?? '') === 'features') : ?>
    <ul class="tgbl-inset-features">
      <?php foreach ((array) ($inset['items'] ?? []) as $item) : ?>
        <li><span class="dashicons <?php echo esc_attr($item['icon'] ?? 'dashicons-yes'); ?>" aria-hidden="true"></span><?php
          echo esc_html($item['label'] ?? ''); ?></li>
      <?php endforeach; ?>
    </ul>
  <?php elseif (($inset['type'] ?? '') === 'palette') : ?>
    <div class="tgbl-inset-palette">
      <?php foreach ((array) ($inset['rows'] ?? []) as $row) : ?>
        <div class="tgbl-inset-palette__row">
          <span class="tgbl-inset-palette__label"><?php echo esc_html($row['label'] ?? ''); ?></span>
          <?php foreach ((array) ($row['colors'] ?? []) as $c) : ?>
            <span class="tgbl-inset-palette__swatch" style="background:<?php echo esc_attr(sanitize_hex_color($c) ?: '#ccc'); ?>"></span>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif;
}
