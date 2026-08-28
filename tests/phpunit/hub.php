<?php
namespace tests\framework;

use tangible\hub;

/**
 * Hub — the notification store.
 *
 * One owned channel so Tangible never posts an admin notice. Items are
 * registered by id (idempotent — feeds re-deliver), dismissal is a separate
 * per-site record so a re-delivered item stays dismissed, and the admin-bar
 * badge is exactly the unread count of this store and nothing else.
 */
class Hub_TestCase extends \WP_UnitTestCase {

  function tearDown(): void {
    delete_option('tangible_hub_notifications');
    delete_option('tangible_hub_notifications_dismissed');
    parent::tearDown();
  }

  function test_adding_by_id_is_idempotent() {
    hub\add_notification([ 'id' => 'ldx-3-2', 'kind' => 'whats-new', 'title' => 'Templates' ]);
    hub\add_notification([ 'id' => 'ldx-3-2', 'kind' => 'whats-new', 'title' => 'Templates, redelivered' ]);
    $items = hub\get_notifications();
    $this->assertCount(1, $items);
    // Redelivery updates content — the feed's latest copy wins…
    $this->assertSame('Templates, redelivered', $items[0]['title']);
  }

  function test_an_item_without_an_id_is_rejected() {
    $this->expectException(\Exception::class);
    hub\add_notification([ 'title' => 'anon' ]);
  }

  function test_dismissal_is_sticky_across_redelivery() {
    hub\add_notification([ 'id' => 'tip-1', 'kind' => 'tip', 'title' => 'A tip' ]);
    hub\dismiss_notification('tip-1');
    // …but dismissal survives it: dismissed is a separate record.
    hub\add_notification([ 'id' => 'tip-1', 'kind' => 'tip', 'title' => 'A tip again' ]);
    $this->assertCount(0, hub\get_notifications());
    $this->assertCount(1, hub\get_notifications(true));
    $this->assertSame(0, hub\get_unread_count());
  }

  function test_unread_count_is_undismissed_items_only() {
    hub\add_notification([ 'id' => 'a', 'kind' => 'tip', 'title' => 'A' ]);
    hub\add_notification([ 'id' => 'b', 'kind' => 'whats-new', 'title' => 'B' ]);
    $this->assertSame(2, hub\get_unread_count());
    hub\dismiss_notification('a');
    $this->assertSame(1, hub\get_unread_count());
  }

  function test_newest_first() {
    hub\add_notification([ 'id' => 'old', 'title' => 'Old', 'created' => 100 ]);
    hub\add_notification([ 'id' => 'new', 'title' => 'New', 'created' => 200 ]);
    $this->assertSame(['new','old'], array_column(hub\get_notifications(), 'id'));
  }

  function test_unknown_kind_is_normalized_to_notice() {
    hub\add_notification([ 'id' => 'x', 'kind' => 'shouting', 'title' => 'X' ]);
    $this->assertSame('notice', hub\get_notifications()[0]['kind']);
  }
}
