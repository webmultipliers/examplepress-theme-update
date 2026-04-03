<?php
/**
 * Plugin Name:  ExamplePress Theme Update
 * Plugin URI:   https://github.com/webmultipliers/examplepress-theme-update
 * Description:  Persistent update manager for the ExamplePress theme. Checks for new versions via GitHub Releases and injects them into the WordPress native updater. This plugin cannot be deactivated while ExamplePress is the active theme.
 * Version:      1.0.1
 * Author:       Web Multipliers
 * Author URI:   https://vinnysgreen.com
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * ExamplePress_Theme_Updater
 *
 * Single-class plugin responsible for:
 *  1. Fetching the updates.json manifest from the theme's GitHub Releases.
 *  2. Injecting update payloads into WP's site_transient_update_themes.
 *  3. Rewriting the download URL so WP can pull the zip from GitHub.
 *  4. Preventing its own deactivation/deletion while the EP theme is active.
 *  5. Clearing update caches on theme switch so stale data never persists.
 *
 * The updates.json schema (produced by the shared ExamplePress release workflow):
 *
 *   {
 *     "version": "1.0.4",
 *     "slug":    "examplepress-theme",
 *     "packages": [
 *       {
 *         "variant":  "full",
 *         "package":  "https://github.com/.../examplepress-theme.zip",
 *         "checksum": "sha256...",
 *         "size":     123456
 *       }
 *     ]
 *   }
 */
final class ExamplePress_Theme_Updater {

	/**
	 * Plugin version — used for cache-busting and future self-update logic.
	 */
	const VERSION = '1.0.0';

	/**
	 * Theme directory slug as it exists in wp-content/themes/.
	 */
	const THEME_SLUG = 'examplepress-theme';

	/**
	 * GitHub owner/repo for the theme releases.
	 */
	const GITHUB_REPO = 'webmultipliers/examplepress-theme';

	/**
	 * Transient key for the cached remote manifest.
	 */
	const TRANSIENT_KEY = 'ep_theme_update_manifest';

	/**
	 * How often to re-check for updates (in seconds). Default: 6 hours.
	 */
	const CHECK_INTERVAL = 6 * HOUR_IN_SECONDS;

	/**
	 * The update channel. Matches the branch suffix in the release tag.
	 * Supported: 'stable' (main branch), 'development'.
	 */
	private string $channel;

	/**
	 * Singleton instance.
	 */
	private static ?self $instance = null;

