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
  foreach ($outbox as $consent_key => $entry) {
    if (empty($entry['synced'])) {
      $answers[] = [
        'key'         => $consent_key,
        'answer'      => $entry['answer'],
        'consentText' => $entry['consent_text'],
        'at'          => (int) (($entry['at'] ?? 0) * 1000),   // seconds → ms, the platform clock unit
      ];
    }
  }
  if (!$answers) return;

  $response = wp_remote_post($plugin->activation_url, [
    'timeout'   => 15,
    'sslverify' => false,   // matches the updater's own cloud_endpoint
    'body'      => [
      'edd_action' => 'tangible_sync_consent',
      'license'    => $key,
      'url'        => home_url(),
      'answers'    => wp_json_encode($answers),
    ],
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
 * The steward answer — "who will look after this site?" — lives LOCALLY, as a
 * per-account map (decided 2026-08-28: mirroring account×site pairs on
 * tangible.one is a lot of work for little benefit, and the question is
 * deliberately asked once per site anyway).
 *
 * Keyed by the opaque accountId from the onboarding block, because one site
 * can host plugins licensed under two different accounts — rare, but it costs
 * one array key to be correct about. '' is the key when no account is known
 * yet (pre-activation, free builds). Legacy shape (a bare string from the
 * first cut) reads as the ''-keyed answer.
 */
const STEWARD_OPTION = 'tangible_site_steward';

function get_steward_map() {
  $value = get_option(STEWARD_OPTION, []);
  if (is_string($value) && $value !== '') return [ '' => $value ];   // legacy
  return is_array($value) ? $value : [];
}

function get_steward($account_id = '') {
  $map = get_steward_map();
  // An account-specific answer wins; the anonymous answer covers the rest —
  // whoever set up the first plugin answered for the site as they knew it.
  return $map[$account_id] ?? $map[''] ?? null;
}

function set_steward($account_id, $value) {
  if (!in_array($value, ['team', 'client'], true)) return;
  $map = get_steward_map();
  $map[$account_id] = $value;
  update_option(STEWARD_OPTION, $map, false);
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
  // "Free build" has two spellings: no cloud_id, or no updater module at all —
  // the wp.org-distributed build deliberately ships without the updater (two
  // things claiming one slug), and a licence ask with no activation machinery
  // behind it could only end in an error.
  if (!empty($plugin->cloud_id) && function_exists('tangible\\updater\\get_license_key')) {
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
        return true;
      },
    ];
  }

  // ── Consent: one step, two asks, asked separately, never bundled ─────────
  $ask_telemetry = ($facts->ask['telemetry_extended'] ?? null) === 'ask';
  $ask_marketing = ($facts->ask['marketing'] ?? null) === 'ask';

  $steps[] = [
    'id'     => 'consent',
    'label'  => 'two optional things',
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
  //    identically, once per (site, account) ────────────────────────────────
  $account_id = is_object($facts) ? ($facts->account_id ?? '') : '';
  $account_name = is_object($facts) ? ($facts->account_name ?? '') : '';
  $steps[] = [
    'id'     => 'steward',
    'label'  => 'this site',
    'weight' => 40,
    // 'account' scope = the resolver records nothing; the step's own local
    // map is the whole state, so a NEW account on the same site re-asks.
    'scope'  => 'account',
    'skippable' => false,
    'needed' => function () use ($account_id) { return get_steward($account_id) === null; },
    'skip_note' => 'answered',
    'render' => function () use ($account_id, $account_name) {
      $current = get_steward($account_id);
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
    'handle' => function () use ($account_id) {
      $v = $_POST['steward'] ?? '';
      if (!in_array($v, ['team', 'client'], true)) return false;
      set_steward($account_id, $v);
      return true;
    },
  ];

  return $steps;
}, 5, 3);
