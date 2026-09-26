<?php
/**
 * The shell's own steps — the core archetypes from the design.
 *
 * The artifact's step table names five archetypes; two belong to core and
 * live here, appearing in every Tangible plugin's wizard:
 *
 *   Licence   Identify the account. Always first, never skippable, absent
 *             entirely on free builds (no `cloud_id` = free).
 *   Consent   ONE step, two asks — diagnostics and email — asked separately,
 *             never bundled, and shown only when not already on file.
 *
 * Choice / Credentials / Verified-state are plugin archetypes: whatever a
 * plugin needs there is its business, the shell just supplies the infra
 * (render/handle contract, the WP_Error test path, submit_label).
 *
 * ## The consent step, in the Wordfence shape
 *
 * The competitive teardown found exactly one decent consent flow shipping at
 * 4M+ installs: forced, unbiased, unbundled Yes/No that refuses to submit on
 * a non-answer. Three properties do the work and all three are here — equal
 * size and weight options, nothing preselected, Continue refuses until BOTH
 * are answered (server-enforced; the small script is progressive enhancement).
 * And the payload is SHOWN — the actual facts that would be sent — not a
 * policy link.
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

function record_consent_answer($key, $answer, $consent_text) {
  $outbox = get_option(CONSENT_OUTBOX, []);
  $outbox[$key] = [
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
 * Auth is the plugin's licence key (same trust as activation), endpoint is
 * the same activation_url the updater already talks to, action
 * `tangible_sync_consent`. Server side is idempotent — a redelivered answer
 * that matches resolved state is dropped there — so this client can retry
 * forever without bookkeeping beyond the synced flag.
 *
 * No key yet (consent answered before licence, or a free build) → answers
 * simply wait; every call is a cheap no-op until a key exists. Failure of
 * any kind leaves synced=false and the next trigger retries. Never blocks
 * rendering: one POST, 15s cap, outcome recorded and forgotten.
 */
