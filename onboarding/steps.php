<?php
/**
 * The shell's own steps — Cristian's core screens (Plugin Wizard Figma,
 * "[Core] Free/Pro 01–03", 2026-10-14). Three appear in a wizard:
 *
 *   Welcome     every plugin, first (welcome.php). Carries the licence key
 *               (paid), the Connect card (free) and the "installing this for
 *               a client" checkbox (the steward answer). Licence, Connect and
 *               steward used to be rail steps of their own; their logic is
 *               below and in connect/step.php, called from Welcome.
 *   Usage data  anonymous sites only (free, not connected — ADR-011): the
 *               telemetry ask, once per site. Paid and connected sites never
 *               see it; their Terms are the grant.
 *   Done        every plugin, last. Next-step links, plugin notices, and the
 *               release-notes band whose button finishes setup.
 *
 * Choice / Credentials / Verified-state are plugin archetypes: whatever a
 * plugin needs there is its business, the shell just supplies the infra
 * (render/handle contract, the WP_Error test path, submit_label).
 *
 * ## The usage-data ask, in the Wordfence shape
 *
 * The competitive teardown found exactly one decent consent flow shipping at
 * 4M+ installs: forced, unbiased Yes/No that refuses a non-answer. The
 * properties survive the redesign: two footer buttons of equal standing,
 * nothing preselected, no Continue that could mean either — and the payload
 * is SHOWN, this site's actual values, not a policy link.
 *
 * ## The consent outbox
 *
 * Account facts live server-side and this module records no local shadow of
 * them. But an answer given in a wizard must reach the server, and the site
 * may not reach our API at that moment — so the answer lands in a local
 * outbox (option `tangible_consent_outbox`): exact wording shown, answer,
 * time, synced=false. The platform client claims and syncs it; until then
 * build_facts reads it as "answered, sync pending" so the wizard never
 * re-asks its own unsynced answer. Client half of the integrationOutbox
 * pattern — the envelope, not the record.
 */
namespace tangible\onboarding;

use tangible\framework;
use tangible\onboarding;

const CONSENT_OUTBOX = 'tangible_consent_outbox';

function get_consent_answer($key) {
  $outbox = get_option(CONSENT_OUTBOX, []);
  return $outbox[$key]['answer'] ?? null;
}

function record_consent_answer($key, $answer, $consent_text, $email = '') {
  $outbox = get_option(CONSENT_OUTBOX, []);
  $outbox[$key] = [
    // Free (anonymous) marketing only: the address typed in the wizard. It
    // goes to the platform's double opt-in, never into an interaction.
    'email'        => $email,
    'answer'       => $answer,           // 'granted' | 'declined'
    'consent_text' => $consent_text,     // the exact wording shown — provable later
    'at'           => time(),
    'synced'       => false,
    // Identity of THIS answer, so a redelivery of the same unsynced batch
    // carries the same source_operation_id and the server records it once.
    'op'           => wp_generate_uuid4(),
  ];
  update_option(CONSENT_OUTBOX, $outbox, false);
  do_action('tangible_consent_recorded', $key, $answer);
}

// Outbox answers silence the ask before the server has confirmed it.
add_filter('tangible_onboarding_facts', function ($facts) {
  foreach (['telemetry_extended', 'marketing'] as $key) {
    if (($facts->ask[$key] ?? null) === 'ask' && get_consent_answer($key) !== null) {
      $facts->ask[$key] = 'skip';
    }
  }
  return $facts;
}, 5);

/**
 * Deliver unsynced outbox answers to the platform.
 *
 * Auth is the plugin's licence key (same trust as activation), else the
 * site's Connect token, else none (an anonymous free plugin); the endpoint is
 * consent_endpoint(), action `tangible_sync_consent`. Server side is
 * idempotent — a redelivered answer that matches resolved state is dropped
 * there — so this client can retry forever without bookkeeping beyond the
 * synced flag.
 *
 * A paid plugin without its key yet → answers simply wait; every call is a
 * cheap no-op until the key exists. Failure of any kind leaves synced=false
 * and the next trigger retries. Never blocks rendering: one POST, 15s cap,
 * outcome recorded and forgotten.
 */
