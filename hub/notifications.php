<?php
/**
 * Hub notifications — the one owned channel.
 *
 * The rule this store exists to enforce: Tangible never posts an admin
 * notice. Announcements, tips and renewal heads-ups land here, render in the
 * Hub's rail, and are counted by the admin-bar badge — and nowhere else.
 *
 * Items are registered by id and registration is idempotent, because feeds
 * re-deliver: a campaign fetched hourly must not duplicate, and its latest
 * copy should win. Dismissal is a SEPARATE per-site record so a re-delivered
 * item stays dismissed — merging the two is how "don't show me this again"
 * quietly stops being true.
 *
 * Remote feeds are not built yet. The seam is `tangible_hub_notifications_feed`,
 * fired on the (throttled) refresh: the campaign-channel client will hydrate
 * this store from tangible.one through it; nothing in the Hub changes when it
 * does. Per-user read-state is a known, deliberate cut — everything here is
 * site-wide options, same as the framework's notice dismissals.
 */
namespace tangible\hub;

const ITEMS_KEY     = 'tangible_hub_notifications';
const DISMISSED_KEY = 'tangible_hub_notifications_dismissed';

const KINDS = ['whats-new', 'tip', 'notice'];

/**
 * Register (or re-deliver) one item.
 *
 *   id      required, unique — the idempotency key
 *   kind    whats-new | tip | notice   (unknown kinds normalize to notice)
 *   title   short heading
 *   body    one or two sentences, plain text
 *   action  ['label' => …, 'url' => …]  optional single call-to-action
 *   created unix time; defaults to now, kept from the FIRST delivery so
 *           redelivery does not float an old item back to the top
 */
function add_notification($item) {
  if (empty($item['id']) || !is_string($item['id'])) {
    throw new \Exception('Hub notification needs a string id');
  }

  $item += [ 'kind' => 'notice', 'title' => '', 'body' => '', 'action' => null, 'created' => time() ];
  if (!in_array($item['kind'], KINDS, true)) $item['kind'] = 'notice';

  $items = get_option(ITEMS_KEY, []);
  if (isset($items[ $item['id'] ])) {
    // Latest content wins, first delivery time survives.
    $item['created'] = $items[ $item['id'] ]['created'];
  }
  $items[ $item['id'] ] = $item;
  update_option(ITEMS_KEY, $items, false);
}

/** Dismiss one item. Sticky across redelivery by design. */
function dismiss_notification($id) {
  $dismissed = get_option(DISMISSED_KEY, []);
  $dismissed[$id] = time();
  update_option(DISMISSED_KEY, $dismissed, false);
}

/** Items, newest first. Dismissed ones excluded unless asked for. */
function get_notifications($include_dismissed = false) {
  $items = array_values(get_option(ITEMS_KEY, []));
  if (!$include_dismissed) {
    $dismissed = get_option(DISMISSED_KEY, []);
    $items = array_values(array_filter($items, function ($i) use ($dismissed) {
      return !isset($dismissed[ $i['id'] ]);
    }));
  }
  usort($items, function ($a, $b) { return $b['created'] <=> $a['created']; });
  return $items;
}

/** What the admin-bar pill shows. This store and nothing else feeds it. */
function get_unread_count() {
  return count(get_notifications());
}
