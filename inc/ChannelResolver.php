<?php

declare( strict_types=1 );

namespace ExamplePress\ThemeUpdate;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the active update channel and manages version pinning.
 *
 * Channel resolution priority:
 *  1. `examplepress_update_channel` filter
 *  2. `EP_UPDATE_CHANNEL` constant (wp-config.php)
 *  3. `ep_update_channel` WP option (admin UI)
 *  4. Auto-detect from installed theme version string
 *  5. Default: 'stable'
 */
final class ChannelResolver {

	private const CHANNEL_OPTION = 'ep_update_channel';
	private const PIN_OPTION     = 'ep_pinned_version';
	private const THEME_SLUG     = 'examplepress-theme';

	private const VALID_CHANNELS = [ 'stable', 'development' ];

	/**
	 * No hooks — pure data class.
	 */
	public function register_hooks(): void {}

	// -----------------------------------------------------------------
	//  Channel
	// -----------------------------------------------------------------

	/**
	 * Resolve the active update channel.
	 */
	public function resolve(): string {
		// 1. Filter.
		$channel = apply_filters( 'examplepress_update_channel', '' );

		if ( in_array( $channel, self::VALID_CHANNELS, true ) ) {
			return $channel;
		}

		// 2. Constant.
		if ( defined( 'EP_UPDATE_CHANNEL' ) && in_array( EP_UPDATE_CHANNEL, self::VALID_CHANNELS, true ) ) {
			return EP_UPDATE_CHANNEL;
		}

		// 3. WP option.
		$option = get_option( self::CHANNEL_OPTION );

		if ( $option && in_array( $option, self::VALID_CHANNELS, true ) ) {
			return $option;
		}

		// 4. Auto-detect from installed theme version.
		$theme = wp_get_theme( self::THEME_SLUG );

		if ( $theme->exists() ) {
			$version = $theme->get( 'Version' );

			if ( $version && preg_match( '/(dev|alpha|beta|rc)/i', $version ) ) {
				return 'development';
			}
		}

		// 5. Default.
		return 'stable';
	}

	/**
	 * Determine which source is controlling the channel.
	 *
	 * @return string One of: 'filter', 'constant', 'option', 'auto', 'default'.
	 */
	public function get_channel_source(): string {
		$filter_value = apply_filters( 'examplepress_update_channel', '' );

		if ( in_array( $filter_value, self::VALID_CHANNELS, true ) ) {
			return 'filter';
		}

		if ( defined( 'EP_UPDATE_CHANNEL' ) && in_array( EP_UPDATE_CHANNEL, self::VALID_CHANNELS, true ) ) {
			return 'constant';
		}

		$option = get_option( self::CHANNEL_OPTION );

		if ( $option && in_array( $option, self::VALID_CHANNELS, true ) ) {
			return 'option';
		}

		$theme = wp_get_theme( self::THEME_SLUG );

		if ( $theme->exists() ) {
			$version = $theme->get( 'Version' );

			if ( $version && preg_match( '/(dev|alpha|beta|rc)/i', $version ) ) {
				return 'auto';
			}
		}

		return 'default';
	}

	/**
	 * Save the channel preference to the WP option.
	 *
	 * Returns false if a higher-priority source (filter or constant)
	 * is already controlling the channel — in that case the option
	 * would have no effect.
	 */
	public function set_channel( string $channel ): bool {
		if ( ! in_array( $channel, self::VALID_CHANNELS, true ) ) {
			return false;
		}

		$source = $this->get_channel_source();

		if ( in_array( $source, [ 'filter', 'constant' ], true ) ) {
			return false;
		}

		update_option( self::CHANNEL_OPTION, $channel, false );

		return true;
	}

	// -----------------------------------------------------------------
	//  Version pinning
	// -----------------------------------------------------------------

	/**
	 * Get the pinned version, or null if no pin is set.
	 */
	public function get_pinned_version(): ?string {
		$version = get_option( self::PIN_OPTION );

		return $version ? (string) $version : null;
	}

	/**
	 * Set or clear the pinned version.
	 *
	 * Pass null to clear the pin.
	 */
	public function set_pinned_version( ?string $version ): void {
		if ( null === $version || '' === $version ) {
			delete_option( self::PIN_OPTION );
			return;
		}

		update_option( self::PIN_OPTION, $version, false );
	}

	/**
	 * Check whether a version is currently pinned.
	 */
	public function is_pinned(): bool {
		return null !== $this->get_pinned_version();
	}
}
