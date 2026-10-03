<?php
/**
 * Design settings — reset, export and import for plugins whose design lives
 * in the Customizer.
 *
 * Several Tangible plugins style a third-party surface (LearnDash, H5P,
 * LearnDash quizzes) from Customizer settings stored in options (and, for
 * one legacy template, theme mods). Each needs the same three tools on its
 * settings page, and an export from one should carry the whole suite's
 * design to another site, so they live here once:
 *
 *   register( $plugin, [ 'options' => [...], 'panel' => '...' ] )
 *   'design' => tab( $plugin )        in register_plugin_settings' tabs
 *
 * Import never writes what the Customizer couldn't: every value is matched
 * to a registered Customizer setting and run through that setting's own
 * sanitize/validate, so there is no second allowlist to drift. Keys with no
 * setting on this site (an inactive template's, a retired key) are skipped
 * and reported. A plugin's `migrate` callback sees the raw section first, so
 * exports from older versions still land. Licences and other options a
 * plugin didn't register are never exported.
 */
namespace tangible\design_settings;

use tangible\framework;

const FORMAT  = 'tangible-design-settings';
const VERSION = 1;

/**
 * @param object $plugin From framework\register_plugin()
 * @param array  $config {
 *   @type string[] $options    Option names holding the design.
 *   @type string[] $theme_mods Theme-mod key prefixes holding design (legacy).
 *   @type string   $panel      Customizer panel to deep-link.
 *   @type callable $migrate    fn( array $section, string $from_version ): array
 * }
 */
function register( $plugin, array $config ) {
	framework::$state->design_settings[ $plugin->name ] = (object) array(
		'plugin'     => $plugin,
		'options'    => array_values( (array) ( $config['options'] ?? array() ) ),
		'theme_mods' => array_values( (array) ( $config['theme_mods'] ?? array() ) ),
		'panel'      => (string) ( $config['panel'] ?? '' ),
		'migrate'    => $config['migrate'] ?? null,
	);
}

/** @return object[] name => registration */
function registered() {
	return framework::$state->design_settings ?? array();
}

function get( $name ) {
	return registered()[ $name ] ?? null;
}

/**
 * The theme mods a registration owns, by prefix.
 */
function owned_theme_mods( $entry ) {
	$mods = array();
	if ( ! $entry->theme_mods ) {
		return $mods;
	}
	foreach ( (array) get_theme_mods() as $key => $value ) {
		foreach ( $entry->theme_mods as $prefix ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				$mods[ $key ] = $value;
			}
		}
	}
	return $mods;
}

/**
 * Export payload for the named plugins (all registered when empty).
 */
function export( array $names = array() ) {
	$plugins = array();
	foreach ( registered() as $name => $entry ) {
		if ( $names && ! in_array( $name, $names, true ) ) {
			continue;
		}
		$options = array();
		foreach ( $entry->options as $option ) {
			$value = get_option( $option, null );
			if ( null !== $value ) {
				$options[ $option ] = $value;
			}
		}
		$plugins[ $name ] = array(
			'title'      => $entry->plugin->title ?? $name,
			'version'    => (string) ( $entry->plugin->version ?? '' ),
			'options'    => $options,
			'theme_mods' => owned_theme_mods( $entry ),
		);
	}
	return array(
		'format'   => FORMAT,
		'version'  => VERSION,
		'exported' => gmdate( 'c' ),
		'site'     => home_url(),
		'plugins'  => $plugins,
	);
}

/**
 * Parse an uploaded file into a payload, or a WP_Error saying why not.
 */
function parse( $json ) {
	$payload = json_decode( (string) $json, true );
	if ( ! is_array( $payload ) || ( $payload['format'] ?? '' ) !== FORMAT ) {
		return new \WP_Error( 'format', 'This file is not a Tangible design settings export.' );
	}
	if ( (int) ( $payload['version'] ?? 0 ) > VERSION ) {
		return new \WP_Error( 'version', 'This export was made by a newer version. Update the plugins on this site first.' );
	}
	if ( ! is_array( $payload['plugins'] ?? null ) ) {
		return new \WP_Error( 'plugins', 'This export contains no design settings.' );
	}
	return $payload;
}

/**
 * A Customizer manager with every plugin's settings registered, to borrow
 * their sanitize/validate callbacks. Built once per request; a setting's
 * sanitize_callback lives on a filter it adds when constructed, so anything
 * that resets hooks (the test suite does, per test) needs a fresh one.
 */
function customizer( $fresh = false ) {
	static $manager = null;
	if ( $fresh || null === $manager ) {
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		$manager = new \WP_Customize_Manager( array( 'settings_previewed' => false ) );

		// Registration callbacks are written for a real Customizer request:
		// some read the global (LearnDash does), and one that throws must not
		// take the settings page down. Settings it would have added are
		// simply absent, so their values are skipped, and the review says so.
		$previous                  = $GLOBALS['wp_customize'] ?? null;
		$GLOBALS['wp_customize']   = $manager;
		framework::$state->design_settings_error = null;
		try {
			do_action( 'customize_register', $manager );
		} catch ( \Throwable $e ) {
			framework::$state->design_settings_error = $e->getMessage();
		}
		$GLOBALS['wp_customize'] = $previous;
	}
	return $manager;
}

