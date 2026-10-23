<?php
/**
 * The Design tab and its admin-post handlers.
 *
 * The framework renders tab content inside its own settings <form>, and a
 * nested <form> is dropped by the HTML parser, so this tab's forms are
 * printed at body level from admin_footer and its controls join them with
 * the `form` attribute. The reset confirm is a native <dialog> (focus trap,
 * Esc to close, ::backdrop) — the pattern Design Upgrade Pro shipped first.
 *
 * Capability: edit_theme_options, the Customizer's own. An import is held
 * per user for review (a transient) and re-planned at apply time, so what
 * gets written is sanitized against the settings as they are then.
 */
namespace tangible\design_settings;

use tangible\framework;

const ACTION = 'tangible_design_settings';

/**
 * Tab config for register_plugin_settings():
 *   'tabs' => [ 'design' => design_settings\tab( $plugin ), … ]
 */
function tab( $plugin, $slug = 'design' ) {
	return array(
		// A closure, so it is translated when the page renders (this runs on
		// plugins_loaded, before translations load).
		'title'    => function () {
			return __( 'Design', 'tangible-framework' );
		},
		'callback' => function ( $plugin ) use ( $slug ) {
			render_tab( $plugin, $slug );
		},
	);
}

function review_key() {
	return ACTION . '_review_' . get_current_user_id();
}

function tab_url( $plugin, $slug, array $args = array() ) {
	return add_query_arg( $args, framework\get_plugin_settings_page_url( $plugin, $slug ) );
}

function render_tab( $plugin, $slug ) {

	if ( is_network_admin() ) {
		echo '<p>' . esc_html__( 'Design settings belong to each site. Manage them from a site’s dashboard.', 'tangible-framework' ) . '</p>';
		return;
	}
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	$entry = get( $plugin->name );
	if ( ! $entry ) {
		return;
	}

	add_action( 'admin_footer', function () use ( $plugin, $slug ) {
		render_forms( $plugin, $slug );
	} );

	render_notices();

	$customizer = admin_url( 'customize.php' . ( $entry->panel ? '?autofocus[panel]=' . rawurlencode( $entry->panel ) : '' ) );
	$others     = count( registered() ) - 1;
	$review     = isset( $_GET['design-import'] ) ? get_transient( review_key() ) : false;
	?>
	<div class="tangible-design-settings">
		<h2><?php esc_html_e( 'Design settings', 'tangible-framework' ); ?></h2>
		<p>
			<?php esc_html_e( 'Design settings are edited live in the Customizer.', 'tangible-framework' ); ?>
			<a href="<?php echo esc_url( $customizer ); ?>"><?php esc_html_e( 'Open the Customizer', 'tangible-framework' ); ?></a>
		</p>

		<?php if ( is_array( $review ) ) { render_review( $review ); } ?>

		<h3><?php esc_html_e( 'Export', 'tangible-framework' ); ?></h3>
		<p><?php esc_html_e( 'Download the design as a file to keep, or to import on another site. Licences are never included.', 'tangible-framework' ); ?></p>
		<p>
			<button type="submit" class="button" form="tangible-design-export" name="scope" value="plugin">
				<?php
				/* translators: %s: plugin name */
				echo esc_html( sprintf( __( 'Export %s', 'tangible-framework' ), $plugin->title ) );
				?>
			</button>
			<?php if ( $others > 0 ) { ?>
				<button type="submit" class="button" form="tangible-design-export" name="scope" value="all">
					<?php
					$count = $others + 1;
					/* translators: %s: number of plugins */
					echo esc_html( sprintf( _n( 'Export all Tangible design settings (%s plugin)', 'Export all Tangible design settings (%s plugins)', $count, 'tangible-framework' ), number_format_i18n( $count ) ) );
					?>
				</button>
			<?php } ?>
		</p>

		<h3><?php esc_html_e( 'Import', 'tangible-framework' ); ?></h3>
		<p><?php esc_html_e( 'Choose an export file. You’ll see what will change before anything is applied.', 'tangible-framework' ); ?></p>
		<p>
			<input type="file" name="file" accept=".json,application/json" form="tangible-design-import" required>
			<button type="submit" class="button" form="tangible-design-import"><?php esc_html_e( 'Review import…', 'tangible-framework' ); ?></button>
		</p>

		<h3><?php esc_html_e( 'Reset', 'tangible-framework' ); ?></h3>
		<p>
			<?php
			/* translators: %s: plugin name */
			echo esc_html( sprintf( __( 'Remove every saved design setting for %s. The plugin then renders its built-in default design until new values are saved.', 'tangible-framework' ), $plugin->title ) );
			?>
		</p>
		<p>
			<button type="button" class="button" id="tangible-design-reset-open"><?php esc_html_e( 'Reset all design settings…', 'tangible-framework' ); ?></button>
		</p>
	</div>
	<?php
}

