<?php
namespace tests\framework;

use tangible\design_settings;

/**
 * Design settings — export, import, reset.
 *
 * The property under test is that an import can only write what the
 * Customizer itself would accept: values are matched to registered
 * Customizer settings and run through their own sanitize/validate, and
 * everything else is skipped and reported, never written.
 */
class Design_Settings_TestCase extends \WP_UnitTestCase {

  static $plugin;
  static $other;

  static function set_up_before_class() {
    parent::set_up_before_class();

    self::$plugin = (object) [ 'name' => 'test-design', 'title' => 'Test Design', 'version' => '2.0.0' ];
    self::$other  = (object) [ 'name' => 'test-other', 'title' => 'Test Other', 'version' => '1.0.0' ];

    add_action('customize_register', function ($wp_customize) {
      $wp_customize->add_setting('test_design[color]', [ 'type' => 'option', 'sanitize_callback' => 'sanitize_hex_color' ]);
      $wp_customize->add_setting('test_design[size]', [ 'type' => 'option', 'sanitize_callback' => 'absint' ]);
      $wp_customize->add_setting('test_design[renamed]', [ 'type' => 'option', 'sanitize_callback' => 'sanitize_text_field' ]);
      $wp_customize->add_setting('test_legacy_bg', [ 'type' => 'theme_mod', 'sanitize_callback' => 'sanitize_hex_color' ]);
      $wp_customize->add_setting('test_other[on]', [ 'type' => 'option', 'sanitize_callback' => 'sanitize_text_field' ]);
    });
  }

  function set_up() {
    parent::set_up();
    \tangible\framework::$state->design_settings = [];
    design_settings\register(self::$plugin, [
      'options'    => [ 'test_design' ],
      'theme_mods' => [ 'test_legacy_' ],
      'panel'      => 'test_panel',
      'migrate'    => function ($section, $from) {
        // 1.x stored "old" where 2.x stores "renamed"
        if (version_compare($from, '2.0.0', '<') && isset($section['options']['test_design']['old'])) {
          $section['options']['test_design']['renamed'] = $section['options']['test_design']['old'];
          unset($section['options']['test_design']['old']);
        }
        return $section;
      },
    ]);
    design_settings\register(self::$other, [ 'options' => [ 'test_other' ] ]);
    design_settings\customizer(true);
  }

  function tear_down() {
    delete_option('test_design');
    delete_option('test_other');
    delete_option('test_design_license_key');
    remove_theme_mod('test_legacy_bg');
    remove_theme_mod('unrelated_mod');
    parent::tear_down();
  }

  // ── export ───────────────────────────────────────────────────────

  function test_export_carries_registered_design_and_nothing_else() {
    update_option('test_design', [ 'color' => '#112233' ]);
    update_option('test_design_license_key', 'SECRET');
    set_theme_mod('test_legacy_bg', '#ffffff');
    set_theme_mod('unrelated_mod', 'x');

    $export = design_settings\export([ 'test-design' ]);

    $this->assertSame(design_settings\FORMAT, $export['format']);
    $this->assertSame([ 'test-design' ], array_keys($export['plugins']));
    $section = $export['plugins']['test-design'];
    $this->assertSame('2.0.0', $section['version']);
    $this->assertSame([ 'test_design' => [ 'color' => '#112233' ] ], $section['options']);
    $this->assertSame([ 'test_legacy_bg' => '#ffffff' ], $section['theme_mods']);
    $this->assertStringNotContainsString('SECRET', wp_json_encode($export));
  }

  function test_empty_sections_export_as_maps() {
    $json = wp_json_encode(design_settings\export([ 'test-other' ]));
    $this->assertStringContainsString('"options":{}', $json);
    $this->assertStringContainsString('"theme_mods":{}', $json);
  }

  function test_export_of_everything_includes_every_registered_plugin() {
    $this->assertSame([ 'test-design', 'test-other' ], array_keys(design_settings\export()['plugins']));
  }

  // ── parse ────────────────────────────────────────────────────────

  function test_parse_rejects_other_files_and_newer_formats() {
    $this->assertWPError(design_settings\parse('{"hello":"world"}'));
    $this->assertWPError(design_settings\parse('not json'));
    $this->assertWPError(design_settings\parse(wp_json_encode([ 'format' => design_settings\FORMAT, 'version' => design_settings\VERSION + 1, 'plugins' => [] ])));
    $this->assertIsArray(design_settings\parse(wp_json_encode(design_settings\export())));
  }

  // ── plan ─────────────────────────────────────────────────────────

  function payload($plugins) {
    return [ 'format' => design_settings\FORMAT, 'version' => 1, 'plugins' => $plugins ];
  }

  function test_values_go_through_each_settings_own_sanitizer() {
    $plan = design_settings\plan($this->payload([
      'test-design' => [ 'version' => '2.0.0', 'options' => [ 'test_design' => [
        'color' => '#abcdef',
        'size'  => '12px',               // absint → 12
      ] ] ],
    ]));
    $this->assertSame('ready', $plan['test-design']['status']);
    $this->assertSame([ 'color' => '#abcdef', 'size' => 12 ], $plan['test-design']['values']['options']['test_design']);
    $this->assertSame(2, $plan['test-design']['applied']);
  }