function attempt_consent_sync($plugin) {
  if (!$plugin) return;
  $key = function_exists('tangible\\updater\\get_license_key') && !empty($plugin->activation_url)
    ? \tangible\updater\get_license_key($plugin) : '';
  // Who is delivering, in order of authority: a licence key (paid); else the
  // site's Connect token (connected free plugin, account-level as keyed); else
  // nothing but a site and a plugin name (anonymous free plugin, spec §1). A
  // paid plugin without its key waits for it.
  $site_token = empty($key) && function_exists('tangible\\connect\\get_active_token')
    ? \tangible\connect\get_active_token() : '';
  if (empty($key) && $site_token === '' && !is_free_distribution($plugin)) return;

  $outbox = get_option(CONSENT_OUTBOX, []);
  $answers = [];
  $ops = [];
  foreach ($outbox as $consent_key => $entry) {
    if (empty($entry['synced'])) {
      $answer = [
        'key'         => $consent_key,
        'answer'      => $entry['answer'],
        'consentText' => $entry['consent_text'],
        'at'          => (int) (($entry['at'] ?? 0) * 1000),   // seconds → ms, the platform clock unit
      ];
      // Only an anonymous delivery carries the typed address; a connected one
      // uses the account's verified email.
      if (empty($key) && $site_token === '' && $consent_key === 'marketing' && !empty($entry['email'])) {
        $answer['email'] = $entry['email'];
      }
      $answers[] = $answer;
      $ops[] = $entry['op'] ?? ($consent_key . ':' . ($entry['at'] ?? 0));
    }
  }
  if (!$answers) return;

  $args = [
    'timeout'   => 15,
    // Same switch as the updater's licence calls: verified unless a local-dev
    // opt-out says otherwise.
    'sslverify' => function_exists('tangible\\updater\\should_verify_ssl')
      ? \tangible\updater\should_verify_ssl($plugin) : true,
    'body'      => consent_sync_body($plugin, $key, $answers, $ops),
  ];
  if ($site_token !== '') {
    // The token rides in a header, over VERIFIED TLS — never sslverify=false,
    // and names the URL the connection is bound to.
    unset($args['sslverify']);
    $args['body']['url'] = \tangible\connect\site_address();
    $args['headers'] = [ \tangible\connect\HEADER => $site_token ];
  }
  $response = wp_remote_post(consent_endpoint($plugin), $args);
  if (is_wp_error($response)) return;
  if ($site_token !== '' && wp_remote_retrieve_response_code($response) === 401) {
    // Revoked on tangible.one: fall back to Anonymous; answers stay unsynced.
    \tangible\connect\clear();
    return;
  }
  $body = json_decode(wp_remote_retrieve_body($response));
  if (empty($body->success)) return;

  foreach ($answers as $a) {
    if (isset($outbox[ $a['key'] ])) {
      $outbox[ $a['key'] ]['synced'] = true;
      $outbox[ $a['key'] ]['synced_at'] = time();
    }
  }
  update_option(CONSENT_OUTBOX, $outbox, false);
}

/**
 * Where consent answers go: the plugin's own licence endpoint when the updater
 * set one, else the tangible.one API (Connect's api_base(), which honours
 * TANGIBLE_CLOUD_URL) — a build without the updater still delivers.
 */
function consent_endpoint($plugin) {
  if (!empty($plugin->activation_url)) return (string) $plugin->activation_url;
  $base = function_exists('tangible\\connect\\api_base') ? \tangible\connect\api_base() : 'https://api.tangible.one';
  return rtrim($base, '/') . '/api/edd';
}

/**
 * The request body for one consent delivery. Besides the answers, it carries
 * the site/plugin context the platform records with each consent interaction
 * (tangible-one PR #236), and one source_operation_id for the batch: derived
 * from the answers' own ids, so a retry of the same unsynced batch repeats
 * it and the server records the delivery once.
 */
function consent_sync_body($plugin, $key, $answers, $ops) {
  $body = [
    'edd_action' => 'tangible_sync_consent',
    'license'    => (string) $key,
    'url'        => home_url(),
    'answers'    => wp_json_encode($answers),
    'slug'       => $plugin->name,
    'source_operation_id' => 'wizard-batch:' . md5(implode('|', $ops)),
  ];
  if (!empty($plugin->version)) $body['version'] = (string) $plugin->version;
  if (function_exists('tangible\\updater\\get_install_id')) {
    $install_id = \tangible\updater\get_install_id($plugin);
    if (!empty($install_id)) $body['install_id'] = $install_id;
  }
  return $body;
}

/**
 * The steward answer — "who will look after this site?" — is ONE site option,
 * shared by every Tangible plugin on the site (decided 2026-09-25): never on
 * tangible.one, and not keyed by account or licence, even when several
 * licences are used on one site. If the options are wiped, asking again is
 * fine.
 *
 * Reads the older shapes: a per-account map (2026-08-28 cut) collapses to
 * "client" only when every recorded answer says so — one self-managed answer
 * keeps the site personal, the same rule the hub already applied.
 */
const STEWARD_OPTION = 'tangible_site_steward';

function get_steward() {
  $value = get_option(STEWARD_OPTION, null);
  if (is_string($value)) return in_array($value, ['team', 'client'], true) ? $value : null;
  if (is_array($value) && $value) {
    $answers = array_values(array_filter($value, function ($v) {
      return in_array($v, ['team', 'client'], true);
    }));
    if (!$answers) return null;
    return count(array_unique($answers)) === 1 && $answers[0] === 'client' ? 'client' : 'team';
  }
  return null;
}

function set_steward($value) {
  if (!in_array($value, ['team', 'client'], true)) return;
  update_option(STEWARD_OPTION, $value, false);
}

/**
 * Free (distribution decided by the server, cached by the updater from its
 * update check). Before the first answer is cached there is nothing to go on,
 * and the licence step's own machinery predicate decides alone.
 */
/** Free and not connected: the server knows only a URL and a plugin name. */
function is_anonymous($plugin) {
  $connected = function_exists('tangible\\connect\\get_active_token')
    && \tangible\connect\get_active_token() !== '';
  return is_free_distribution($plugin) && !$connected;
}

