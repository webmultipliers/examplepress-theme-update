<?php

declare( strict_types=1 );

namespace ExamplePress\ThemeUpdate;

defined( 'ABSPATH' ) || exit;

/**
 * Transient cache wrapper for update manifests and GitHub releases.
 *
 * Manages all transient-based caching, cache invalidation hooks,
 * and the legacy ?ep_force_update_check=1 URL handler.
 */
final class Cache {

	private const MANIFEST_KEY     = 'ep_theme_update_manifest';
	private const RELEASES_KEY     = 'ep_github_releases';
	private const MANIFEST_TTL     = 6 * HOUR_IN_SECONDS;
	private const RELEASES_TTL     = 30 * MINUTE_IN_SECONDS;
	private const ERROR_TTL        = 5 * MINUTE_IN_SECONDS;
	private const LAST_CHECKED_KEY = 'ep_theme_update_last_checked';

	/**
	 * Theme slug used to scope cache flush on upgrade.
	 */
	private const THEME_SLUG = 'examplepress-theme';

	/**
	 * Register WordPress hooks for automatic cache management.
	 */
	public function register_hooks(): void {
		add_action( 'switch_theme', [ $this, 'flush' ] );
		add_action( 'upgrader_process_complete', [ $this, 'flush_after_upgrade' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'maybe_force_check' ] );
	}

	// -----------------------------------------------------------------
	//  Manifest cache
	// -----------------------------------------------------------------

	/**
	 * Get the cached manifest array.
	 *
	 * Returns null when no cache exists or when the cached value is the
	 * empty-array sentinel (indicating a recent failed fetch).
	 */
	public function get_manifest(): ?array {
		$cached = get_transient( self::MANIFEST_KEY );

		if ( false === $cached || ! is_array( $cached ) ) {
			return null;
		}

		return ! empty( $cached ) ? $cached : null;
	}

	/**
	 * Store a successfully-fetched manifest.
	 */
	public function set_manifest( array $data ): void {
		set_transient( self::MANIFEST_KEY, $data, self::MANIFEST_TTL );
	}

	/**
	 * Store the error sentinel (empty array) with a short TTL to avoid
	 * hammering GitHub on repeated failures.
	 */
	public function set_manifest_error(): void {
		set_transient( self::MANIFEST_KEY, [], self::ERROR_TTL );
	}

	// -----------------------------------------------------------------
	//  Releases cache
	// -----------------------------------------------------------------

	/**
	 * Get the cached releases list.
	 */
	public function get_releases(): ?array {
		$cached = get_transient( self::RELEASES_KEY );

		if ( false === $cached || ! is_array( $cached ) ) {
			return null;
		}

		return ! empty( $cached ) ? $cached : null;
	}

	/**
	 * Store the GitHub releases list.
	 */
	public function set_releases( array $data ): void {
		set_transient( self::RELEASES_KEY, $data, self::RELEASES_TTL );
	}

	// -----------------------------------------------------------------
	//  Last-checked timestamp
	// -----------------------------------------------------------------

	/**
	 * Get the Unix timestamp of the last successful manifest check.
	 */
	public function get_last_checked(): ?int {
		$ts = get_option( self::LAST_CHECKED_KEY );

		return $ts ? (int) $ts : null;
	}

	/**
	 * Record the current time as the last-checked timestamp.
	 */
	public function set_last_checked(): void {
		update_option( self::LAST_CHECKED_KEY, time(), false );
	}

	// -----------------------------------------------------------------
	//  Flush
	// -----------------------------------------------------------------

	/**
	 * Delete all cached data and the WP update_themes site transient.
	 */
	public function flush(): void {
		delete_transient( self::MANIFEST_KEY );
		delete_transient( self::RELEASES_KEY );
		delete_site_transient( 'update_themes' );
	}

	/**
	 * Flush cache after a theme upgrade completes — but only if the
	 * ExamplePress theme was one of the themes updated.
	 */
	public function flush_after_upgrade( \WP_Upgrader $upgrader, array $options ): void {
		if ( 'update' !== ( $options['action'] ?? '' ) || 'theme' !== ( $options['type'] ?? '' ) ) {
			return;
		}

		$themes = $options['themes'] ?? [];

		if ( in_array( self::THEME_SLUG, $themes, true ) ) {
			$this->flush();
		}
	}

	/**
	 * Legacy URL handler: flush cache when ?ep_force_update_check=1 is
	 * present in wp-admin. Requires a nonce and update_themes capability.
	 */
	public function maybe_force_check(): void {
		if ( ! current_user_can( 'update_themes' ) ) {
			return;
		}

		if ( empty( $_GET['ep_force_update_check'] ) ) {
			return;
		}

		check_admin_referer( 'ep_force_update_check' );

		$this->flush();

		wp_safe_redirect( remove_query_arg( [ 'ep_force_update_check', '_wpnonce' ] ) );
		exit;
	}
}
