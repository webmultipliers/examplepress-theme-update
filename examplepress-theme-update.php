<?php
/**
 * Plugin Name:  ExamplePress Theme Update
 * Plugin URI:   https://github.com/webmultipliers/examplepress-theme-update
 * Description:  Persistent update manager for the ExamplePress theme. Checks for new versions via GitHub Releases and injects them into the WordPress native updater. This plugin cannot be deactivated while ExamplePress is the active theme.
 * Version:      2.0.0
 * Author:       Web Multipliers
 * Author URI:   https://vinnysgreen.com
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

// ── Plugin constants ─────────────────────────────────────────────────
define( 'EP_THEME_UPDATE_FILE', __FILE__ );
define( 'EP_THEME_UPDATE_DIR', __DIR__ );
define( 'EP_THEME_UPDATE_SLUG', 'examplepress-theme-update' );

// Read version from plugin header to keep it DRY.
$ep_theme_update_data = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
define( 'EP_THEME_UPDATE_VERSION', $ep_theme_update_data['Version'] ?? '2.0.0' );

// ── SPL Autoloader ───────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
	$prefix = 'ExamplePress\\ThemeUpdate\\';

	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = __DIR__ . '/inc/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

// ── Boot ─────────────────────────────────────────────────────────────
ExamplePress\ThemeUpdate\Plugin::init( __FILE__ );