function is_free_distribution($plugin) {
  if (!$plugin) return false;
  if (function_exists('tangible\\updater\\is_free_distribution')
    && \tangible\updater\is_free_distribution($plugin)) return true;
  $known = function_exists('tangible\\updater\\is_distribution_known')
    && \tangible\updater\is_distribution_known($plugin);
  // No verdict, and THIS plugin never registered with the updater: a build that
  // ships without it (wordpress.org forbids self-updating). Nothing can hold a
  // licence key or unlock paid features in it, so it is free by construction —
  // otherwise it would stay "unknown" forever and never be asked anything a free
  // plugin is asked. A registered plugin waits for the server's verdict.
  return !$known && !registered_with_updater($plugin);
}

/**
 * The updater registered THIS plugin — another plugin's updater on the site
 * doesn't count. Evidence: its update checker, or the activation_url the
 * updater's register_plugin() gives it (see licence_applies()). A wordpress.org
 * build must not pass activation_url itself, or it reads as registered.
 */
function registered_with_updater($plugin) {
  if (!empty($plugin->activation_url)) return true;
  if (!class_exists('tangible\\updater') || !isset(\tangible\updater::$instance)) return false;
  $checkers = (array) (\tangible\updater::$instance->update_checkers ?? []);
  return isset($checkers[ $plugin->name ?? '' ]);
}

/**
 * Paid: the server has answered and the answer was not free. Unknown is not
 * paid, and neither is a plugin without the updater (a wordpress.org build).
 */
function is_licensed_distribution($plugin) {
  return $plugin && function_exists('tangible\\updater\\is_distribution_known')
    && \tangible\updater\is_distribution_known($plugin)
    && !is_free_distribution($plugin);
}

/**
 * A development site: WordPress's own environment type when set to local or
 * development, else a host that only resolves on a developer's machine.
 * WP_ENVIRONMENT_TYPE defaults to production, so the host check does most of
 * the work in practice. Staging is not dev: a staging clone of a live site
 * normally carries the licence.
 */
function is_dev_site() {
  $dev = in_array(wp_get_environment_type(), ['local', 'development'], true);
  if (!$dev) {
    // One host rule, shared with Connect's is_local_site().
    $dev = \tangible\connect\is_local_host(wp_parse_url(home_url(), PHP_URL_HOST));
  }
  return (bool) apply_filters('tangible_onboarding_is_dev_site', $dev);
}

/**
 * Whether the platform knows who this site belongs to: a licence key (paid)
 * or an active Connect token (connected free). With neither, an email answer
 * has no account to land on, so the wizard asks for the address itself and
 * the platform confirms it first (double opt-in).
 */
function has_account_identity($plugin) {
  $key = $plugin && function_exists('tangible\\updater\\get_license_key')
    ? \tangible\updater\get_license_key($plugin) : '';
  $connected = function_exists('tangible\\connect\\get_active_token')
    && \tangible\connect\get_active_token() !== '';
  return !empty($key) || $connected;
}

/** The name a sentence uses ("Design Upgrade works…"); the title otherwise. */
function short_title($plugin) {
  $args = $plugin ? wizard_args($plugin->name) : [];
  return (string) ($args['short_title'] ?? ($plugin->title ?? ($plugin->name ?? '')));
}

// ── Licence — part of Welcome on paid builds ─────────────────────────────

/**
 * A licence ask needs activation machinery behind it, or it could only end
 * in an error, and "no machinery" has three spellings:
 *
 *   no cloud_id            — a free build, nothing to activate
 *   no updater functions   — the wp.org build deliberately ships without the
 *                            updater (two things claiming one slug)
 *   no activation_url      — THIS plugin never registered with the updater,
 *                            even though another plugin on the site loaded
 *                            its functions. activation_url is only defaulted
 *                            inside the updater's own register_plugin(), so
 *                            without it the key would be posted to null.
 *
 * function_exists() answers a site-wide question; activation_url answers the
 * per-plugin one, which is the question that actually matters here. Same
 * predicate attempt_consent_sync() uses above, for the same reason.
 */
function licence_applies($plugin) {
  return $plugin && !empty($plugin->cloud_id) && !empty($plugin->activation_url)
    && function_exists('tangible\\updater\\get_license_key')
    && !is_free_distribution($plugin);
}

function licence_is_active($plugin) {
  return $plugin && function_exists('tangible\\updater\\get_license_status')
    && in_array(\tangible\updater\get_license_status($plugin), ['valid', 'active'], true);
}

const LICENCE_INFO_OPTION = 'tangible_onboarding_licence__';

/** What the last activation said about the licence: item name, expiry. */
function licence_info($plugin) {
  $info = get_option(LICENCE_INFO_OPTION . $plugin->name, []);
  return is_array($info) ? $info : [];
}

/**
 * Activate $key for $plugin — a REAL activation, through the updater's own
 * machinery; no second activation path exists. Returns true or a WP_Error
 * whose message the shell shows. The outcome rules, so no state leaks water:
 *
 *   key rejected (invalid/expired/revoked/no seats/site inactive)
 *     → WP_Error with the updater's own message; Welcome stays open.
 *       A wrong key fails HERE, not at the first missed update.
 *   our API unreachable (timeout, DNS, firewall)
 *     → the key is SAVED, status set to the real error-free unknown, and
 *       setup goes on. OUR outage never blocks THEIR setup; the updater's
 *       cron checker retries and the plugins-row notice reappears if the
 *       key turns out bad.
 *   success
 *     → status stored where settings page, plugins row and hub all read it,
 *       so no surface can disagree. If the response carries an `onboarding`
 *       block (steward, consent decisions), it is cached as facts for the
 *       rest of this wizard; its expiry is kept for Welcome's status line.
 */
