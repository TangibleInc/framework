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

const TELEMETRY_CONSENT_TEXT =
  'Share anonymous usage data — which features are used, content counts, a role histogram, '
  . 'and environment performance. Never content, names, or visitor data. (extended telemetry v1)';

const MARKETING_CONSENT_TEXT =
  'Email me release notes and product updates from Tangible. Unsubscribe any time. '
  . '(plugin wizard opt-in v1)';

/** One unbiased answer pair: same size, same weight, nothing preselected. */
function render_answer_pair($field, $question, $detail) {
  $id = esc_attr($field);
  ?>
  <div style="border:1px solid #c3c4c7; border-radius:4px; padding:14px 16px; margin-top:12px">
    <p style="margin:0 0 3px; font-size:13.5px; font-weight:600"><?php echo esc_html($question); ?></p>
    <p style="margin:0 0 10px; font-size:12.5px; color:#646970"><?php echo esc_html($detail); ?></p>
    <div style="display:flex; gap:10px">
      <label style="flex:1 1 0; border:1px solid #c3c4c7; border-radius:2px; padding:9px 13px; cursor:pointer; text-align:center">
        <input type="radio" name="<?php echo $id; ?>" value="granted" data-tgbl-consent /> Yes
      </label>
      <label style="flex:1 1 0; border:1px solid #c3c4c7; border-radius:2px; padding:9px 13px; cursor:pointer; text-align:center">
        <input type="radio" name="<?php echo $id; ?>" value="declined" data-tgbl-consent /> No
      </label>
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
  if (!empty($plugin->cloud_id)) {
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
        <p>From your <a href="https://tangible.one/licensing" target="_blank" rel="noopener">tangible.one account</a>.
           Identifies your account and unlocks updates for this site.</p>
        <p><input type="text" name="license_key" class="regular-text code"
                  value="<?php echo esc_attr($existing); ?>" placeholder="TGBL-…" /></p>
        <?php
      },
      // Write-through-updater: the key lands in the exact settings subfield
      // the update checker reads. A wizard-private key store would show
      // "activated" while updates silently query keyless.
      'handle' => function ($plugin) {
        $key = sanitize_text_field($_POST['license_key'] ?? '');
        if ($key === '') return false;
        if (!function_exists('tangible\\updater\\get_license_key_setting_field')) {
          return new \WP_Error('tangible_onboarding', 'The updater module is not available on this site.');
        }
        framework\update_plugin_settings($plugin, [
          \tangible\updater\get_license_key_setting_field() => $key,
        ]);
        // Remote activation rides the updater's own next check.
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
      global $wp_version;
      $payload = [
        'site'      => wp_parse_url(home_url(), PHP_URL_HOST),
        'wordpress' => $wp_version,
        'php'       => PHP_VERSION,
        ($plugin->name ?? 'plugin') => $plugin->version ?? '',
      ];
      ?>
      <h2>Two optional things</h2>
      <p><strong>No</strong> is a complete answer to both — the plugin works exactly the same
         either way. We ask rather than assume, so you do have to answer.</p>

      <?php if ($ask_telemetry) {
        render_answer_pair('telemetry_extended',
          'Send us usage data?',
          'Which features you use, content counts, a role histogram, environment performance. Never your content, your users, or your visitors.');
        // Show the payload, not a policy link — the facts themselves.
        ?>
        <table style="margin:10px 0 0; border-collapse:collapse; font-size:12px">
          <?php foreach ($payload as $k => $v) : ?>
            <tr>
              <td class="lbl" style="padding:2px 14px 2px 0; font-size:9.5px"><?php echo esc_html($k); ?></td>
              <td class="code" style="padding:2px 0"><?php echo esc_html($v); ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php } ?>

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
    'handle' => function () use ($ask_telemetry, $ask_marketing) {
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
      return true;
    },
  ];

  return $steps;
}, 5, 3);
