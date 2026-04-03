<?php

declare( strict_types=1 );

namespace ExamplePress\ThemeUpdate;

defined( 'ABSPATH' ) || exit;

/**
 * Core WordPress update pipeline.
 *
 * Hooks into the native theme update system to inject ExamplePress
 * theme updates from GitHub Releases. Handles version pinning,
 * theme info modals, and source directory renaming.
 */
final class Updater {

	public const THEME_SLUG = 'examplepress-theme';

	private GitHubClient    $github;
	private ChannelResolver $channel_resolver;
	private Cache           $cache;

	/**
	 * When true, inject_update() passes through without modifying
	 * the transient. Set during reinstall to prevent the filter from
	 * removing the injected fake-version entry.
	 */
	private bool $bypass_inject = false;

	public function __construct( GitHubClient $github, ChannelResolver $channel_resolver, Cache $cache ) {
		$this->github           = $github;
		$this->channel_resolver = $channel_resolver;
		$this->cache            = $cache;
	}

	/**
	 * Register the core update pipeline filters.
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_themes', [ $this, 'inject_update' ] );
		add_filter( 'themes_api', [ $this, 'theme_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
	}

	// -----------------------------------------------------------------
	//  Status
	// -----------------------------------------------------------------

	/**
	 * Build a structured status array for REST and admin page.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$channel       = $this->channel_resolver->resolve();
		$local_version = $this->get_local_version();
		$manifest      = $this->github->get_manifest( $channel );
		$download_url  = $manifest ? $this->github->resolve_package_url( $manifest ) : null;
		$pinned        = $this->channel_resolver->get_pinned_version();

		$latest_version   = $manifest['version'] ?? null;
		$update_available = false;

		if ( $local_version && $latest_version ) {
			if ( $pinned ) {
				// Pinning logic: only offer update to pinned version.
				$update_available = version_compare( $pinned, $local_version, '>' );
			} else {
				$update_available = version_compare( $latest_version, $local_version, '>' );
			}
		}

		return [
			'current_version'  => $local_version,
			'latest_version'   => $latest_version,
			'update_available' => $update_available,
			'download_url'     => $download_url,
			'channel'          => $channel,
			'channel_source'   => $this->channel_resolver->get_channel_source(),
			'pinned_version'   => $pinned,
			'last_checked'     => $this->cache->get_last_checked(),
			'theme_active'     => $this->is_ep_theme_active(),
		];
	}

	// -----------------------------------------------------------------
	//  Update injection
	// -----------------------------------------------------------------

	/**
	 * Inject the ExamplePress theme update into WP's update transient.
	 *
	 * Respects version pinning: when pinned, only the pinned version
	 * is offered (if it's newer than the installed version).
	 *
	 * @param  mixed $transient
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		// During reinstall, pass through without touching the transient
		// so the injected fake-version entry survives.
		if ( $this->bypass_inject ) {
			return $transient;
		}

		$local_version = $this->get_local_version();

		if ( ! $local_version ) {
			return $transient;
		}

		$channel = $this->channel_resolver->resolve();
		$pinned  = $this->channel_resolver->get_pinned_version();

		// Pinned version logic.
		if ( $pinned ) {
			return $this->inject_pinned_update( $transient, $local_version, $pinned );
		}

		// Standard (unpinned) logic.
		$manifest = $this->github->get_manifest( $channel );

		if ( ! $manifest ) {
			return $transient;
		}

		$remote_version = $manifest['version'];
		$download_url   = $this->github->resolve_package_url( $manifest );

		if ( ! $download_url ) {
			return $transient;
		}

		if ( version_compare( $remote_version, $local_version, '>' ) ) {
			$transient->response[ self::THEME_SLUG ] = [
				'theme'        => self::THEME_SLUG,
				'new_version'  => $remote_version,
				'url'          => $manifest['details_url'] ?? $this->github->get_repo_url(),
				'package'      => $download_url,
				'requires'     => $manifest['requires'] ?? '',
				'requires_php' => $manifest['requires_php'] ?? '8.0',
			];
		} else {
			unset( $transient->response[ self::THEME_SLUG ] );

			if ( ! isset( $transient->checked ) ) {
				$transient->checked = [];
			}

			$transient->checked[ self::THEME_SLUG ] = $local_version;
		}

		return $transient;
	}

	/**
	 * Handle update injection when a version is pinned.
	 *
	 * @param  object $transient
	 * @param  string $local_version
	 * @param  string $pinned
	 * @return object
	 */
	private function inject_pinned_update( object $transient, string $local_version, string $pinned ): object {
		// Already on the pinned version — no update.
		if ( version_compare( $pinned, $local_version, '==' ) ) {
			unset( $transient->response[ self::THEME_SLUG ] );

			if ( ! isset( $transient->checked ) ) {
				$transient->checked = [];
			}

			$transient->checked[ self::THEME_SLUG ] = $local_version;

			return $transient;
		}

		// Pinned version is newer — offer it.
		if ( version_compare( $pinned, $local_version, '>' ) ) {
			$manifest = $this->github->get_manifest_for_version( $pinned );

			if ( $manifest ) {
				$download_url = $this->github->resolve_package_url( $manifest );

				if ( $download_url ) {
					$transient->response[ self::THEME_SLUG ] = [
						'theme'        => self::THEME_SLUG,
						'new_version'  => $pinned,
						'url'          => $manifest['details_url'] ?? $this->github->get_repo_url(),
						'package'      => $download_url,
						'requires'     => $manifest['requires'] ?? '',
						'requires_php' => $manifest['requires_php'] ?? '8.0',
					];

					return $transient;
				}
			}
		}

		// Pinned version is older or manifest not found — no update (no downgrade).
		unset( $transient->response[ self::THEME_SLUG ] );

		if ( ! isset( $transient->checked ) ) {
			$transient->checked = [];
		}

		$transient->checked[ self::THEME_SLUG ] = $local_version;

		return $transient;
	}