  function test_values_the_customizer_would_refuse_are_skipped_not_written() {
    $plan = design_settings\plan($this->payload([
      'test-design' => [ 'version' => '2.0.0',
        'options'    => [
          'test_design'             => [ 'color' => 'red;}body{display:none', 'nope' => 'x' ],
          'test_design_license_key' => 'STOLEN',
        ],
        'theme_mods' => [ 'test_legacy_bg' => '#000000', 'unrelated_mod' => 'x' ],
      ],
    ]));
    $step = $plan['test-design'];
    $this->assertSame([], $step['values']['options']['test_design']);
    $this->assertArrayNotHasKey('test_design_license_key', $step['values']['options']);
    $this->assertSame([ 'test_legacy_bg' => '#000000' ], $step['values']['theme_mods']);
    $this->assertEqualsCanonicalizing(
      [ 'test_design[color]', 'test_design[nope]', 'test_design_license_key', 'unrelated_mod' ],
      $step['skipped']
    );
  }

  function test_plugins_not_on_this_site_are_reported_not_applied() {
    $plan = design_settings\plan($this->payload([ 'somewhere-else' => [ 'title' => 'Elsewhere', 'options' => [ 'x' => [ 'y' => 1 ] ] ] ]));
    $this->assertSame('missing', $plan['somewhere-else']['status']);
  }

  function test_migrate_sees_the_raw_section_first() {
    $plan = design_settings\plan($this->payload([
      'test-design' => [ 'version' => '1.4.0', 'options' => [ 'test_design' => [ 'old' => 'kept' ] ] ],
    ]));
    $this->assertSame([ 'renamed' => 'kept' ], $plan['test-design']['values']['options']['test_design']);
  }

  // ── registration outside a Customizer request ────────────────────

  function test_registration_callbacks_can_rely_on_the_global() {
    $seen = null;
    add_action('customize_register', function () use (&$seen) {
      global $wp_customize;
      $seen = $wp_customize instanceof \WP_Customize_Manager;
    }, 1);
    $manager = design_settings\customizer(true);
    $this->assertTrue($seen);
    $this->assertNull($GLOBALS['wp_customize'] ?? null, 'global restored afterwards');
    $this->assertNotNull($manager->get_setting('test_design[color]'));
  }

  function test_a_throwing_callback_is_contained_and_reported() {
    add_action('customize_register', function () { throw new \RuntimeException('neighbour broke'); }, 1);
    design_settings\customizer(true);
    $this->assertSame('neighbour broke', design_settings\customizer_error());

    // Our settings registered after it are absent, so values are skipped, never written unsanitized.
    $plan = design_settings\plan($this->payload([ 'test-design' => [ 'version' => '2.0.0', 'options' => [ 'test_design' => [ 'color' => '#abcdef' ] ] ] ]));
    $this->assertSame([], $plan['test-design']['values']['options']['test_design']);
    $this->assertSame([ 'test_design[color]' ], $plan['test-design']['skipped']);
  }

  // ── apply ────────────────────────────────────────────────────────

  function test_apply_replaces_the_chosen_plugins_design_only() {
    update_option('test_design', [ 'color' => '#111111', 'size' => 3 ]);
    update_option('test_other', [ 'on' => 'yes' ]);
    set_theme_mod('test_legacy_bg', '#222222');

    $fired = [];
    add_action('tangible_design_settings_imported', function ($plugin) use (&$fired) { $fired[] = $plugin->name; });

    $plan = design_settings\plan($this->payload([
      'test-design' => [ 'version' => '2.0.0', 'options' => [ 'test_design' => [ 'color' => '#999999' ] ], 'theme_mods' => [] ],
      'test-other'  => [ 'version' => '1.0.0', 'options' => [ 'test_other' => [ 'on' => 'no' ] ] ],
    ]));
    design_settings\apply($plan, [ 'test-design' ]);

    $this->assertSame([ 'color' => '#999999' ], get_option('test_design'), 'replaced, not merged');
    $this->assertFalse(get_theme_mod('test_legacy_bg'), 'owned theme mods replaced too');
    $this->assertSame([ 'on' => 'yes' ], get_option('test_other'), 'unchosen plugin untouched');
    $this->assertSame([ 'test-design' ], $fired);
  }

  // ── reset ────────────────────────────────────────────────────────

  function test_reset_removes_registered_design_and_nothing_else() {
    update_option('test_design', [ 'color' => '#111111' ]);
    update_option('test_design_license_key', 'KEEP');
    set_theme_mod('test_legacy_bg', '#222222');
    set_theme_mod('unrelated_mod', 'keep');

    design_settings\reset('test-design');

    $this->assertFalse(get_option('test_design'));
    $this->assertFalse(get_theme_mod('test_legacy_bg'));
    $this->assertSame('KEEP', get_option('test_design_license_key'));
    $this->assertSame('keep', get_theme_mod('unrelated_mod'));
  }
}
