<?php
namespace tests\framework;

use tangible\onboarding;

/**
 * Welcome's media slot, and the wizard's strings in the tangible-framework
 * domain — what is shown is translatable, what is recorded stays provable.
 */
class Welcome_I18n_TestCase extends \WP_UnitTestCase {

  function tearDown(): void {
    remove_all_filters('gettext_tangible-framework');
    parent::tearDown();
  }

  function test_media_fills_every_key() {
    $media = onboarding\normalize_media([ 'image' => 'https://example.com/a.png', 'href' => 'https://example.com/post' ]);
    $this->assertSame('', $media['alt']);
    $this->assertSame('', $media['label']);
    $this->assertSame('post', $media['kind']);
  }

  function test_a_bare_video_url_is_media_without_an_image() {
    $media = onboarding\normalize_media(null, 'https://example.com/tour');
    $this->assertSame('https://example.com/tour', $media['href']);
    $this->assertSame('video', $media['kind']);
    $this->assertSame('', $media['image']);
  }

  function test_media_with_neither_image_nor_link_is_nothing() {
    $this->assertNull(onboarding\normalize_media([ 'label' => 'Orphan' ]));
    $this->assertNull(onboarding\normalize_media(null));
  }

  function test_an_unknown_kind_is_a_post() {
    $this->assertSame('post', onboarding\normalize_media([ 'image' => 'x.png', 'kind' => 'podcast' ])['kind']);
  }

  function test_media_label_names_the_kind_when_unset() {
    $this->assertSame('Watch the video', onboarding\media_label(onboarding\normalize_media(null, 'https://example.com/v')));
    $this->assertSame('Read more', onboarding\media_label(onboarding\normalize_media([ 'href' => 'https://example.com/p' ])));
  }

  function test_english_consent_wording_is_unchanged_by_translation() {
    $rows = [ 'Theme' => 'x', 'Page speed' => 'y' ];
    $this->assertSame(
      'Share usage data from this site? Exactly what would be sent: Theme, Page speed. '
        . onboarding\USAGE_NOTE . ' (telemetry v2, site answer, wizard v3)',
      onboarding\telemetry_consent_text($rows)
    );
    $this->assertSame(onboarding\USAGE_NOTE, onboarding\usage_note());
    $this->assertSame(onboarding\TERMS_USAGE_DISCLOSURE, onboarding\terms_usage_disclosure());
  }

  function test_consent_records_the_wording_shown_in_the_sites_language() {
    add_filter('gettext_tangible-framework', function ($translation, $text) {
      return $text === 'Theme' ? 'Thema' : $translation;
    }, 10, 2);
    $this->assertSame('Thema', onboarding\usage_row_label('Theme'));
    $this->assertStringContainsString(': Thema.', onboarding\telemetry_consent_text([ 'Theme' => 'x' ]));
    // The version tag stays English, so the record is identifiable in any language.
    $this->assertStringEndsWith('(telemetry v2, site answer, wizard v3)', onboarding\telemetry_consent_text([ 'Theme' => 'x' ]));
  }

  function test_a_plugins_own_usage_row_keeps_its_label() {
    $this->assertSame('Indexed records', onboarding\usage_row_label('Indexed records'));
  }
}