function render_notices() {
	$notice = sanitize_key( $_GET['design-notice'] ?? '' );
	$count  = (int) ( $_GET['design-count'] ?? 0 );
	$error  = sanitize_text_field( wp_unslash( $_GET['design-error'] ?? '' ) );

	$messages = array(
		'reset'     => array( 'success', __( 'All design settings have been reset to the plugin’s default design.', 'tangible-framework' ) ),
		/* translators: %s: number of plugins */
		'imported'  => array( 'success', sprintf( _n( 'Imported design settings for %s plugin.', 'Imported design settings for %s plugins.', $count, 'tangible-framework' ), number_format_i18n( $count ) ) ),
		'cancelled' => array( 'info', __( 'Import cancelled. Nothing was changed.', 'tangible-framework' ) ),
	);
	if ( isset( $messages[ $notice ] ) ) {
		list( $type, $text ) = $messages[ $notice ];
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}
	if ( '' !== $error ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
	}
}

function render_review( array $payload ) {
	$plan = plan( $payload );
	?>
	<?php if ( customizer_error() ) { ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: %s: the error the other plugin reported, in a code element */
					esc_html__( 'Another plugin’s Customizer setup failed while this import was checked, so its values are listed as skipped. The plugin reported: %s', 'tangible-framework' ),
					'<code>' . esc_html( customizer_error() ) . '</code>'
				);
				?>
			</p>
		</div>
	<?php } ?>
	<div class="notice notice-info inline tangible-design-review">
		<h3><?php esc_html_e( 'Review import', 'tangible-framework' ); ?></h3>
		<p>
			<?php
			echo esc_html( sprintf(
				/* translators: 1: the site the file was exported from, 2: when it was exported */
				__( 'From %1$s, exported %2$s.', 'tangible-framework' ),
				(string) ( $payload['site'] ?? __( 'an unknown site', 'tangible-framework' ) ),
				(string) ( $payload['exported'] ?? '' )
			) );
			?>
			<?php esc_html_e( 'Each selected plugin’s design is replaced by the file’s.', 'tangible-framework' ); ?>
		</p>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Import', 'tangible-framework' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Plugin', 'tangible-framework' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Settings', 'tangible-framework' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Skipped', 'tangible-framework' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $plan as $name => $step ) {
				$ready = 'ready' === $step['status'];
				$id    = 'tangible-design-import-' . sanitize_key( $name );
				?>
				<tr>
					<td>
						<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="plugins[]" value="<?php echo esc_attr( $name ); ?>"
							form="tangible-design-apply" <?php checked( $ready ); disabled( ! $ready ); ?>>
					</td>
					<td>
						<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $step['title'] ); ?></label>
						<?php if ( $step['from'] ) { ?><br><small><?php
							/* translators: %s: plugin version number */
							echo esc_html( sprintf( __( 'Exported from version %s', 'tangible-framework' ), $step['from'] ) );
						?></small><?php } ?>
					</td>
					<td><?php echo $ready ? (int) $step['applied'] : '—'; ?></td>
					<td>
						<?php if ( ! $ready ) { ?>
							<?php esc_html_e( 'Not installed on this site', 'tangible-framework' ); ?>
						<?php } elseif ( $step['skipped'] ) { ?>
							<details>
								<summary><?php
									$skipped = count( $step['skipped'] );
									/* translators: %s: number of settings */
									echo esc_html( sprintf( _n( '%s not available here', '%s not available here', $skipped, 'tangible-framework' ), number_format_i18n( $skipped ) ) );
								?></summary>
								<code><?php echo esc_html( implode( ', ', $step['skipped'] ) ); ?></code>
							</details>
						<?php } else { ?>
							<?php esc_html_e( 'None', 'tangible-framework' ); ?>
						<?php } ?>
					</td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
		<p>
			<button type="submit" class="button button-primary" form="tangible-design-apply" name="apply" value="1"><?php esc_html_e( 'Apply import', 'tangible-framework' ); ?></button>
			<button type="submit" class="button" form="tangible-design-apply" name="cancel" value="1" formnovalidate><?php esc_html_e( 'Cancel', 'tangible-framework' ); ?></button>
		</p>
	</div>
	<?php
}

/**
 * Forms and dialog, at body level (see file docblock).
 */
