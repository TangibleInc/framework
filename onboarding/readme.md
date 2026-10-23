# Onboarding

The first-run setup wizard every Tangible plugin can show after activation, and the
steps it asks (licence or Connect, usage data, done). The shell and the shared steps
live here; each plugin supplies only its own content.

## Where a plugin's onboarding goes

Everything a plugin adds to the onboarder lives in one file, `includes/onboarding.php`
(`inc/onboarding.php` in plugins that already use `inc/`): the `register_wizard()` call
and every `tangible_onboarding_*` filter, with their strings. The main plugin file
requires it right after `register_plugin()`, inside the same `plugins_loaded` callback,
so `$plugin` is in scope:

```php
add_action('plugins_loaded', function () {
  $plugin = framework\register_plugin([ /* … */ ]);
  // settings, dependencies, updater…

  require __DIR__ . '/includes/onboarding.php';
});
```

```php
// includes/onboarding.php
if (!function_exists('tangible\\onboarding\\register_wizard')) return;

\tangible\onboarding\register_wizard($plugin, [
  'short_title' => 'Example',
  'welcome'     => [ /* see welcome_config() in welcome.php */ ],
]);

add_filter('tangible_onboarding_done', function ($done, $for) {
  if (($for->name ?? '') !== 'tangible-example') return $done;
  $done['summary'] = __('Example is ready.', 'tangible-example');
  return $done;
}, 10, 2);
```

One file per plugin, at the same path in every plugin, means anyone looking for a
plugin's onboarding text (people reviewing its wording, translators, an agent) finds
it without reading the main file. Every filter receives the plugin it runs for, so
check `$for->name` and return the value unchanged for other plugins: the filters are
shared by every Tangible plugin on the site.

## What a plugin can supply

| Hook | Shapes |
| --- | --- |
| `register_wizard($plugin, $args)` | `short_title`, and the Welcome media card (`welcome`) |
| `tangible_onboarding_welcome` | the Welcome media card, when it needs runtime data |
| `tangible_onboarding_content_counts` | extra rows in Usage data's "Published content" (e.g. courses) |
| `tangible_onboarding_usage_rows` | the Usage data rows themselves |
| `tangible_onboarding_done` | the Done step: heading, summary, notices, links, primary button |
| `tangible_onboarding_facts` | the facts the step plan is resolved from |

Layout rules for the wizard itself are in [layout-rules.md](layout-rules.md).