function activate_licence($plugin, $key) {
  if (!function_exists('tangible\\updater\\cloud_endpoint')) {
    return new \WP_Error('tangible_onboarding', __('The updater module is not available on this site.', 'tangible-framework'));
  }

  // A different key can mean a different ACCOUNT: stale decisions from the
  // old account must never govern the new one's wizard, so the facts cache
  // dies with the old key. The activation response repopulates it.
  delete_option('tangible_onboarding_facts_cache__' . $plugin->name);
  delete_option(LICENCE_INFO_OPTION . $plugin->name);

  // Save first — the exact subfield the update checker reads. Even on the
  // deferred path, updates must query with this key.
  framework\update_plugin_settings($plugin, [
    \tangible\updater\get_license_key_setting_field() => $key,
  ]);

  $response = \tangible\updater\cloud_endpoint($plugin, $key, 'activate_license');

  if (is_wp_error($response)) {
    // Unreachable ≠ rejected. Defer, don't block.
    \tangible\updater\set_license_status($plugin, 'pending');
    return true;
  }

  $body = \tangible\updater\response_body($response);
  $code = \tangible\updater\response_code($response);

  if ($code === 403 || empty($body->success)) {
    \tangible\updater\set_license_status($plugin, $body->license ?? 'invalid');
    $failed = __('License validation failed.', 'tangible-framework');
    $message = $code === 403
      ? ($body->error ?? $failed)
      : \tangible\updater\check_license_response($body, $plugin);
    return new \WP_Error('tangible_onboarding', is_string($message) ? $message : $failed);
  }

  \tangible\updater\set_license_status($plugin, $body->license ?? 'valid');
  update_option(LICENCE_INFO_OPTION . $plugin->name, array_filter([
    'item'    => sanitize_text_field($body->item_name ?? ''),
    'expires' => sanitize_text_field($body->expires ?? ''),
  ]), false);

  // The facts seam: when the platform's activate response carries onboarding
  // decisions, they land here and build_facts reads them.
  if (!empty($body->onboarding)) {
    // Deep-convert: a (array) cast is shallow and leaves nested stdClass
    // objects, which the reader's is_array guard then rejects — the bug
    // where a good skip/skip block was ignored and answered questions were
    // asked again.
    $data = json_decode(wp_json_encode($body->onboarding), true);
    update_option('tangible_onboarding_facts_cache__' . $plugin->name,
      [ 'at' => time(), 'data' => is_array($data) ? $data : [] ], false);
  }
  // Answers given before the key existed can be delivered now.
  attempt_consent_sync($plugin);
  return true;
}

/**
 * Welcome's Get started, for the licence half. Paid stays mandatory: no
 * active licence (or a deferred one, see above) means no way past Welcome.
 */
function handle_licence_field($plugin) {
  $key = sanitize_text_field(wp_unslash($_POST['license_key'] ?? ''));
  $existing = (string) \tangible\updater\get_license_key($plugin);
  if (licence_is_active($plugin) && ($key === '' || $key === $existing)) return true;
  if ($key === '') {
    return new \WP_Error('tangible_onboarding',
      __('Enter your license key to continue. You can find it in your tangible.one account.', 'tangible-framework'));
  }
  return activate_licence($plugin, $key);
}

/** The licence half of Welcome's form card (Figma "[Core] Pro 01 Welcome"). */
function render_licence_field($plugin) {
  $existing = (string) \tangible\updater\get_license_key($plugin);
  $status = function_exists('tangible\\updater\\get_license_status')
    ? (string) \tangible\updater\get_license_status($plugin) : '';
  $info = licence_info($plugin);
  ?>
  <h3 class="tgbl-card__title"><?php esc_html_e('Your license key', 'tangible-framework'); ?></h3>
  <p class="tgbl-card__text"><?php
    printf(
      /* translators: %s: link to the customer's tangible.one account, "tangible.one account" */
      esc_html__('Find it in your %s. It activates this site and unlocks updates.', 'tangible-framework'),
      '<a href="https://tangible.one/licensing" target="_blank" rel="noopener">' . esc_html__('tangible.one account', 'tangible-framework') . '</a>'
    ); ?></p>
  <label class="tui-visually-hidden" for="tgbl-license-key"><?php esc_html_e('License key', 'tangible-framework'); ?></label>
  <input type="text" id="tgbl-license-key" name="license_key" class="tgbl-input code" autocomplete="off"
         spellcheck="false" value="<?php echo esc_attr($existing); ?>" placeholder="TGBL-XXXX-XXXX-XXXX" />
  <?php
  if (licence_is_active($plugin)) {
    $line = __('Your license is valid.', 'tangible-framework');
    $item = $info['item'] ?? ($plugin->title ?? '');
    $expires = $info['expires'] ?? '';
    /* translators: %s: product name on the licence */
    if ($expires === 'lifetime') $line .= ' ' . sprintf(__('%s, lifetime.', 'tangible-framework'), $item);
    /* translators: 1: product name on the licence, 2: renewal date */
    elseif ($expires !== '' && strtotime($expires)) $line .= ' ' . sprintf(__('%1$s, renews %2$s.', 'tangible-framework'), $item, date_i18n('j F Y', strtotime($expires)));
    echo '<p class="tgbl-status is-ok">' . check_svg() . '<span>' . esc_html($line) . '</span></p>';
    // A licensed site is never asked about usage data (the Terms are the
    // grant, ADR-011), so this is where it is told (Figma "[Core] Pro 01").
    if (!site_telemetry_refused()) echo '<p class="tgbl-status">' . esc_html(terms_usage_disclosure()) . '</p>';
  } elseif ($status === 'pending' && $existing !== '') {
    echo '<p class="tgbl-status">' . esc_html__('Saved. tangible.one could not be reached to confirm it, so it will be checked again shortly.', 'tangible-framework') . '</p>';
  }
}