	// -----------------------------------------------------------------
	//  Theme info modal
	// -----------------------------------------------------------------

	/**
	 * Supply theme information for the "View Details" modal.
	 *
	 * @param  mixed  $result
	 * @param  string $action
	 * @param  object $args
	 * @return mixed
	 */
	public function theme_info( $result, string $action, object $args ) {
		if ( 'theme_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::THEME_SLUG !== $args->slug ) {
			return $result;
		}

		$channel  = $this->channel_resolver->resolve();
		$manifest = $this->github->get_manifest( $channel );

		if ( ! $manifest ) {
			return $result;
		}

		$download_url = $this->github->resolve_package_url( $manifest );

		return (object) [
			'name'          => $manifest['name'] ?? 'ExamplePress',
			'slug'          => self::THEME_SLUG,
			'version'       => $manifest['version'],
			'author'        => $manifest['author'] ?? 'Web Multipliers',
			'homepage'      => $manifest['homepage'] ?? $this->github->get_repo_url(),
			'download_link' => $download_url,
			'requires'      => $manifest['requires'] ?? '',
			'requires_php'  => $manifest['requires_php'] ?? '8.0',
			'tested'        => $manifest['tested'] ?? '',
			'last_updated'  => $manifest['last_updated'] ?? '',
			'sections'      => [
				'description' => $manifest['description'] ?? 'A code-first WordPress theme built on Blockstudio.',
				'changelog'   => $manifest['changelog'] ?? '<p>See the <a href="' . esc_url( $this->github->get_repo_url() . '/releases' ) . '">GitHub Releases</a> page.</p>',
			],
		];
	}

	// -----------------------------------------------------------------
	//  Source directory fix
	// -----------------------------------------------------------------

	/**
	 * Fix the extracted directory name after WP downloads the GitHub zip.
	 *
	 * GitHub release zips extract to `examplepress-theme-1.0.4/` or similar.
	 * WordPress expects the folder to match the theme slug exactly.
	 */
	public function fix_source_dir( string $source, string $remote_source, \WP_Upgrader $upgrader, array $extras ): string {
		if ( ! isset( $extras['theme'] ) || self::THEME_SLUG !== $extras['theme'] ) {
			return $source;
		}

		$expected = trailingslashit( $remote_source ) . self::THEME_SLUG . '/';

		if ( $source === $expected ) {
			return $source;
		}

		global $wp_filesystem;

		if ( $wp_filesystem->move( $source, $expected, true ) ) {
			return $expected;
		}

		return $source;
	}

	// -----------------------------------------------------------------
	//  Install
	// -----------------------------------------------------------------

	/**
	 * Install a specific version (or latest) using WP's Theme_Upgrader.
	 *
	 * @param  string|null $version Specific version to install, or null for latest.
	 * @return array{ success: bool, message: string }
	 */
	public function install_version( ?string $version = null ): array {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

		$channel = $this->channel_resolver->resolve();

		if ( $version ) {
			$manifest = $this->github->get_manifest_for_version( $version );
		} else {
			$manifest = $this->github->get_manifest( $channel );
		}

		if ( ! $manifest ) {
			return [
				'success' => false,
				'message' => 'Could not fetch update manifest.',
			];
		}

		$download_url = $this->github->resolve_package_url( $manifest );

		if ( ! $download_url ) {
			return [
				'success' => false,
				'message' => 'No download URL found in manifest.',
			];
		}

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );

		// Inject the update into the transient so Theme_Upgrader::upgrade() finds it.
		$update_data = [
			'theme'        => self::THEME_SLUG,
			'new_version'  => $manifest['version'],
			'url'          => $manifest['details_url'] ?? $this->github->get_repo_url(),
			'package'      => $download_url,
			'requires'     => $manifest['requires'] ?? '',
			'requires_php' => $manifest['requires_php'] ?? '8.0',
		];

		$transient = get_site_transient( 'update_themes' );

		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$transient->response[ self::THEME_SLUG ] = $update_data;
		set_site_transient( 'update_themes', $transient );

		$result = $upgrader->upgrade( self::THEME_SLUG );

		// Flush cache after install.
		$this->cache->flush();

		if ( is_wp_error( $result ) ) {
			return [
				'success' => false,
				'message' => $result->get_error_message(),
			];
		}

		if ( true !== $result ) {
			$messages = $skin->get_upgrade_messages();
			$last     = end( $messages );

			return [
				'success' => false,
				'message' => $last ?: 'Update failed with an unknown error.',
			];
		}

		return [
			'success' => true,
			'message' => sprintf( 'Updated to version %s.', $manifest['version'] ),
		];
	}

	/**
	 * Reinstall the currently installed version (or a specific version).
	 *
	 * Unlike install_version(), this bypasses Theme_Upgrader::upgrade()
	 * which refuses to act when the version hasn't changed. Instead it
	 * injects a fake "newer" version into the transient to force the
	 * upgrade path, then lets the real package URL install the correct
	 * version.
	 *
	 * @param  string|null $version Version to reinstall, or null for the currently installed version.
	 * @return array{ success: bool, message: string }
	 */
	public function reinstall( ?string $version = null ): array {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

		$local_version = $this->get_local_version();

		if ( ! $local_version ) {
			return [
				'success' => false,
				'message' => 'ExamplePress theme is not installed.',
			];
		}

		$target = $version ?? $local_version;

		$manifest = $this->github->get_manifest_for_version( $target );

		if ( ! $manifest ) {
			// Try the current channel manifest if the version matches.
			$channel  = $this->channel_resolver->resolve();
			$manifest = $this->github->get_manifest( $channel );

			if ( ! $manifest || ( $manifest['version'] ?? '' ) !== $target ) {
				return [
					'success' => false,
					'message' => sprintf( 'Could not fetch manifest for version %s.', $target ),
				];
			}
		}

		$download_url = $this->github->resolve_package_url( $manifest );

		if ( ! $download_url ) {
			return [
				'success' => false,
				'message' => 'No download URL found in manifest.',
			];
		}

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );

		// Bypass inject_update() so it doesn't remove our fake entry
		// when set_site_transient triggers pre_set_site_transient_update_themes.
		$this->bypass_inject = true;

		// Inject a fake "newer" version so Theme_Upgrader::upgrade() proceeds.
		// The actual package URL still downloads the correct version.
		$transient = get_site_transient( 'update_themes' );

		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$transient->response[ self::THEME_SLUG ] = [
			'theme'        => self::THEME_SLUG,
			'new_version'  => $target . '.999',
			'url'          => $manifest['details_url'] ?? $this->github->get_repo_url(),
			'package'      => $download_url,
			'requires'     => $manifest['requires'] ?? '',
			'requires_php' => $manifest['requires_php'] ?? '8.0',
		];

		set_site_transient( 'update_themes', $transient );

		$result = $upgrader->upgrade( self::THEME_SLUG );

		// Restore normal inject_update behavior and clean up.
		$this->bypass_inject = false;
		$this->cache->flush();

		if ( is_wp_error( $result ) ) {
			return [
				'success' => false,
				'message' => $result->get_error_message(),
			];
		}

		if ( true !== $result ) {
			$messages = $skin->get_upgrade_messages();
			$last     = end( $messages );

			return [
				'success' => false,
				'message' => $last ?: 'Reinstall failed with an unknown error.',
			];
		}

		return [
			'success' => true,
			'message' => sprintf( 'Reinstalled version %s.', $target ),
		];
	}

	// -----------------------------------------------------------------
	//  Helpers
	// -----------------------------------------------------------------

	/**
	 * Get the currently installed ExamplePress theme version.
	 */
	public function get_local_version(): ?string {
		$theme = wp_get_theme( self::THEME_SLUG );

		if ( ! $theme->exists() ) {
			return null;
		}

		$version = $theme->get( 'Version' );

		return $version ?: null;
	}

	/**
	 * Check whether the ExamplePress theme (or a child of it) is active.
	 */
	private function is_ep_theme_active(): bool {
		$active   = get_option( 'stylesheet' );
		$template = get_option( 'template' );

		return self::THEME_SLUG === $active || self::THEME_SLUG === $template;
	}
}