function render_forms( $plugin, $slug ) {
	$post   = admin_url( 'admin-post.php' );
	$hidden = function ( $action ) use ( $plugin, $slug ) {
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( ACTION . '_' . $action ) );
		printf( '<input type="hidden" name="plugin" value="%s">', esc_attr( $plugin->name ) );
		printf( '<input type="hidden" name="tab" value="%s">', esc_attr( $slug ) );
		wp_nonce_field( ACTION . '_' . $action, '_tangible_design_nonce' );
	};
	?>
	<form id="tangible-design-export" method="post" action="<?php echo esc_url( $post ); ?>" hidden><?php $hidden( 'export' ); ?></form>
	<form id="tangible-design-import" method="post" action="<?php echo esc_url( $post ); ?>" enctype="multipart/form-data" hidden><?php $hidden( 'import' ); ?></form>
	<form id="tangible-design-apply" method="post" action="<?php echo esc_url( $post ); ?>" hidden><?php $hidden( 'apply' ); ?></form>

	<dialog id="tangible-design-reset-dialog" aria-labelledby="tangible-design-reset-title" style="max-width: 32rem; padding: 1.5rem;">
		<form method="post" action="<?php echo esc_url( $post ); ?>">
			<?php $hidden( 'reset' ); ?>
			<h2 id="tangible-design-reset-title" style="margin-top: 0;"><?php esc_html_e( 'Reset all design settings?', 'tangible-framework' ); ?></h2>
			<p>
				<?php
				/* translators: %s: plugin name */
				echo esc_html( sprintf( __( 'Every saved design setting for %s will be removed. This cannot be undone; export first to keep a copy.', 'tangible-framework' ), $plugin->title ) );
				?>
			</p>
			<p style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-bottom: 0;">
				<button type="button" class="button" id="tangible-design-reset-cancel"><?php esc_html_e( 'Cancel', 'tangible-framework' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Reset', 'tangible-framework' ); ?></button>
			</p>
		</form>
	</dialog>
	<script>
	( function () {
		var dialog = document.getElementById( 'tangible-design-reset-dialog' );
		var open = document.getElementById( 'tangible-design-reset-open' );
		if ( ! dialog || ! open || ! dialog.showModal ) return;
		open.addEventListener( 'click', function () { dialog.showModal(); } );
		document.getElementById( 'tangible-design-reset-cancel' ).addEventListener( 'click', function () { dialog.close(); open.focus(); } );
	} )();
	</script>
	<?php
}

/**
 * Shared guard for every handler: capability, nonce, registered plugin.
 *
 * @return object The plugin
 */
function guard( $action ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to change design settings.', 'tangible-framework' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( ACTION . '_' . $action, '_tangible_design_nonce' );
	$entry = get( sanitize_text_field( wp_unslash( $_POST['plugin'] ?? '' ) ) );
	if ( ! $entry ) {
		wp_die( esc_html__( 'Unknown plugin.', 'tangible-framework' ), '', array( 'response' => 400 ) );
	}
	return $entry->plugin;
}

function back( $plugin, array $args ) {
	wp_safe_redirect( tab_url( $plugin, sanitize_key( $_POST['tab'] ?? 'design' ), $args ) );
	exit;
}

add_action( 'admin_post_' . ACTION . '_export', function () {
	$plugin = guard( 'export' );
	$names  = 'all' === ( $_POST['scope'] ?? '' ) ? array() : array( $plugin->name );
	$host   = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$file   = 'tangible-design-' . ( $names ? $plugin->name : 'all' ) . '-' . $host . '-' . gmdate( 'Y-m-d' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $file . '"' );
	echo wp_json_encode( export( $names ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
} );

add_action( 'admin_post_' . ACTION . '_import', function () {
	$plugin = guard( 'import' );
	$upload = $_FILES['file'] ?? null;

	if ( ! $upload || UPLOAD_ERR_OK !== ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $upload['tmp_name'] ) ) {
		back( $plugin, array( 'design-error' => __( 'No file was uploaded.', 'tangible-framework' ) ) );
	}
	if ( $upload['size'] > MB_IN_BYTES ) {
		back( $plugin, array( 'design-error' => __( 'That file is too large to be a design settings export.', 'tangible-framework' ) ) );
	}

	$payload = parse( file_get_contents( $upload['tmp_name'] ) );
	if ( is_wp_error( $payload ) ) {
		back( $plugin, array( 'design-error' => $payload->get_error_message() ) );
	}

	set_transient( review_key(), $payload, 30 * MINUTE_IN_SECONDS );
	back( $plugin, array( 'design-import' => 'review' ) );
} );

add_action( 'admin_post_' . ACTION . '_apply', function () {
	$plugin  = guard( 'apply' );
	$payload = get_transient( review_key() );
	delete_transient( review_key() );

	if ( ! empty( $_POST['cancel'] ) ) {
		back( $plugin, array( 'design-notice' => 'cancelled' ) );
	}
	if ( ! is_array( $payload ) ) {
		back( $plugin, array( 'design-error' => __( 'The import expired. Choose the file again.', 'tangible-framework' ) ) );
	}

	$names = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['plugins'] ?? array() ) ) );
	$plan  = plan( $payload );
	$names = array_values( array_filter( $names, function ( $name ) use ( $plan ) {
		return 'ready' === ( $plan[ $name ]['status'] ?? '' );
	} ) );
	apply( $plan, $names );
	back( $plugin, array( 'design-notice' => 'imported', 'design-count' => count( $names ) ) );
} );

add_action( 'admin_post_' . ACTION . '_reset', function () {
	$plugin = guard( 'reset' );
	reset( $plugin->name );
	back( $plugin, array( 'design-notice' => 'reset' ) );
} );
