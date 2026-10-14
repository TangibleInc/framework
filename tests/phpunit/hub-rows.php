<?php
namespace tests\framework;

use tangible\hub;

/**
 * Hub rows — what each plugin row says, decided from facts alone
 * (hub\row_state is pure; hub\gather_facts is the only reader).
 *
 * The rules under test: Connect is for keyless FREE plugins only (a key
 * always wins); a plugin whose distribution is not known yet is never nagged;
 * usage data is the site's, not a row's (ADR-011); setup outranks every other
 * action; the Connect band follows the site, not a plugin.
 */
class Hub_Rows_TestCase extends \WP_UnitTestCase {

  private function paid($overrides = []) {
    return $overrides + [ 'licence_managed' => true, 'cloud' => true, 'free' => false ];
  }

  private function free($overrides = []) {
    return $overrides + [ 'licence_managed' => true, 'cloud' => true, 'free' => true ];
  }

  function test_paid_without_a_key_asks_for_it_and_never_offers_connect() {
    $s = hub\row_state($this->paid());
    $this->assertSame('License', $s['account']['key']);
    $this->assertSame(['need', 'Key not entered', 'enter_key'],
      [ $s['account']['chip'], $s['account']['text'], $s['account']['action'] ]);
    $this->assertSame('enter_key', $s['primary']);
  }

  function test_rows_carry_no_usage_data_the_site_does() {
    foreach ([ $this->paid(), $this->free(), $this->free([ 'connect' => 'active' ]) ] as $facts) {
      $this->assertArrayNotHasKey('usage', hub\row_state($facts));
    }
  }

  function test_site_usage_data_state() {
    $this->assertSame('refused', \tangible\onboarding\site_telemetry_state('declined', true), 'a no beats the Terms');
    $this->assertSame('granted', \tangible\onboarding\site_telemetry_state('granted', false));
    $this->assertSame('terms', \tangible\onboarding\site_telemetry_state(null, true), 'keyed or connected: never asked');
    $this->assertSame('none', \tangible\onboarding\site_telemetry_state(null, false), 'anonymous and unasked sends nothing');
  }

  function test_paid_licence_states() {
    $active = hub\row_state($this->paid([ 'has_key' => true, 'licence_status' => 'valid', 'account_name' => 'Acme Agency' ]));
    $this->assertSame(['ok', 'Active', 'Acme Agency'], [ $active['account']['chip'], $active['account']['text'], $active['account']['sub'] ]);
    $this->assertSame('open', $active['primary']);

    $expired = hub\row_state($this->paid([ 'has_key' => true, 'licence_status' => 'expired' ]));
    $this->assertSame(['bad', 'Expired', 'renew'], [ $expired['account']['chip'], $expired['account']['text'], $expired['primary'] ]);

    $invalid = hub\row_state($this->paid([ 'has_key' => true, 'licence_status' => 'invalid' ]));
    $this->assertSame('Key not valid', $invalid['account']['text']);

    $inactive = hub\row_state($this->paid([ 'has_key' => true, 'licence_status' => 'site_inactive' ]));
    $this->assertSame('Not activated', $inactive['account']['text']);
  }

  function test_free_unconnected_offers_connect() {
    $s = hub\row_state($this->free());
    $this->assertSame('Account', $s['account']['key']);
    $this->assertSame(['free', 'Free · not connected', 'connect'],
      [ $s['account']['chip'], $s['account']['text'], $s['account']['action'] ]);
  }

  function test_a_build_without_the_updater_is_a_free_row() {
    // A wordpress.org build: on the platform (cloud_id), never registered with the updater.
    $plugin = \tangible\framework\register_plugin([ 'name' => 'hubwporg', 'title' => 'Hub WP.org', 'cloud_id' => 'hubwporg' ]);
    $f = hub\gather_facts($plugin);
    $this->assertTrue($f['free']);
    $s = hub\row_state($f);
    $this->assertSame(['Account', 'free', 'connect'], [ $s['account']['key'], $s['account']['chip'], $s['account']['action'] ]);

    // A legacy build (no cloud_id, no wizard) is not declared free.
    $legacy = \tangible\framework\register_plugin([ 'name' => 'hublegacy', 'title' => 'Hub Legacy' ]);
    $this->assertNotTrue(hub\gather_facts($legacy)['free']);
  }

  function test_free_connected_names_the_account() {
    $s = hub\row_state($this->free([ 'connect' => 'active', 'connect_account' => 'Oakhill' ]));
    $this->assertSame(['ok', 'Connected', 'Oakhill'], [ $s['account']['chip'], $s['account']['text'], $s['account']['sub'] ]);
  }

  function test_a_key_wins_over_a_free_build() {
    $s = hub\row_state($this->free([ 'has_key' => true, 'licence_status' => 'active' ]));
    $this->assertSame('License', $s['account']['key']);
    $this->assertSame('Active', $s['account']['text']);
  }

  function test_unknown_distribution_is_not_nagged() {
    $s = hub\row_state([ 'licence_managed' => true, 'cloud' => true, 'free' => null ]);
    $this->assertSame(['off', 'Checking', null], [ $s['account']['chip'], $s['account']['text'], $s['account']['action'] ]);
    $this->assertSame('open', $s['primary']);

    // A legacy build that never talks to tangible.one will never answer.
    $legacy = hub\row_state([ 'licence_managed' => true, 'cloud' => false, 'free' => null ]);
    $this->assertSame('No license info', $legacy['account']['text']);
  }

  function test_setup_left_outranks_every_other_action() {
    $s = hub\row_state($this->paid([ 'has_wizard' => true, 'steps_left' => 2, 'steps_total' => 3, 'update' => '1.1' ]));
    $this->assertSame(['done' => false, 'left' => 2, 'pct' => 33], $s['setup']);
    $this->assertSame('resume', $s['primary']);

    $done = hub\row_state($this->paid([ 'has_key' => true, 'licence_status' => 'active', 'has_wizard' => true, 'update' => '1.1' ]));
    $this->assertTrue($done['setup']['done']);
    $this->assertSame('update', $done['primary']);

    $this->assertNull(hub\row_state($this->paid())['setup'], 'no wizard, no setup column');
  }

  function test_connect_band_follows_the_site() {
    $now = 1000;
    $this->assertNull(hub\band_state('active', true, 0, $now));
    $this->assertNull(hub\band_state('none', true, $now + 1, $now), 'Not now hides it');
    $this->assertSame([ 'status' => 'none', 'has_free' => false ], hub\band_state('none', false, 0, $now),
      'shown with no free plugin installed too');
    $this->assertSame('pending', hub\band_state('pending', true, $now + 1, $now)['status'],
      'a half-finished connection is not snoozable');
  }
}