/**
 * Why the Customizer's settings couldn't all be loaded, if they couldn't.
 */
function customizer_error() {
	return framework::$state->design_settings_error ?? null;
}

/**
 * Sanitize one value through the Customizer setting registered under $id.
 *
 * @return array{0: bool, 1: mixed} [ accepted, value ]
 */
function sanitize_setting( $id, $value, $type ) {
	$setting = customizer()->get_setting( $id );
	if ( ! $setting || $setting->type !== $type ) {
		return array( false, null );
	}
	$clean = $setting->sanitize( $value );
	if ( null === $clean || is_wp_error( $clean ) ) {
		return array( false, null );
	}
	$valid = $setting->validate( $clean );
	if ( is_wp_error( $valid ) ) {
		return array( false, null );
	}
	return array( true, $clean );
}

/**
 * What importing $payload would do, per plugin, without writing anything.
 *
 * @return array name => [ title, status: ready|missing, from, values: [options, theme_mods], applied, skipped: string[] ]
 */
function plan( array $payload ) {
	$plan = array();
	foreach ( (array) $payload['plugins'] as $name => $section ) {
		$section = (array) $section;
		$entry   = get( $name );
		$title   = (string) ( $section['title'] ?? $name );
		if ( ! $entry ) {
			$plan[ $name ] = array( 'title' => $title, 'status' => 'missing', 'from' => (string) ( $section['version'] ?? '' ), 'applied' => 0, 'skipped' => array(), 'values' => null );
			continue;
		}

		if ( is_callable( $entry->migrate ) ) {
			$section = (array) call_user_func( $entry->migrate, $section, (string) ( $section['version'] ?? '' ) );
		}

		$options = array();
		$mods    = array();
		$skipped = array();
		$applied = 0;

		foreach ( (array) ( $section['options'] ?? array() ) as $option => $value ) {
			if ( ! in_array( $option, $entry->options, true ) ) {
				$skipped[] = $option;
				continue;
			}
			if ( is_array( $value ) ) {
				$options[ $option ] = array();
				foreach ( $value as $key => $item ) {
					list( $ok, $clean ) = sanitize_setting( "{$option}[{$key}]", $item, 'option' );
					if ( $ok ) {
						$options[ $option ][ $key ] = $clean;
						$applied++;
					} else {
						$skipped[] = "{$option}[{$key}]";
					}
				}
			} else {
				list( $ok, $clean ) = sanitize_setting( $option, $value, 'option' );
				if ( $ok ) {
					$options[ $option ] = $clean;
					$applied++;
				} else {
					$skipped[] = $option;
				}
			}
		}

		foreach ( (array) ( $section['theme_mods'] ?? array() ) as $key => $value ) {
			$owned = false;
			foreach ( $entry->theme_mods as $prefix ) {
				$owned = $owned || 0 === strpos( (string) $key, $prefix );
			}
			list( $ok, $clean ) = $owned ? sanitize_setting( $key, $value, 'theme_mod' ) : array( false, null );
			if ( $ok ) {
				$mods[ $key ] = $clean;
				$applied++;
			} else {
				$skipped[] = $key;
			}
		}

		$plan[ $name ] = array(
			'title'   => $entry->plugin->title ?? $title,
			'status'  => 'ready',
			'from'    => (string) ( $section['version'] ?? '' ),
			'applied' => $applied,
			'skipped' => $skipped,
			'values'  => array( 'options' => $options, 'theme_mods' => $mods ),
		);
	}
	return $plan;
}

/**
 * Write a planned import for the chosen plugins. Each plugin's design is
 * replaced, not merged: the import makes it match the export.
 */
function apply( array $plan, array $names ) {
	foreach ( $names as $name ) {
		$entry = get( $name );
		$step  = $plan[ $name ] ?? null;
		if ( ! $entry || ! $step || 'ready' !== $step['status'] ) {
			continue;
		}
		foreach ( $entry->options as $option ) {
			$value = $step['values']['options'][ $option ] ?? null;
			if ( null === $value || array() === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		foreach ( owned_theme_mods( $entry ) as $key => $unused ) {
			remove_theme_mod( $key );
		}
		foreach ( $step['values']['theme_mods'] as $key => $value ) {
			set_theme_mod( $key, $value );
		}
		do_action( 'tangible_design_settings_imported', $entry->plugin );
	}
}

/**
 * Remove every design value the plugin registered.
 */
function reset( $name ) {
	$entry = get( $name );
	if ( ! $entry ) {
		return;
	}
	foreach ( $entry->options as $option ) {
		delete_option( $option );
	}
	foreach ( owned_theme_mods( $entry ) as $key => $unused ) {
		remove_theme_mod( $key );
	}
	do_action( 'tangible_design_settings_reset', $entry->plugin );
}

require_once __DIR__ . '/tab.php';