// ── Usage data (anonymous sites only) ────────────────────────────────────

/**
 * The values the Usage data card shows — this site's real numbers, the
 * facts that would be sent. Plugins add their own content to the "Published
 * content" row (Design Upgrade: courses and lessons) through
 * `tangible_onboarding_content_counts`, as label => count:
 *
 *   add_filter('tangible_onboarding_content_counts', function ($counts, $plugin) {
 *     $counts['courses and lessons'] = $n;
 *     return $counts;
 *   }, 10, 2);
 *
 * Whole rows can still be changed through `tangible_onboarding_usage_rows`.
 */
function usage_rows($plugin) {
  global $wp_version;
  $posts = wp_count_posts('post'); $pages = wp_count_posts('page');
  $users = function_exists('count_users') ? count_users() : [ 'total_users' => 0, 'avail_roles' => [] ];
  $roles = count(array_filter((array) ($users['avail_roles'] ?? []), function ($n, $role) {
    return $n > 0 && $role !== 'none';
  }, ARRAY_FILTER_USE_BOTH));
  $theme = wp_get_theme();

  // The framework's own key stays English, so a filter can address it on
  // any locale; it is translated only when shown.
  $counts = (array) apply_filters('tangible_onboarding_content_counts', [
    'posts and pages' => (int) ($posts->publish ?? 0) + (int) ($pages->publish ?? 0),
  ], $plugin);
  $content = [];
  foreach ($counts as $label => $n) {
    if ($label === 'posts and pages') $label = __('posts and pages', 'tangible-framework');
    $content[] = number_format_i18n((int) $n) . ' ' . $label;
  }

  // One row per group of what a check-in sends, each with this site's value
  // (Cristian's copy, Plugin Wizard Figma "Free 02"). The keys stay English —
  // they are what `tangible_onboarding_usage_rows` filters address — and are
  // shown, and recorded in the consent wording, through usage_row_label().
  // Renaming one is a new consent wording.
  $rows = [
    'WordPress and PHP versions' => $wp_version . ' · PHP ' . implode('.', array_slice(explode('.', PHP_VERSION), 0, 2)),
    'Theme'                      => $theme->exists() ? $theme->get('Name') : '—',
    'Active plugins'             => number_format_i18n(count((array) get_option('active_plugins', []))),
    /* translators: 1: number of users, 2: number of roles */
    'Users and roles'            => sprintf(__('%1$s users · %2$s roles', 'tangible-framework'),
      number_format_i18n((int) ($users['total_users'] ?? 0)), number_format_i18n($roles)),
    'Published content'          => implode(' · ', $content),
    'Hosting provider'           => detect_host() ?: __('Not detected', 'tangible-framework'),
    'Page speed'                 => __('Weekly averages, measured on this site', 'tangible-framework'),
  ];
  $rows = apply_filters('tangible_onboarding_usage_rows', $rows, $plugin);
  return is_array($rows) ? $rows : [];
}

/** A usage row's label as shown: the core rows translated, a plugin's own as given. */
function usage_row_label($key) {
  $labels = [
    'WordPress and PHP versions' => __('WordPress and PHP versions', 'tangible-framework'),
    'Theme'                      => __('Theme', 'tangible-framework'),
    'Active plugins'             => __('Active plugins', 'tangible-framework'),
    'Users and roles'            => __('Users and roles', 'tangible-framework'),
    'Published content'          => __('Published content', 'tangible-framework'),
    'Hosting provider'           => __('Hosting provider', 'tangible-framework'),
    'Page speed'                 => __('Page speed', 'tangible-framework'),
  ];
  return $labels[$key] ?? (string) $key;
}

/**
 * The hosting provider, from the constants and environment each host sets —
 * never from the IP address. '' when nothing matches. Filterable for hosts
 * this list misses (`tangible_onboarding_host`).
 */
