<?php

declare( strict_types=1 );

namespace ExamplePress\ThemeUpdate;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page registration and asset enqueuing.
 *
 * Registers a submenu page under Appearance (themes.php) called "EP Updates".
 * The page is a thin shell — all data fetching and mutations happen via the
 * REST API and the JS frontend.
 */
final class AdminPage {

	private Updater $updater;

	public function __construct( Updater $updater ) {
		$this->updater = $updater;
	}

	/**
	 * Register admin menu hook.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
	}

	/**
	 * Add the submenu page under Appearance.
	 */
	public function add_menu_page(): void {
		$hook = add_submenu_page(
			'themes.php',
			__( 'ExamplePress Updates', 'examplepress-theme-update' ),
			__( 'EP Updates', 'examplepress-theme-update' ),
			'update_themes',
			'ep-theme-updates',
			[ $this, 'render_page' ]
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, [ $this, 'enqueue_assets' ] );
		}
	}

	/**
	 * Enqueue CSS and JS for the admin page.
	 */
	public function enqueue_assets(): void {
		$version = defined( 'EP_THEME_UPDATE_VERSION' ) ? EP_THEME_UPDATE_VERSION : '2.0.0';
		$file    = defined( 'EP_THEME_UPDATE_FILE' ) ? EP_THEME_UPDATE_FILE : __DIR__ . '/../examplepress-theme-update.php';

		wp_enqueue_style(
			'ep-theme-updates',
			plugins_url( 'assets/css/admin.css', $file ),
			[],
			$version
		);

		wp_enqueue_script(
			'ep-theme-updates',
			plugins_url( 'assets/js/admin.js', $file ),
			[ 'wp-api-fetch', 'wp-i18n' ],
			$version,
			true
		);

		wp_localize_script( 'ep-theme-updates', 'epThemeUpdate', [
			'restNamespace' => 'ep-theme-update/v1',
			'themeSlug'     => Updater::THEME_SLUG,
			'nonce'         => wp_create_nonce( 'wp_rest' ),
		] );
	}

	/**
	 * Render the admin page shell.
	 */
	public function render_page(): void {
		include __DIR__ . '/views/admin-page.php';
	}
}
