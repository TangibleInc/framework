<?php
namespace tests\framework;

use tangible\hub;

/**
 * Hub rows — what each plugin row says, decided from facts alone
 * (hub\row_state is pure; hub\gather_facts is the only reader).
 *
 * The rules under test: Connect is for keyless FREE plugins only (a key
 * always wins); a plugin whose distribution is not known yet is never nagged;
 * usage data never claims more than the site can prove; setup outranks every
 * other action; the Connect band follows the site, not a plugin.
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
    $this->assertSame('Licence', $s['account']['key']);
    $this->assertSame(['need', 'Key not entered', 'enter_key'],
      [ $s['account']['chip'], $s['account']['text'], $s['account']['action'] ]);
    $this->assertSame('enter_key', $s['primary']);
    $this->assertSame('Asked after the key is activated', $s['usage']['sub']);
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

  function test_free_unconnected_offers_connect_and_shows_the_sites_own_answer() {
    $s = hub\row_state($this->free([ 'telemetry_local' => 'declined' ]));
    $this->assertSame('Account', $s['account']['key']);
    $this->assertSame(['free', 'Free · not connected', 'connect'],
      [ $s['account']['chip'], $s['account']['text'], $s['account']['action'] ]);
    $this->assertSame('Off · this site', $s['usage']['text']);
  }

  function test_free_connected_defers_usage_to_the_account() {
    $s = hub\row_state($this->free([ 'connect' => 'active', 'connect_account' => 'Oakhill', 'telemetry_on_file' => true ]));
    $this->assertSame(['ok', 'Connected', 'Oakhill'], [ $s['account']['chip'], $s['account']['text'], $s['account']['sub'] ]);
    $this->assertSame('On file · account setting', $s['usage']['text']);

    // An answer given here and not yet delivered says so rather than "on file".
    $pending = hub\row_state($this->free([ 'connect' => 'active', 'telemetry_local' => 'granted', 'telemetry_synced' => false ]));
    $this->assertSame('On · sync pending', $pending['usage']['text']);
  }

  function test_a_key_wins_over_a_free_build() {
    $s = hub\row_state($this->free([ 'has_key' => true, 'licence_status' => 'active' ]));
    $this->assertSame('Licence', $s['account']['key']);
    $this->assertSame('Active', $s['account']['text']);
  }

  function test_unknown_distribution_is_not_nagged() {
    $s = hub\row_state([ 'licence_managed' => true, 'cloud' => true, 'free' => null ]);
    $this->assertSame(['off', 'Checking', null], [ $s['account']['chip'], $s['account']['text'], $s['account']['action'] ]);
    $this->assertNull($s['usage']);
    $this->assertSame('open', $s['primary']);

    // A legacy build that never talks to tangible.one will never answer.
    $legacy = hub\row_state([ 'licence_managed' => true, 'cloud' => false, 'free' => null ]);
    $this->assertSame('No licence info', $legacy['account']['text']);
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