function detect_host() {
  $signs = [
    'Kinsta'           => fn() => defined('KINSTAMU_VERSION') || defined('KINSTA_CACHE_ZONE'),
    'WP Engine'        => fn() => defined('WPE_APIKEY') || function_exists('is_wpe'),
    'Pressable'        => fn() => defined('IS_PRESSABLE'),
    'WordPress.com'    => fn() => defined('IS_ATOMIC') || defined('WPCOMSH_VERSION'),
    'Pantheon'         => fn() => isset($_ENV['PANTHEON_ENVIRONMENT']) || defined('PANTHEON_ENVIRONMENT'),
    'Flywheel'         => fn() => defined('FLYWHEEL_CONFIG_DIR'),
    'SiteGround'       => fn() => defined('SG_OPTIMIZER_VERSION') || is_dir(WP_CONTENT_DIR . '/mu-plugins/sg-security'),
    'GoDaddy'          => fn() => defined('GD_SYSTEM_PLUGIN_DIR') || class_exists('\\WPaaS\\Plugin'),
    'Cloudways'        => fn() => (bool) preg_match('/\.cloudwaysapps\.com$/', (string) wp_parse_url(home_url(), PHP_URL_HOST)),
    'DreamHost'        => fn() => defined('DREAMPRESS_VERSION'),
    'Bluehost'         => fn() => defined('BLUEHOST_PLUGIN_VERSION') || class_exists('\\Bluehost\\Plugin'),
    'Local development' => fn() => is_dev_site(),
  ];
  $found = '';
  foreach ($signs as $name => $test) {
    if ($test()) { $found = $name; break; }
  }
  // Host names are names; this one is a description, shown as the value.
  if ($found === 'Local development') $found = __('Local development', 'tangible-framework');
  return (string) apply_filters('tangible_onboarding_host', $found);
}

/**
 * Told to licensed and connected sites, who are never asked (Cristian's copy,
 * Plugin Wizard Figma). The constants are the English source, kept for code
 * that read them; what is shown comes from the functions, translated.
 */
const TERMS_USAGE_DISCLOSURE = 'Usage data from this site is now shared under your tangible.one terms. '
  . 'You can turn it off in Settings → Tangible Plugins.';

const USAGE_NOTE ='Never your content, your users or your visitors. One answer covers every Tangible plugin '
  . 'on this site, and you can change it any time in Settings.';

function terms_usage_disclosure() {
  return __('Usage data from this site is now shared under your tangible.one terms. You can turn it off in Settings → Tangible Plugins.', 'tangible-framework');
}

function usage_note() {
  return __('Never your content, your users or your visitors. One answer covers every Tangible plugin on this site, and you can change it any time in Settings.', 'tangible-framework');
}

/**
 * The exact wording shown, row labels included — provable later. Recorded in
 * the language it was shown in; the version tag stays English so the record
 * is identifiable whatever the language. In English it is byte-identical to
 * the wording recorded before translation existed.
 */
function telemetry_consent_text($rows) {
  /* translators: %s: comma-separated list of what would be sent */
  return sprintf(__('Share usage data from this site? Exactly what would be sent: %s.', 'tangible-framework'),
      implode(', ', array_map(__NAMESPACE__ . '\\usage_row_label', array_keys($rows))))
    . ' ' . usage_note() . ' (telemetry v2, site answer, wizard v3)';
}

/** The release-notes band's wording, per plugin; the note after it is the record's, not shown. */
function marketing_consent_text($plugin) {
  return release_notes_title($plugin)
    . '. Recorded when Finish Setup is pressed with this box shown. (plugin wizard opt-in v2, Done band)';
}

function release_notes_title($plugin) {
  $name = $plugin ? short_title($plugin) : '';
  /* translators: %s: plugin name */
  return sprintf(__('Get release notes from the makers of %s', 'tangible-framework'),
    $name !== '' ? $name : __('this plugin', 'tangible-framework'));
}