function attempt_consent_sync($plugin) {
  if (empty($plugin->activation_url) || !function_exists('tangible\\updater\\get_license_key')) return;
  $key = \tangible\updater\get_license_key($plugin);
  if (empty($key)) return;

  $outbox = get_option(CONSENT_OUTBOX, []);
  $answers = [];
  $ops = [];
  foreach ($outbox as $consent_key => $entry) {
    if (empty($entry['synced'])) {
      $answers[] = [
        'key'         => $consent_key,
        'answer'      => $entry['answer'],
        'consentText' => $entry['consent_text'],
        'at'          => (int) (($entry['at'] ?? 0) * 1000),   // seconds → ms, the platform clock unit
      ];
      $ops[] = $entry['op'] ?? ($consent_key . ':' . ($entry['at'] ?? 0));
    }
  }
  if (!$answers) return;

  $response = wp_remote_post($plugin->activation_url, [
    'timeout'   => 15,
    'sslverify' => false,   // matches the updater's own cloud_endpoint
    'body'      => consent_sync_body($plugin, $key, $answers, $ops),
  ]);
  if (is_wp_error($response)) return;
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
 * The request body for one consent delivery. Besides the answers, it carries
 * the site/plugin context the platform records with each consent interaction
 * (tangible-one PR #236), and one source_operation_id for the batch: derived
 * from the answers' own ids, so a retry of the same unsynced batch repeats
 * it and the server records the delivery once.
 */
function consent_sync_body($plugin, $key, $answers, $ops) {
  $body = [
    'edd_action' => 'tangible_sync_consent',
    'license'    => $key,
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
function is_free_distribution($plugin) {
  return $plugin && function_exists('tangible\\updater\\is_free_distribution')
    && \tangible\updater\is_free_distribution($plugin);
}

const TELEMETRY_CONSENT_TEXT =
  'Share anonymous performance and usage data — execution times, which features are used, '
  . 'content counts, a role histogram. Never content, names, or visitor data. (extended telemetry v1)';

const MARKETING_CONSENT_TEXT =
  'Email me release notes and product updates from Tangible. Unsubscribe any time. '
  . '(plugin wizard opt-in v1)';

/**
 * One unbiased answer pair: same size, same weight, nothing preselected.
 * Wears the catalog's option-card DOM (a Yes/No two-card radiogroup) —
 * the Wordfence-shape properties are unchanged, only the skin moved.
 */
function render_answer_pair($field, $question, $detail) {
  $id = esc_attr($field);
  $check_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10.687 16.567 18.14 3.99l1.72 1.02-8.547 14.423-7.382-5.111 1.138-1.644z" clip-rule="evenodd"/></svg>';
  ?>
  <div class="tgbl-answer">
    <p class="tgbl-answer__q"><?php echo esc_html($question); ?></p>
    <p class="tgbl-answer__d"><?php echo esc_html($detail); ?></p>
    <div role="radiogroup" aria-label="<?php echo esc_attr($question); ?>" class="tui-option-card-group">
      <?php foreach ([ 'granted' => 'Yes', 'declined' => 'No' ] as $value => $label) : ?>
        <label class="tui-option-card is-row" data-tgbl-option>
          <input class="tui-option-card__input tui-visually-hidden" type="radio"
                 name="<?php echo $id; ?>" value="<?php echo esc_attr($value); ?>" data-tgbl-consent />
          <span class="tui-option-card__control" aria-hidden="true"><span class="tui-icon"><?php echo $check_svg; ?></span></span>
          <span class="tui-option-card__body">
            <span class="tui-option-card__heading">
              <span class="tui-option-card__title"><?php echo esc_html($label); ?></span>
            </span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
}

add_filter('tangible_onboarding_steps', function ($steps, $facts, $plugin_name = '') {

  // Core steps belong to wizards, not to every resolve_plan() call.
  if (empty(registered_wizards()[$plugin_name])) return $steps;

  $plugin = function_exists('tangible\\framework\\get_plugin')
    ? framework\get_plugin($plugin_name) : null;

  // ── Licence: always first, never skippable, absent on free builds ────────
  // A licence ask with no activation machinery behind it could only end in an
  // error, and "no machinery" has three spellings:
  //
  //   no cloud_id            — a free build, nothing to activate
  //   no updater functions   — the wp.org build deliberately ships without the
  //                            updater (two things claiming one slug)
  //   no activation_url      — THIS plugin never registered with the updater,
  //                            even though another plugin on the site loaded
  //                            its functions. activation_url is only defaulted
  //                            inside the updater's own register_plugin(), so
  //                            without it the handler posts the key to null
  //                            and takes the whole request down with it.
  //
  // function_exists() answers a site-wide question; activation_url answers the
  // per-plugin one, which is the question that actually matters here. Same
  // predicate attempt_consent_sync() uses above, for the same reason.
  if (!empty($plugin->cloud_id) && !empty($plugin->activation_url)
      && function_exists('tangible\\updater\\get_license_key')
      && !is_free_distribution($plugin)) {
    $steps[] = [
      'id'     => 'licence',
      'label'  => 'licence',
      'weight' => 10,
      'scope'  => 'plugin',
      'skippable' => false,
      'needed' => function ($f) { return empty($f->licence_active); },
      'skip_note' => 'active',
      'render' => function ($plugin) {
        $existing = function_exists('tangible\\updater\\get_license_key')
          ? \tangible\updater\get_license_key($plugin) : '';
        ?>
        <h2>Your licence key</h2>
        <p class="step-intro">From your <a href="https://tangible.one/licensing" target="_blank" rel="noopener">tangible.one account</a>.
           Identifies your account and unlocks updates for this site.</p>
        <p><input type="text" name="license_key" class="code"
                  value="<?php echo esc_attr($existing); ?>" placeholder="TGBL-…" /></p>
        <?php
      },
      // A REAL activation, through the updater's own machinery — no second
      // activation path exists. The outcome rules, so no state leaks water:
      //
      //   key rejected (invalid/expired/revoked/no seats/site inactive)
      //     → WP_Error with the updater's own message; the step stays open.
      //       A wrong key fails HERE, not at the first missed update.
      //   our API unreachable (timeout, DNS, firewall)
      //     → the key is SAVED, status set to the real error-free unknown,
      //       and the step completes with a deferred note. OUR outage never
      //       blocks THEIR setup; the updater's cron checker retries and the
      //       plugins-row notice reappears if the key turns out bad.
      //   success
      //     → status stored where settings page, plugins row and hub all
      //       read it, so no surface can disagree. If the response carries
      //       an `onboarding` block (steward, consent decisions — the
      //       platform's resolve endpoint, when it ships), it is cached as
      //       facts for the rest of this wizard.
      'handle' => function ($plugin) {
        $key = sanitize_text_field($_POST['license_key'] ?? '');
        if ($key === '') return false;
        if (!function_exists('tangible\\updater\\cloud_endpoint')) {
          return new \WP_Error('tangible_onboarding', 'The updater module is not available on this site.');
        }

        // A different key can mean a different ACCOUNT: stale decisions from
        // the old account must never govern the new one's wizard, so the
        // facts cache dies with the old key. The activation response
        // repopulates it.
        delete_option('tangible_onboarding_facts_cache__' . $plugin->name);

        // Save first — the exact subfield the update checker reads. Even on
        // the deferred path, updates must query with this key.
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
          $message = $code === 403
            ? ($body->error ?? 'License validation failed.')
            : \tangible\updater\check_license_response($body, $plugin);
          return new \WP_Error('tangible_onboarding', is_string($message) ? $message : 'License validation failed.');
        }

        \tangible\updater\set_license_status($plugin, $body->license ?? 'valid');

        // The facts seam: when the platform's activate response starts
        // carrying onboarding decisions, they land here and build_facts
        // reads them — nothing else changes.
        if (!empty($body->onboarding)) {
          // Deep-convert: a (array) cast is shallow and leaves nested
          // stdClass objects, which the reader's is_array guard then
          // rejects — the bug where a perfectly good skip/skip block was
          // silently ignored and the wizard re-asked answered questions.
          $data = json_decode(wp_json_encode($body->onboarding), true);
          update_option('tangible_onboarding_facts_cache__' . $plugin->name,
            [ 'at' => time(), 'data' => is_array($data) ? $data : [] ], false);
        }
        // Answers given before the key existed (consent asked first, or a
        // free build that became licensed) can be delivered now.
        attempt_consent_sync($plugin);
        return true;
      },
    ];
  }

  // ── Consent: one step, two asks, asked separately, never bundled ─────────
  $ask_telemetry = ($facts->ask['telemetry_extended'] ?? null) === 'ask';
  $ask_marketing = ($facts->ask['marketing'] ?? null) === 'ask';

  $steps[] = [
    'id'     => 'consent',
    // The rail is a table of contents: short enough that eleven of them fit a
    // row. The step's own heading still says "Two optional things".
    'label'  => 'optional',
    'weight' => 20,
    'scope'  => 'account',
    'skippable' => false,   // "no" is a complete answer, which is why there is no skip
    'needed' => function () use ($ask_telemetry, $ask_marketing) {
      return $ask_telemetry || $ask_marketing;
    },
    'skip_note' => 'on file',
    'render' => function ($plugin) use ($ask_telemetry, $ask_marketing) {
      // The tier boundary, kept visibly straight: on a paid build, BASIC
      // telemetry (WP/PHP/plugin versions, site URL) is already held as a
      // term of purchase — checkout was the portal for it. This step asks
      // for EXTENDED — usage data — so the payload shown is the extended
      // payload: real values from this site, none of them version basics.
      $roles = function_exists('wp_roles') ? count(wp_roles()->roles) : 0;
      $posts = wp_count_posts('post'); $pages = wp_count_posts('page');
      $payload = [
        'published items' => number_format_i18n((int) ($posts->publish ?? 0) + (int) ($pages->publish ?? 0)),
        'user roles'      => number_format_i18n($roles),
        'active plugins'  => number_format_i18n(count((array) get_option('active_plugins', []))),
        'locale'          => get_locale(),
      ];
      $paid = !empty($plugin->cloud_id);
      ?>
      <h2>Two optional things</h2>
      <p class="step-intro"><strong>No</strong> is a complete answer to both — the plugin works
         exactly the same either way. We ask rather than assume, so you do have to answer.</p>
      <div class="tgbl-dbl"></div>

      <?php if ($ask_telemetry) {
        render_answer_pair('telemetry_extended',
          'Share performance and usage data?',
          'Execution times and performance timings, which features you use, content counts, a role histogram. Never your content, your users, or your visitors.');
        // Show the payload, not a policy link — this site's actual numbers.
        ?>
        <table style="margin:10px 0 0; border-collapse:collapse">
          <?php foreach ($payload as $k => $v) : ?>
            <tr>
              <td class="lbl tgbl-factkey"><?php echo esc_html($k); ?></td>
              <td class="code" style="padding:2px 0"><?php echo esc_html($v); ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php if ($paid) : ?>
          <p class="whisper">Version and environment basics (WordPress, PHP,
             plugin version) are already shared under your licence terms — this question is about
             the rest.</p>
        <?php endif;
      } ?>

      <?php if ($ask_marketing) {
        render_answer_pair('marketing',
          'Release notes by email?',
          'What shipped and what it means for your site. No drip campaigns, unsubscribe any time.');
      } ?>

      <script>
      /* Progressive enhancement only — the handler refuses a non-answer anyway. */
      (function () {
        var form = document.currentScript.closest('form'); if (!form) return;
        var go = form.querySelector('[name="do"][value="continue"]'); if (!go) return;
        var groups = {};
        form.querySelectorAll('[data-tgbl-consent]').forEach(function (r) { groups[r.name] = true; });
        var names = Object.keys(groups); if (!names.length) return;
        function sync() {
          go.disabled = !names.every(function (n) { return form.querySelector('[name="' + n + '"]:checked'); });
        }
        form.addEventListener('change', sync); sync();
      })();
      </script>
      <?php
    },
    'handle' => function ($plugin) use ($ask_telemetry, $ask_marketing) {
      $answers = [];
      foreach ([
        'telemetry_extended' => [$ask_telemetry, TELEMETRY_CONSENT_TEXT],
        'marketing'          => [$ask_marketing, MARKETING_CONSENT_TEXT],
      ] as $key => [$asked, $text]) {
        if (!$asked) continue;
        $answer = $_POST[$key] ?? '';
        if (!in_array($answer, ['granted', 'declined'], true)) return false;  // both or stay open
        $answers[$key] = [$answer, $text];
      }
      // Record only once every asked question has an answer — half-consent
      // recorded is worse than none.
      foreach ($answers as $key => [$answer, $text]) {
        record_consent_answer($key, $answer, $text);
      }
      // Deliver immediately when a key exists; otherwise the outbox waits for
      // the next trigger (setup-page load, licence activation).
      if ($plugin) attempt_consent_sync($plugin);
      return true;
    },
  ];

  // ── Steward: site question, shell-owned — every Tangible plugin asks it
  //    identically, once per site ────────────────────────────────────────────
  $account_name = is_object($facts) ? ($facts->account_name ?? '') : '';
  $steps[] = [
    'id'     => 'steward',
    'label'  => 'this site',
    'weight' => 40,
    // The site option IS the state: 'site' scope keeps the resolver's record
    // shared too, so a second plugin on the site sees it answered.
    'scope'  => 'site',
    'skippable' => false,
    'needed' => function () { return get_steward() === null; },
    'skip_note' => 'answered',
    'render' => function () use ($account_name) {
      $current = get_steward();
      $who = $account_name !== '' ? $account_name : 'your team';
      ?>
      <h2>Who will look after this site?</h2>
      <p class="step-intro">This decides who we talk to about licences and renewals — here,
         or the account owner by email. You can change it later.</p>
      <div class="tgbl-dbl"></div>
      <?php
      render_option_group('Who will look after this site?', function () use ($current, $account_name, $who) {
        render_option_card([
          'name' => 'steward', 'value' => 'team', 'variant' => 'row',
          'checked' => $current !== 'client',
          'title' => 'Me or my team',
          'description' => 'Licence, renewals and account details show here, where you will actually see them.',
        ]);
        render_option_card([
          'name' => 'steward', 'value' => 'client', 'variant' => 'row',
          'checked' => $current === 'client',
          'title' => 'A client' . ($account_name !== '' ? ' — ' . $account_name . ' manages this site for them' : ''),
          'description' => 'Billing, renewals and offers stay out of this admin — ' . $who . ' hears about them by email instead.',
        ]);
      });
      ?>
      <?php
    },
    'handle' => function () {
      $v = $_POST['steward'] ?? '';
      if (!in_array($v, ['team', 'client'], true)) return false;
      set_steward($v);
      return true;
    },
    // Back reopens the question: the option is the answer, so clear it.
    'on_back' => function () { delete_option(STEWARD_OPTION); },
  ];

  return $steps;
}, 5, 3);

/**
 * Done — the last screen, a core step for every wizard (spec §3). Registered
 * late so a plugin that ships its own `done` step keeps it (SearchSync does).
 *
 * A plugin shapes it through `tangible_onboarding_done`:
 *
 *   add_filter('tangible_onboarding_done', function ($done, $plugin) {
 *     if ($plugin->name !== 'my-plugin') return $done;
 *     $done['summary'] = 'Ready — no merges run yet.';
 *     $done['primary'] = [ 'label' => 'Open Merge User Accounts', 'url' => admin_url('tools.php?page=…') ];
 *     $done['links'][] = [ 'label' => 'How merges work', 'url' => 'https://…', 'kind' => 'Article' ];
 *     return $done;
 *   }, 10, 2);
 */
add_filter('tangible_onboarding_steps', function ($steps, $facts, $plugin_name = '') {
  if (empty(registered_wizards()[$plugin_name])) return $steps;
  foreach ($steps as $s) if (($s['id'] ?? null) === 'done') return $steps;

  $plugin = function_exists('tangible\\framework\\get_plugin')
    ? framework\get_plugin($plugin_name) : null;
  $title = $plugin->title ?? $plugin_name;
  $done = apply_filters('tangible_onboarding_done', [
    'summary' => $title . ' is set up.',
    'primary' => [ 'label' => 'Go to Tangible Home', 'url' => admin_url('admin.php?page=tangible-home') ],
    'links'   => [],
  ], $plugin);

  $steps[] = [
    'id'     => 'done',
    'label'  => 'done',
    'weight' => 1000,
    // Terminal: never a reason on its own to redirect, notify, or count as
    // "steps left" — it only closes a wizard that asked something.
    'terminal' => true,
    'scope'  => 'plugin',
    'skippable' => false,
    'submit_label' => $done['primary']['label'] ?? 'Finish',
    'redirect_to'  => $done['primary']['url'] ?? admin_url('admin.php?page=tangible-home'),
    'render' => function () use ($done, $title) {
      ?>
      <h2><?php echo esc_html($title); ?> is ready</h2>
      <p class="step-intro"><?php echo esc_html($done['summary'] ?? ''); ?></p>
      <?php if (!empty($done['links'])) : ?>
        <div class="tgbl-dbl"></div>
        <p class="lbl">What people usually do next</p>
        <ul class="tgbl-next">
          <?php foreach ($done['links'] as $link) : ?>
            <li><a href="<?php echo esc_url($link['url'] ?? '#'); ?>" target="_blank" rel="noopener"><?php
              echo esc_html($link['label'] ?? ''); ?></a><?php
              if (!empty($link['kind'])) echo ' <span class="whisper">' . esc_html($link['kind']) . '</span>'; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif;
    },
    'handle' => '__return_true',
  ];
  return $steps;
}, 100, 3);