	/**
	 * Boot the updater. Called once at plugin load.
	 */
	public static function init(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	/**
	 * Wire up all hooks.
	 */
	private function __construct() {
		$this->channel = $this->resolve_channel();

		// ── Core update pipeline ──────────────────────────────────────
		add_filter( 'pre_set_site_transient_update_themes', [ $this, 'inject_update' ] );
		add_filter( 'themes_api',                           [ $this, 'theme_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection',            [ $this, 'fix_source_dir' ], 10, 4 );

		// ── Cache management ──────────────────────────────────────────
		add_action( 'switch_theme',              [ $this, 'flush_cache' ] );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache_after_upgrade' ], 10, 2 );

		// ── Self-protection while EP theme is active ──────────────────
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'lock_action_links' ] );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'lock_action_links' ] );
		add_action( 'deactivate_' . plugin_basename( __FILE__ ), [ $this, 'block_deactivation' ] );

		// ── Admin notice when EP theme is not active ──────────────────
		add_action( 'admin_notices', [ $this, 'maybe_show_inactive_notice' ] );

		// ── Manual check trigger via query param ──────────────────────
		add_action( 'admin_init', [ $this, 'maybe_force_check' ] );
	}

	// =====================================================================
	//  UPDATE PIPELINE
	// =====================================================================

	/**
	 * Inject the ExamplePress theme update into WP's update transient.
	 *
	 * Runs on `pre_set_site_transient_update_themes`. If a newer version
	 * is available, we add it to $transient->response so WP shows the
	 * native "Update Available" notice and handles the upgrade.
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$manifest = $this->get_remote_manifest();

		if ( ! $manifest ) {
			return $transient;
		}

		$local_version = $this->get_local_version();

		if ( ! $local_version ) {
			return $transient;
		}

		$remote_version = $manifest['version'];
		$download_url   = $this->resolve_package_url( $manifest );

		if ( ! $download_url ) {
			$this->log( 'No suitable package found in manifest.' );
			return $transient;
		}

		if ( version_compare( $remote_version, $local_version, '>' ) ) {
			$transient->response[ self::THEME_SLUG ] = [
				'theme'        => self::THEME_SLUG,
				'new_version'  => $remote_version,
				'url'          => $manifest['details_url'] ?? $this->get_repo_url(),
				'package'      => $download_url,
				'requires'     => $manifest['requires'] ?? '',
				'requires_php' => $manifest['requires_php'] ?? '8.0',
			];
		} else {
			// No update available — ensure any stale response entry is cleared.
			unset( $transient->response[ self::THEME_SLUG ] );

			if ( ! isset( $transient->checked ) ) {
				$transient->checked = [];
			}

			$transient->checked[ self::THEME_SLUG ] = $local_version;
		}

		return $transient;
	}

	/**
	 * Supply theme information for the "View Details" modal in wp-admin.
	 *
	 * Hooks into `themes_api` so WP can display version info, changelog,
	 * requirements, etc. when the user clicks the update details link.
	 */
	public function theme_info( $result, string $action, object $args ) {
		if ( 'theme_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::THEME_SLUG !== $args->slug ) {
			return $result;
		}

		$manifest = $this->get_remote_manifest();

		if ( ! $manifest ) {
			return $result;
		}

		$download_url = $this->resolve_package_url( $manifest );

		return (object) [
			'name'          => $manifest['name'] ?? 'ExamplePress',
			'slug'          => self::THEME_SLUG,
			'version'       => $manifest['version'],
			'author'        => $manifest['author'] ?? 'Web Multipliers',
			'homepage'      => $manifest['homepage'] ?? $this->get_repo_url(),
			'download_link' => $download_url,
			'requires'      => $manifest['requires'] ?? '',
			'requires_php'  => $manifest['requires_php'] ?? '8.0',
			'tested'        => $manifest['tested'] ?? '',
			'last_updated'  => $manifest['last_updated'] ?? '',
			'sections'      => [
				'description' => $manifest['description'] ?? 'A code-first WordPress theme built on Blockstudio.',
				'changelog'   => $manifest['changelog'] ?? '<p>See the <a href="' . esc_url( $this->get_repo_url() . '/releases' ) . '">GitHub Releases</a> page.</p>',
			],
		];
	}

	/**
	 * Fix the extracted directory name after WP downloads the GitHub zip.
	 *
	 * GitHub release zips extract to `examplepress-theme-1.0.4/` or similar.
	 * WordPress expects the folder to match the theme slug exactly. This filter
	 * renames it before the upgrader moves it into place.
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

	// =====================================================================
	//  REMOTE MANIFEST
	// =====================================================================

	/**
	 * Resolve the download URL from the manifest's packages array.
	 *
	 * Prefers the "full" variant. Falls back to the first available package.
	 * Also supports a flat `download_url` key for simple manifests.
	 *
	 * @param  array<string, mixed> $manifest
	 * @return string|null
	 */
	private function resolve_package_url( array $manifest ): ?string {
		// Flat key — simple manifest format.
		if ( ! empty( $manifest['download_url'] ) ) {
			return $manifest['download_url'];
		}

		$packages = $manifest['packages'] ?? [];

		if ( empty( $packages ) ) {
			return null;
		}

		/**
		 * Filter the preferred variant to download.
		 *
		 * @param string $variant Default 'full'.
		 */
		$preferred = apply_filters( 'examplepress_update_variant', 'full' );

		// Look for the preferred variant first.
		foreach ( $packages as $pkg ) {
			if ( ( $pkg['variant'] ?? '' ) === $preferred && ! empty( $pkg['package'] ) ) {
				return $pkg['package'];
			}
		}

		// Fall back to the first package with a URL.
		foreach ( $packages as $pkg ) {
			if ( ! empty( $pkg['package'] ) ) {
				return $pkg['package'];
			}
		}

		return null;
	}

	/**
	 * Get the remote updates.json manifest, cached via a transient.
	 *
	 * Returns an associative array with at minimum:
	 *   - version  (string) e.g. "1.0.4"
	 *   - packages (array)  at least one entry with a `package` URL,
	 *             OR a flat `download_url` string.
	 *
	 * Returns null on failure or if the manifest is unparseable.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_remote_manifest(): ?array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false !== $cached && is_array( $cached ) ) {
			// An empty array is our sentinel for a recently-failed fetch.
			return ! empty( $cached ) ? $cached : null;
		}

		$manifest_url = $this->build_manifest_url();

		$response = wp_remote_get( $manifest_url, [
			'timeout'    => 15,
			'user-agent' => 'ExamplePress-Theme-Updater/' . self::VERSION . ' (+' . home_url() . ')',
			'headers'    => [
				'Accept' => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Manifest fetch failed: ' . $response->get_error_message() );
			set_transient( self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->log( sprintf( 'Manifest returned HTTP %d from %s', $code, $manifest_url ) );
			set_transient( self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			$this->log( 'Manifest JSON is invalid or missing required version field.' );
			set_transient( self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS );
			return null;
		}

		// Validate that at least one download path exists.
		$has_download = ! empty( $data['download_url'] );
		$has_packages = ! empty( $data['packages'] ) && is_array( $data['packages'] );

		if ( ! $has_download && ! $has_packages ) {
			$this->log( 'Manifest has no download_url and no packages array.' );
			set_transient( self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( self::TRANSIENT_KEY, $data, self::CHECK_INTERVAL );

		return $data;
	}

	/**
	 * Build the URL to the updates.json release asset.
	 *
	 * The theme's GitHub Actions workflow attaches updates.json to every
	 * release. For the stable channel we use /releases/latest/download/;
	 * for development we must resolve the latest pre-release tag via the
	 * GitHub API because /latest/ only returns non-prerelease tags.
	 */
	private function build_manifest_url(): string {
		/**
		 * Filter the updates.json manifest URL.
		 *
		 * Allows overriding to point at a custom update server, local file,
		 * or alternative branch channel.
		 *
		 * @param string $url     The manifest URL (empty = use default).
		 * @param string $channel The active channel ('stable' or 'development').
		 */
		$override = apply_filters( 'examplepress_update_manifest_url', '', $this->channel );

		if ( ! empty( $override ) ) {
			return $override;
		}

		if ( 'stable' === $this->channel ) {
			return sprintf(
				'https://github.com/%s/releases/latest/download/updates.json',
				self::GITHUB_REPO
			);
		}

		// Development channel: resolve the latest pre-release via the API.
		return $this->resolve_prerelease_manifest_url();
	}

	/**
	 * For the development channel, query GitHub's Releases API to find
	 * the latest pre-release and return its updates.json asset URL.
	 *
	 * Falls back to the /latest/ URL if the API call fails.
	 */
	private function resolve_prerelease_manifest_url(): string {
		$fallback = sprintf(
			'https://github.com/%s/releases/latest/download/updates.json',
			self::GITHUB_REPO
		);

		$api_url = sprintf(
			'https://api.github.com/repos/%s/releases',
			self::GITHUB_REPO
		);

		$response = wp_remote_get( $api_url, [
			'timeout'    => 10,
			'user-agent' => 'ExamplePress-Theme-Updater/' . self::VERSION,
			'headers'    => [
				'Accept' => 'application/vnd.github+json',
			],
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $fallback;
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $releases ) ) {
			return $fallback;
		}

		// Find the first release tagged with "development" in its tag name.
		foreach ( $releases as $release ) {
			if ( empty( $release['tag_name'] ) ) {
				continue;
			}

			if ( false === strpos( $release['tag_name'], 'development' ) ) {
				continue;
			}

			// Look for the updates.json asset.
			foreach ( $release['assets'] ?? [] as $asset ) {
				if ( 'updates.json' === ( $asset['name'] ?? '' ) ) {
					return $asset['browser_download_url'];
				}
			}

			// Predictable fallback URL if asset listing is incomplete.
			return sprintf(
				'https://github.com/%s/releases/download/%s/updates.json',
				self::GITHUB_REPO,
				$release['tag_name']
			);
		}

		return $fallback;
	}

	// =====================================================================
	//  CHANNEL RESOLUTION
	// =====================================================================

	/**
	 * Determine the active update channel.
	 *
	 * Priority order:
	 *  1. `examplepress_update_channel` filter
	 *  2. `EP_UPDATE_CHANNEL` constant (wp-config.php)
	 *  3. Auto-detect from the installed theme version string
	 *     (versions containing "dev", "alpha", "beta", "rc" → development)
	 *  4. Default: 'stable'
	 */
	private function resolve_channel(): string {
		$channel = apply_filters( 'examplepress_update_channel', '' );

		if ( in_array( $channel, [ 'stable', 'development' ], true ) ) {
			return $channel;
		}

		if ( defined( 'EP_UPDATE_CHANNEL' ) && in_array( EP_UPDATE_CHANNEL, [ 'stable', 'development' ], true ) ) {
			return EP_UPDATE_CHANNEL;
		}

		// Auto-detect from installed theme version.
		$local = $this->get_local_version();

		if ( $local && preg_match( '/(dev|alpha|beta|rc)/i', $local ) ) {
			return 'development';
		}

		return 'stable';
	}

	// =====================================================================
	//  LOCAL VERSION
	// =====================================================================

	/**
	 * Get the currently installed ExamplePress theme version.
	 *
	 * Reads from the theme's style.css header. Returns null if the theme
	 * is not installed.
	 */
	private function get_local_version(): ?string {
		$theme = wp_get_theme( self::THEME_SLUG );

		if ( ! $theme->exists() ) {
			return null;
		}

		$version = $theme->get( 'Version' );

		return $version ?: null;
	}

	// =====================================================================
	//  CACHE MANAGEMENT
	// =====================================================================

	/**
	 * Flush the cached manifest transient.
	 */
	public function flush_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Flush cache after a theme upgrade completes.
	 */
	public function flush_cache_after_upgrade( \WP_Upgrader $upgrader, array $options ): void {
		if ( 'update' !== ( $options['action'] ?? '' ) || 'theme' !== ( $options['type'] ?? '' ) ) {
			return;
		}

		$themes = $options['themes'] ?? [];

		if ( in_array( self::THEME_SLUG, $themes, true ) ) {
			$this->flush_cache();
		}
	}

	/**
	 * Allow manual cache flush via `?ep_force_update_check=1` in wp-admin.
	 */
	public function maybe_force_check(): void {
		if ( ! current_user_can( 'update_themes' ) ) {
			return;
		}

		if ( empty( $_GET['ep_force_update_check'] ) ) {
			return;
		}

		check_admin_referer( 'ep_force_update_check' );

		$this->flush_cache();
		delete_site_transient( 'update_themes' );

		wp_safe_redirect( remove_query_arg( [ 'ep_force_update_check', '_wpnonce' ] ) );
		exit;
	}

	// =====================================================================
	//  SELF-PROTECTION
	// =====================================================================

	/**
	 * Remove the "Deactivate" and "Delete" links from the plugins list
	 * while the ExamplePress theme is active.
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
	 * Prevent programmatic deactivation while the EP theme is active.
	 *
	 * Hooked on `deactivate_{plugin}`. Calls wp_die() to abort.
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

	// =====================================================================
	//  ADMIN NOTICES
	// =====================================================================

	/**
	 * Show a notice if this plugin is active but the EP theme is not.
	 *
	 * This shouldn't normally happen (the theme installs/activates this plugin),
	 * but if someone manually activates it on a non-EP site, let them know.
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

	// =====================================================================
	//  HELPERS
	// =====================================================================

	/**
	 * Check whether the ExamplePress theme (or a child of it) is active.
	 */
	private function is_ep_theme_active(): bool {
		$active   = get_option( 'stylesheet' );
		$template = get_option( 'template' );

		return self::THEME_SLUG === $active || self::THEME_SLUG === $template;
	}

	/**
	 * Get the public GitHub repo URL.
	 */
	private function get_repo_url(): string {
		return 'https://github.com/' . self::GITHUB_REPO;
	}

	/**
	 * Log a message to the PHP error log (debug only).
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ExamplePress Theme Updater] ' . $message );
		}
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		throw new \LogicException( 'Cannot unserialize singleton.' );
	}
}

// Boot.
ExamplePress_Theme_Updater::init();