add_filter('tangible_onboarding_steps', function ($steps, $facts, $plugin_name = '') {

  // Core steps belong to wizards, not to every resolve_plan() call.
  if (empty(registered_wizards()[$plugin_name])) return $steps;

  $plugin = function_exists('tangible\\framework\\get_plugin')
    ? framework\get_plugin($plugin_name) : null;

  // ADR-011 §4: only an UNCONNECTED FREE plugin is asked, once per site. A
  // licensed or connected site's account accepted the Terms, which are the
  // grant — whatever an older server's block says. Its refusal is the
  // per-site setting (site-telemetry.php), not a wizard question.
  $ask_telemetry = is_anonymous($plugin)
    && ($facts->ask['telemetry_extended'] ?? null) === 'ask';

  $steps[] = [
    'id'     => 'usage',
    'label'  => __('Usage data', 'tangible-framework'),
    'weight' => 20,
    // Recorded per plugin so the rail keeps it as done for THIS setup; the
    // answer itself is the site-wide outbox entry every plugin reads.
    'scope'  => 'plugin',
    'width'  => 'narrow',
    'skippable' => false,   // "Don't share" is a complete answer, so there is no skip
    'hidden_when_skipped' => true,
    'needed' => function () use ($ask_telemetry) { return $ask_telemetry; },
    'skip_note' => _x('on file', 'setup step state', 'tangible-framework'),
    'actions' => [
      'declined' => [ 'label' => __("Don't share", 'tangible-framework'), 'style' => 'outline' ],
      'granted'  => [ 'label' => __('Share usage data', 'tangible-framework'), 'style' => 'primary' ],
    ],
    'render' => function ($plugin) {
      $rows = usage_rows($plugin);
      ?>
      <h2><?php esc_html_e('Share usage data from this site?', 'tangible-framework'); ?></h2>
      <p class="step-intro"><?php
        /* translators: %s: plugin's short name */
        echo esc_html(sprintf(__('Optional. %s works exactly the same either way, so No is a complete answer.', 'tangible-framework'), short_title($plugin))); ?></p>
      <section class="tgbl-card">
        <h3 class="tgbl-card__title"><?php esc_html_e('Exactly what would be sent', 'tangible-framework'); ?></h3>
        <dl class="tgbl-facts">
          <?php foreach ($rows as $label => $value) : ?>
            <div class="tgbl-facts__row"><dt><?php echo esc_html(usage_row_label($label)); ?></dt><dd><?php echo esc_html($value); ?></dd></div>
          <?php endforeach; ?>
        </dl>
        <p class="tgbl-card__note"><?php echo esc_html(usage_note()); ?></p>
      </section>
      <?php
    },
    'handle' => function ($plugin) {
      $answer = $_POST['answer'] ?? '';
      if (!in_array($answer, ['granted', 'declined'], true)) return false;
      record_consent_answer('telemetry_extended', $answer, telemetry_consent_text(usage_rows($plugin)));
      // An anonymous site delivers with its URL and plugin name alone.
      if ($plugin) attempt_consent_sync($plugin);
      return true;
    },
    // Back reopens the question: the outbox entry is the answer, so clear it.
    // The new answer replaces it the moment one of the buttons is pressed.
    'on_back' => function () {
      $outbox = get_option(CONSENT_OUTBOX, []);
      unset($outbox['telemetry_extended']);
      update_option(CONSENT_OUTBOX, $outbox, false);
    },
  ];

  return $steps;
}, 5, 3);

/**
 * Done — the last screen, a core step for every wizard (Figma "[Core] Free
 * 03 Done" and "03b"). Registered late so a plugin that ships its own `done`
 * step keeps it (SearchSync does).
 *
 * A plugin shapes it through `tangible_onboarding_done`:
 *
 *   add_filter('tangible_onboarding_done', function ($done, $plugin) {
 *     if ($plugin->name !== 'my-plugin') return $done;
 *     $done['summary'] = 'Ready — no merges run yet.';
 *     $done['primary'] = [ 'label' => 'Open Merge User Accounts', 'url' => admin_url('tools.php?page=…') ];
 *     $done['links'][] = [ 'label' => 'How merges work', 'url' => 'https://…', 'kind' => 'Article' ];
 *     // A warning right under the heading (Figma 03b):
 *     $done['heading'] = 'My Plugin is installed';
 *     $done['notices'][] = [ 'title' => '…', 'text' => '…', 'actions' => [
 *       [ 'label' => 'Open settings', 'url' => …, 'style' => 'button' ],
 *       [ 'label' => 'Read why', 'url' => …, 'style' => 'link' ],
 *     ] ];
 *     return $done;
 *   }, 10, 2);
 *
 * `primary` is where Finish lands. Links open in a new tab, so setup is still
 * there to finish.
 *
 * The email band shows while marketing is not on file for the site (an
 * outbox answer or the server's skip both count). Its button is the step's
 * Continue: pressing "Finish Setup" there records the release-notes opt-in —
 * implemented as drawn; whether finishing should mean opting in is an open
 * design question. With marketing on file, the footer carries a plain
 * "Finish setup" instead.
 */
add_filter('tangible_onboarding_steps', function ($steps, $facts, $plugin_name = '') {
  if (empty(registered_wizards()[$plugin_name])) return $steps;
  foreach ($steps as $s) if (($s['id'] ?? null) === 'done') return $steps;

  $plugin = function_exists('tangible\\framework\\get_plugin')
    ? framework\get_plugin($plugin_name) : null;
  $title = $plugin->title ?? $plugin_name;
  $done = apply_filters('tangible_onboarding_done', [
    /* translators: %s: plugin name */
    'heading' => sprintf(__('%s is ready', 'tangible-framework'), $plugin ? short_title($plugin) : $title),
    'summary' => '',
    'notices' => [],
    'links_label' => __('What people usually do next', 'tangible-framework'),
    'primary' => [ 'label' => __('Go to Tangible Hub', 'tangible-framework'), 'url' => \tangible\hub\url() ],
    'links'   => [],
  ], $plugin);
  $ask_marketing = is_object($facts) && ($facts->ask['marketing'] ?? null) === 'ask';

  $steps[] = [
    'id'     => 'done',
    'label'  => __('Done', 'tangible-framework'),
    'weight' => 1000,
    // Terminal: never a reason on its own to redirect, notify, or count as
    // "steps left" — it only closes a wizard that asked something.
    'terminal' => true,
    'scope'  => 'plugin',
    'skippable' => false,
    'width'  => 'narrow',
    'align'  => 'center',
    'submit_in_body' => $ask_marketing,
    'submit_label' => __('Finish setup', 'tangible-framework'),
    'redirect_to'  => $done['primary']['url'] ?? \tangible\hub\url(),
    'render' => function ($plugin) use ($done, $ask_marketing) {
      render_done($plugin, $done, $ask_marketing);
    },
    'handle' => function ($plugin) use ($ask_marketing) {
      if (!$ask_marketing || !$plugin) return true;
      // A site with an account sends nothing but the answer: the platform
      // uses the account's verified email. Anonymous: the typed address,
      // confirmed by email before anything is sent to it (double opt-in).
      $email = '';
      if (!has_account_identity($plugin)) {
        $email = sanitize_email(wp_unslash($_POST['marketing_email'] ?? ''));
        if (!is_email($email)) {
          return new \WP_Error('tangible_onboarding', __('Enter an email address for the release notes.', 'tangible-framework'));
        }
      }
      record_consent_answer('marketing', 'granted', marketing_consent_text($plugin), $email);
      attempt_consent_sync($plugin);
      return true;
    },
  ];
  return $steps;
}, 100, 3);

