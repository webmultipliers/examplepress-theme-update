<?php

declare( strict_types=1 );

namespace ExamplePress\ThemeUpdate;

defined( 'ABSPATH' ) || exit;

/**
 * Self-protection: prevents deactivation/deletion while EP theme is active,
 * and shows an admin notice when the theme is not active.
 */
final class Protection {

	private const THEME_SLUG = 'examplepress-theme';

	private string $plugin_basename;

	public function __construct( string $plugin_basename ) {
		$this->plugin_basename = $plugin_basename;
	}

	/**
	 * Register all protection hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'plugin_action_links_' . $this->plugin_basename, [ $this, 'lock_action_links' ] );
		add_filter( 'network_admin_plugin_action_links_' . $this->plugin_basename, [ $this, 'lock_action_links' ] );
		add_action( 'deactivate_' . $this->plugin_basename, [ $this, 'block_deactivation' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_inactive_notice' ] );
	}

	/**
	 * Remove "Deactivate" and "Delete" links while EP theme is active.
	 *
	 * @param  array<string, string> $actions
	 * @return array<string, string>
	 */
	public function lock_action_links( array $actions ): array {
		if ( ! $this->is_ep_theme_active() ) {
			return $actions;
		}

		unset( $actions['deactivate'], $actions['delete'] );

		$actions['ep_locked'] = sprintf(
			'<span style="color:#8c8f94;cursor:default;" title="%s">%s</span>',
			esc_attr__( 'This plugin cannot be deactivated while ExamplePress is the active theme.', 'examplepress-theme-update' ),
			esc_html__( 'Required by ExamplePress', 'examplepress-theme-update' )
		);

		return $actions;
	}

	/**
	 * Prevent programmatic deactivation while EP theme is active.
	 */
	public function block_deactivation(): void {
		if ( ! $this->is_ep_theme_active() ) {
			return;
		}

		wp_die(
			esc_html__( 'The ExamplePress Theme Update plugin cannot be deactivated while ExamplePress is the active theme. Switch themes first.', 'examplepress-theme-update' ),
			esc_html__( 'Plugin Deactivation Blocked', 'examplepress-theme-update' ),
			[ 'back_link' => true, 'response' => 403 ]
		);
	}

	/**
	 * Show a notice if this plugin is active but the EP theme is not.
	 */
	public function maybe_show_inactive_notice(): void {
		if ( $this->is_ep_theme_active() ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'ExamplePress Theme Update is active but the ExamplePress theme is not installed or activated. This plugin has no effect without the theme.', 'examplepress-theme-update' )
		);
	}

	/**
	 * Check whether the ExamplePress theme (or a child of it) is active.
	 */
	public function is_ep_theme_active(): bool {
		$active   = get_option( 'stylesheet' );
		$template = get_option( 'template' );

		return self::THEME_SLUG === $active || self::THEME_SLUG === $template;
	}
}
