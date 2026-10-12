# Design settings

Reset, export and import for plugins whose design lives in the Customizer.
One module, so every Tangible design plugin gets the same **Design** tab and
an export from one site carries the whole suite's design to another.

## Use

```php
use tangible\design_settings;

$plugin = \tangible\framework\register_plugin( $config );

design_settings\register( $plugin, array(
  'options'    => array( 'h5pd_designer_options' ), // option names holding the design
  'theme_mods' => array(),                          // theme-mod key prefixes (legacy templates)
  'panel'      => 'h5pd_designer_panel',            // Customizer panel to deep-link
  'migrate'    => null,                             // fn( array $section, string $from_version ): array
) );

\tangible\framework\register_plugin_settings( $plugin, array(
  'tabs' => array(
    'design'  => design_settings\tab( $plugin ),
    'license' => array( … ),
  ),
) );
```

## The tab

- **Open the Customizer**: links to the registered panel.
- **Export**: this plugin, or every registered design plugin on the site.
  It downloads a JSON file. Only registered options and theme mods go in;
  licences and other settings never do.
- **Import**: upload a file and review it before anything changes. The review
  shows each plugin's applied and skipped values, and lets you untick plugins.
  Applying *replaces* each chosen plugin's design: it doesn't merge.
- **Reset**: deletes the registered options and theme mods after a confirm
  dialog.

The capability is `edit_theme_options`, the same one the Customizer uses. In
network admin the tab only explains that design is per site.

## Import safety

Every value is matched to a registered Customizer setting
(`option[key]`, `option` or a theme mod) and run through that setting's own
`sanitize()` and `validate()`, using a `WP_Customize_Manager` with every
plugin's `customize_register` callbacks applied. There's no second allowlist to
drift out of date. Anything without a setting on this site (an inactive
template's keys, a retired key, a foreign option) is skipped and listed in the
review. A plugin's `migrate` callback receives the raw section first, so exports
from older versions are renamed into today's keys before sanitizing.

## Format

```json
{
  "format": "tangible-design-settings",
  "version": 1,
  "exported": "2026-10-03T12:00:00+00:00",
  "site": "https://example.com",
  "plugins": {
    "design-upgrade-pro-h5p": {
      "title": "Design Upgrade Pro for H5P",
      "version": "1.6.0",
      "options": { "h5pd_designer_options": { "btn_color_primary": "#002bc9" } },
      "theme_mods": {}
    }
  }
}
```

A preset is the same format shipped with a plugin, so presets can reuse
`plan()` and `apply()`.

## Hooks

- `tangible_design_settings_imported( $plugin )`: fires after a plugin's design is replaced.
- `tangible_design_settings_reset( $plugin )`: fires after a reset.

Plugins that cache output built from their options should rebuild on these, or
on their options' own `update_option_*` and `delete_option_*` hooks, which fire
anyway.
