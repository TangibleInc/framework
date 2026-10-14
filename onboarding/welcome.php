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
 *       'media'    => [                              // an image, linked or captioned
 *         'image' => $url,
 *         'alt'   => '',                              // empty when the label says it all
 *         'href'  => 'https://…/whats-new',           // optional; opens in a new tab
 *         'label' => 'What’s new in 3.0',             // visible; the link's name
 *         'kind'  => 'post',                          // 'post' | 'video' (play badge)
 *       ],
 *       'video'    => $url,                         // the round play button; with
 *                                                   // no media image, same as
 *                                                   // media [href, kind: video]
 *       'stats'    => [ [ 'value' => '7,000+', 'label' => 'active installs' ], … ],
 *       'background' => 'linear-gradient(…)',       // the hero's ground
 *     ],
 *   ]);
 *
 * The hero's right-hand column holds the media image when there is one,
 * else the inset (with the video's play button on its corner).
 *
 * Without any of it, the card falls back to the plugin's logo and the
 * description from its plugin header — honest, if plain.
 */
namespace tangible\onboarding;

/** The Description line of the plugin header, tags stripped, in the plugin's own translation. */
function plugin_description($plugin) {
  if (empty($plugin->file_path) || !is_readable($plugin->file_path)) return '';
  if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
  $data = get_plugin_data($plugin->file_path, false, true);
  return trim(wp_strip_all_tags((string) ($data['Description'] ?? '')));
}

function welcome_config($plugin) {
  $title = $plugin->title ?? $plugin->name;
  $config = array_merge([
    /* translators: %s: plugin name */
    'heading'    => sprintf(__('Welcome to %s', 'tangible-framework'), $title),
    'intro'      => '',
    'logo'       => !empty($plugin->logo) ? $plugin->logo : '',
    'headline'   => '',
    'accent'     => '',
    'bullets'    => [],
    'inset'      => null,
    'media'      => null,
    'video'      => '',
    'stats'      => [],
    'background' => '',
  ], (array) (wizard_args($plugin->name)['welcome'] ?? []));
  if ($config['intro'] === '') $config['intro'] = plugin_description($plugin);
  $config = apply_filters('tangible_onboarding_welcome', $config, $plugin);
  if (!is_array($config)) return [];
  $config['media'] = normalize_media($config['media'] ?? null, $config['video'] ?? '');
  return $config;
}

/**
 * The media slot, normalised: null, or every key present. A bare `video`
 * URL is media with no image — the play button — so older registrations
 * keep their look.
 */
function normalize_media($media, $video = '') {
  if (!is_array($media) || (empty($media['image']) && empty($media['href']))) {
    $media = $video !== '' ? ['href' => $video, 'kind' => 'video'] : null;
  }
  if (!$media) return null;
  $media = array_merge(['image' => '', 'alt' => '', 'href' => '', 'label' => '', 'kind' => 'post'], $media);
  $media['kind'] = $media['kind'] === 'video' ? 'video' : 'post';
  return $media;
}

add_filter('tangible_onboarding_steps', function ($steps, $facts, $plugin_name = '') {
  if (empty(registered_wizards()[$plugin_name])) return $steps;

  $steps[] = [
    'id'     => 'welcome',
    'label'  => __('Welcome', 'tangible-framework'),
    'weight' => 5,
    'scope'  => 'plugin',
    'skippable' => false,
    'submit_label' => __('Get started', 'tangible-framework'),
    // Until answered — except on a site that finished this plugin's setup
    // before Welcome existed: its Done record says so, and asking again would
    // turn every finished setup back into "1 step left".
    'needed' => function () use ($plugin_name) {
      $state = get_option(get_state_key($plugin_name, 'plugin'), []);
      return empty($state['done']);
    },
    'skip_note' => _x('set up', 'setup step state', 'tangible-framework'),
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
    ? __('Keeps license and renewal news out of this dashboard and emails the account owner instead.', 'tangible-framework')
    : __('Keeps upgrade offers and account news out of this dashboard and emails the account owner instead.', 'tangible-framework');
  ?>
  <label class="tgbl-check">
    <input type="checkbox" name="steward_client" value="1" <?php checked(get_steward() === 'client'); ?> />
    <span class="tgbl-check__body">
      <span class="tgbl-check__title"><?php esc_html_e('I am installing this for a client', 'tangible-framework'); ?></span>
      <span class="tgbl-check__help"><?php echo esc_html($help); ?>
        <?php esc_html_e('Covers every Tangible plugin on this site. You can change this later in Settings.', 'tangible-framework'); ?></span>
    </span>
  </label>
  <?php
}

function render_welcome_media($plugin, $config) {
  $logo = $config['logo'] ? set_url_scheme($config['logo']) : '';
  $style = $config['background'] !== '' ? ' style="background:' . esc_attr($config['background']) . '"' : '';
  $rich = $config['headline'] !== '';
  $media = $config['media'];
  $image = $media && $media['image'] !== '';
  $aside = $image || $config['inset'] || $media;
  $classes = 'tgbl-hero' . ($rich ? '' : ' is-plain') . ($aside ? ' has-aside' : '');
  ?>
  <section class="tgbl-media">
    <div class="<?php echo esc_attr($classes); ?>"<?php echo $style; ?>>
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
      <?php if ($image) : ?>
        <?php render_media_image($media); ?>
      <?php elseif ($config['inset'] || $media) : ?>
        <div class="tgbl-hero__inset">
          <?php render_inset($config['inset']); ?>
          <?php if ($media) : ?>
            <a class="tgbl-play" href="<?php echo esc_url($media['href']); ?>" target="_blank" rel="noopener"
               aria-label="<?php
                 /* translators: %s: link text, e.g. "Watch the video" */
                 echo esc_attr(sprintf(__('%s (opens in a new tab)', 'tangible-framework'), media_label($media))); ?>">
              <?php echo play_svg(); ?>
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

/** The media's visible label: the plugin's, else one that names the kind. */
function media_label($media) {
  if ($media['label'] !== '') return $media['label'];
  return $media['kind'] === 'video'
    ? __('Watch the video', 'tangible-framework')
    : __('Read more', 'tangible-framework');
}

function play_svg() {
  return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7z"/></svg>';
}

/**
 * The media image. Linked, the image and its visible label are one link
 * and the label is its name (alt adds to it only if the image says more);
 * the new tab is announced, since leaving the wizard's tab would lose
 * the step. Unlinked, it is a captioned figure.
 */
function render_media_image($media) {
  $src = set_url_scheme($media['image']);
  $video = $media['kind'] === 'video';
  if ($media['href'] !== '') : ?>
    <a class="tgbl-hero__figure tgbl-media-link<?php echo $video ? ' is-video' : ''; ?>"
       href="<?php echo esc_url($media['href']); ?>" target="_blank" rel="noopener">
      <span class="tgbl-media-link__frame">
        <img src="<?php echo esc_url($src); ?>" alt="<?php echo esc_attr($media['alt']); ?>" />
        <?php if ($video) : ?><span class="tgbl-media-link__play"><?php echo play_svg(); ?></span><?php endif; ?>
      </span>
      <span class="tgbl-media-link__label"><?php echo esc_html(media_label($media)); ?><span
        class="tgbl-media-link__ext" aria-hidden="true">↗</span><span
        class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'tangible-framework'); ?></span></span>
    </a>
  <?php else : ?>
    <figure class="tgbl-hero__figure">
      <span class="tgbl-media-link__frame">
        <img src="<?php echo esc_url($src); ?>" alt="<?php echo esc_attr($media['alt']); ?>" />
      </span>
      <?php if ($media['label'] !== '') : ?>
        <figcaption class="tgbl-media-link__label"><?php echo esc_html($media['label']); ?></figcaption>
      <?php endif; ?>
    </figure>
  <?php endif;
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