function render_done($plugin, $done, $ask_marketing) {
  ?>
  <h2><?php echo esc_html($done['heading'] ?? ''); ?></h2>
  <?php if (!empty($done['summary'])) : ?>
    <p class="step-intro"><?php echo esc_html($done['summary']); ?></p>
  <?php endif; ?>

  <?php foreach ((array) ($done['notices'] ?? []) as $notice) render_done_notice($notice); ?>

  <?php if (!empty($done['links'])) : ?>
    <section class="tgbl-next">
      <p class="tgbl-next__label"><?php echo esc_html($done['links_label'] ?? __('What people usually do next', 'tangible-framework')); ?></p>
      <ul class="tgbl-card tgbl-links">
        <?php foreach ($done['links'] as $link) : ?>
          <li class="tgbl-links__row">
            <a href="<?php echo esc_url($link['url'] ?? '#'); ?>" target="_blank" rel="noopener"><?php
              echo esc_html($link['label'] ?? ''); ?></a>
            <?php if (!empty($link['kind'])) : ?><span class="tgbl-tag"><?php echo esc_html($link['kind']); ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if ($ask_marketing) :
    $typed = !has_account_identity($plugin);
    // The connection's masked address names the right account only when the
    // delivery goes through Connect, i.e. this plugin has no licence key.
    $keyed = function_exists('tangible\\updater\\get_license_key') && !empty(\tangible\updater\get_license_key($plugin));
    $connect_state = !$keyed && function_exists('tangible\\connect\\get_state') ? \tangible\connect\get_state() : [];
    ?>
    <section class="tgbl-band tgbl-optin">
      <h3 class="tgbl-band__title"><?php echo esc_html(release_notes_title($plugin)); ?></h3>
      <?php if ($typed) : ?>
        <label class="tgbl-band__label" for="tgbl-marketing-email"><?php esc_html_e('Send to', 'tangible-framework'); ?></label>
        <input type="email" id="tgbl-marketing-email" name="marketing_email" class="tgbl-input" required
               value="<?php echo esc_attr(wp_get_current_user()->user_email ?? ''); ?>" />
      <?php else : ?>
        <p class="tgbl-band__label"><?php esc_html_e('Send to', 'tangible-framework'); ?></p>
        <p class="tgbl-band__value"><?php
          $masked = (string) ($connect_state['owner_email_masked'] ?? '');
          echo esc_html($masked !== ''
            /* translators: %s: the account's email address, partly masked */
            ? sprintf(__('%s, your tangible.one account email', 'tangible-framework'), $masked)
            : __('The email on your tangible.one account', 'tangible-framework')); ?></p>
      <?php endif; ?>
      <button class="tui-button is-theme-primary tgbl-band__submit" type="submit" name="do" value="continue"><?php esc_html_e('Finish Setup', 'tangible-framework'); ?> 🎉</button>
    </section>
  <?php endif;
}

/**
 * A warning under the Done heading (Figma 03b, layout rules "A warning"):
 * full column width, never inside a card. $notice: title, text, actions
 * ([label, url, style button|link]).
 */
function render_done_notice($notice) {
  $warn_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12 2 1 21h22L12 2zm0 4.2L19.5 19h-15L12 6.2zM11 10v5h2v-5h-2zm0 6v2h2v-2h-2z"/></svg>';
  ?>
  <div class="tgbl-notice is-<?php echo esc_attr($notice['tone'] ?? 'warning'); ?>" role="note">
    <span class="tgbl-notice__icon"><?php echo $warn_svg; ?></span>
    <div class="tgbl-notice__body">
      <?php if (!empty($notice['title'])) : ?><p class="tgbl-notice__title"><?php echo esc_html($notice['title']); ?></p><?php endif; ?>
      <?php if (!empty($notice['text'])) : ?><p class="tgbl-notice__text"><?php echo esc_html($notice['text']); ?></p><?php endif; ?>
      <?php if (!empty($notice['actions'])) : ?>
        <p class="tgbl-notice__actions">
          <?php foreach ($notice['actions'] as $a) :
            $is_button = ($a['style'] ?? 'link') === 'button'; ?>
            <a class="<?php echo $is_button ? 'tgbl-notice__button' : 'tgbl-notice__link'; ?>"
               href="<?php echo esc_url($a['url'] ?? '#'); ?>" target="_blank" rel="noopener"><?php echo esc_html($a['label'] ?? ''); ?></a>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
    </div>
  </div>
  <?php
}

