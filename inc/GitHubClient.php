<?php

declare( strict_types=1 );

namespace ExamplePress\ThemeUpdate;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub API client for fetching update manifests and releases.
 *
 * Handles all HTTP communication with GitHub, including manifest
 * fetching, release listing, and package URL resolution.
 */
final class GitHubClient {

	private string $repo;
	private Cache  $cache;

	public function __construct( string $repo, Cache $cache ) {
		$this->repo  = $repo;
		$this->cache = $cache;
	}

	/**
	 * No hooks — this class is called by other classes.
	 */
	public function register_hooks(): void {}

	// -----------------------------------------------------------------
	//  Manifest
	// -----------------------------------------------------------------

	/**
	 * Get the updates.json manifest for the given channel.
	 *
	 * Returns a cached result when available. On cache miss, fetches
	 * from GitHub, validates, and caches the result.
	 *
	 * When the direct manifest URL fails (e.g. no stable release exists),
	 * falls back to searching the releases list for the best matching
	 * release's updates.json asset.
	 *
	 * @return array<string, mixed>|null Parsed manifest or null on failure.
	 */
	public function get_manifest( string $channel ): ?array {
		$cached = $this->cache->get_manifest();

		if ( null !== $cached ) {
			return $cached;
		}

		$manifest_url = $this->build_manifest_url( $channel );
		$body         = $this->remote_get( $manifest_url );

		// If the direct URL failed, fall back to the releases list.
		if ( null === $body ) {
			$this->log( sprintf( 'Direct manifest URL failed for channel "%s", falling back to releases list.', $channel ) );
			$data = $this->resolve_manifest_from_releases( $channel );

			if ( $data ) {
				$this->cache->set_manifest( $data );
				$this->cache->set_last_checked();
				return $data;
			}

			$this->cache->set_manifest_error();
			return null;
		}

		$data = json_decode( $body, true );

		if ( ! $this->validate_manifest( $data ) ) {
			// Try the releases fallback before giving up.
			$data = $this->resolve_manifest_from_releases( $channel );

			if ( $data ) {
				$this->cache->set_manifest( $data );
				$this->cache->set_last_checked();
				return $data;
			}

			$this->cache->set_manifest_error();
			return null;
		}

		$this->cache->set_manifest( $data );
		$this->cache->set_last_checked();

		return $data;
	}

	/**
	 * Fetch the manifest for a specific version by finding its release
	 * and downloading the updates.json asset.
	 *
	 * Used for pinned-version installs. Not cached — called only on
	 * explicit user action.
	 */
	public function get_manifest_for_version( string $version ): ?array {
		$releases = $this->get_releases();

		if ( null === $releases ) {
			return null;
		}

		foreach ( $releases as $release ) {
			$tag = $release['tag_name'] ?? '';

			// Extract version from tag (strip leading v and suffixes).
			$tag_version = $this->extract_version_from_tag( $tag );

			if ( $tag_version !== $version ) {
				continue;
			}

			// Find the updates.json asset in this release.
			foreach ( $release['assets'] ?? [] as $asset ) {
				if ( 'updates.json' === ( $asset['name'] ?? '' ) ) {
					$body = $this->remote_get( $asset['browser_download_url'] );

					if ( null === $body ) {
						return null;
					}

					$data = json_decode( $body, true );

					if ( is_array( $data ) && ! empty( $data['version'] ) ) {
						return $data;
					}

					return null;
				}
			}

			// Predictable fallback URL.
			$fallback_url = sprintf(
				'https://github.com/%s/releases/download/%s/updates.json',
				$this->repo,
				$tag
			);

			$body = $this->remote_get( $fallback_url );

			if ( null === $body ) {
				return null;
			}

			$data = json_decode( $body, true );

			if ( is_array( $data ) && ! empty( $data['version'] ) ) {
				return $data;
			}

			return null;
		}

		return null;
	}

	// -----------------------------------------------------------------
	//  Releases
	// -----------------------------------------------------------------

	/**
	 * Get the list of GitHub releases, cached with a shorter TTL.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public function get_releases(): ?array {
		$cached = $this->cache->get_releases();

		if ( null !== $cached ) {
			return $cached;
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/releases',
			$this->repo
		);

		$body = $this->remote_get( $api_url, [
			'headers' => [
				'Accept' => 'application/vnd.github+json',
			],
		] );

		if ( null === $body ) {
			return null;
		}

		$releases = json_decode( $body, true );

		if ( ! is_array( $releases ) ) {
			return null;
		}

		$this->cache->set_releases( $releases );

		return $releases;
	}

	// -----------------------------------------------------------------
	//  Package URL resolution
	// -----------------------------------------------------------------

	/**
	 * Resolve the download URL from the manifest's packages array.
	 *
	 * Prefers the given variant (default "full"). Falls back to the
	 * first available package. Also supports a flat download_url key.
	 *
	 * @param  array<string, mixed> $manifest
	 * @return string|null
	 */
	public function resolve_package_url( array $manifest, string $variant = 'full' ): ?string {
		if ( ! empty( $manifest['download_url'] ) ) {
			return $manifest['download_url'];
		}

		$packages = $manifest['packages'] ?? [];

		if ( empty( $packages ) ) {
			return null;
		}

		/** @var string $preferred */
		$preferred = apply_filters( 'examplepress_update_variant', $variant );

		foreach ( $packages as $pkg ) {
			if ( ( $pkg['variant'] ?? '' ) === $preferred && ! empty( $pkg['package'] ) ) {
				return $pkg['package'];
			}
		}

		foreach ( $packages as $pkg ) {
			if ( ! empty( $pkg['package'] ) ) {
				return $pkg['package'];
			}
		}

		return null;
	}

	// -----------------------------------------------------------------
	//  URL builders
	// -----------------------------------------------------------------

	/**
	 * Get the public GitHub repo URL.
	 */
	public function get_repo_url(): string {
		return 'https://github.com/' . $this->repo;
	}

	/**
	 * Extract a semver version string from a GitHub tag name.
	 *
	 * Strips leading "v" and trailing branch/sha suffixes.
	 * E.g. "v1.0.4-development-abc1234" → "1.0.4"
	 */
	public function extract_version_from_tag( string $tag ): string {
		$tag = ltrim( $tag, 'v' );

		if ( preg_match( '/^(\d+\.\d+\.\d+)/', $tag, $m ) ) {
			return $m[1];
		}

		return $tag;
	}

	// -----------------------------------------------------------------
	//  Private helpers
	// -----------------------------------------------------------------

	/**
	 * Validate that a decoded manifest array has the required fields.
	 */
	private function validate_manifest( $data ): bool {
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			$this->log( 'Manifest JSON is invalid or missing required version field.' );
			return false;
		}

		$has_download = ! empty( $data['download_url'] );
		$has_packages = ! empty( $data['packages'] ) && is_array( $data['packages'] );

		if ( ! $has_download && ! $has_packages ) {
			$this->log( 'Manifest has no download_url and no packages array.' );
			return false;
		}

		return true;
	}

	/**
	 * Fallback: find the best release for the given channel from the
	 * releases list and fetch its updates.json asset.
	 *
	 * For "stable": finds the first non-prerelease release.
	 * For "development": finds the first prerelease with "development" in the tag.
	 * As a last resort: uses the very first release regardless of type.
	 *
	 * @return array<string, mixed>|null Parsed manifest or null.
	 */
	private function resolve_manifest_from_releases( string $channel ): ?array {
		$releases = $this->get_releases();

		if ( null === $releases || empty( $releases ) ) {
			$this->log( 'No releases found for manifest fallback.' );
			return null;
		}

		$target_release = null;

		if ( 'stable' === $channel ) {
			// Prefer non-prerelease.
			foreach ( $releases as $release ) {
				if ( empty( $release['prerelease'] ) && ! empty( $release['tag_name'] ) ) {
					$target_release = $release;
					break;
				}
			}
		} else {
			// Development: prefer prerelease with "development" in tag.
			foreach ( $releases as $release ) {
				if ( ! empty( $release['tag_name'] ) && false !== strpos( $release['tag_name'], 'development' ) ) {
					$target_release = $release;
					break;
				}
			}
		}

		// Last resort: use the first release with a tag.
		if ( ! $target_release ) {
			foreach ( $releases as $release ) {
				if ( ! empty( $release['tag_name'] ) ) {
					$target_release = $release;
					break;
				}
			}
		}

		if ( ! $target_release ) {
			$this->log( 'No suitable release found for manifest fallback.' );
			return null;
		}

		return $this->fetch_manifest_from_release( $target_release );
	}

	/**
	 * Fetch and parse the updates.json asset from a single release.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetch_manifest_from_release( array $release ): ?array {
		$tag = $release['tag_name'];

		// Try the named asset first.
		foreach ( $release['assets'] ?? [] as $asset ) {
			if ( 'updates.json' === ( $asset['name'] ?? '' ) ) {
				$body = $this->remote_get( $asset['browser_download_url'] );

				if ( null !== $body ) {
					$data = json_decode( $body, true );

					if ( $this->validate_manifest( $data ) ) {
						return $data;
					}
				}

				break;
			}
		}

		// Predictable fallback URL.
		$fallback_url = sprintf(
			'https://github.com/%s/releases/download/%s/updates.json',
			$this->repo,
			$tag
		);

		$body = $this->remote_get( $fallback_url );

		if ( null === $body ) {
			$this->log( sprintf( 'No updates.json found for release %s.', $tag ) );
			return null;
		}

		$data = json_decode( $body, true );

		if ( ! $this->validate_manifest( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Build the URL to the updates.json release asset.
	 *
	 * For stable: /releases/latest/download/updates.json
	 * For development: resolved from the releases list.
	 */
	private function build_manifest_url( string $channel ): string {
		/**
		 * Filter the updates.json manifest URL.
		 *
		 * @param string $url     Empty string = use default.
		 * @param string $channel The active channel.
		 */
		$override = apply_filters( 'examplepress_update_manifest_url', '', $channel );

		if ( ! empty( $override ) ) {
			return $override;
		}

		if ( 'stable' === $channel ) {
			return sprintf(
				'https://github.com/%s/releases/latest/download/updates.json',
				$this->repo
			);
		}

		// Development channel: find the URL from the releases list.
		return $this->resolve_prerelease_manifest_url();
	}

	/**
	 * Find the updates.json asset URL for the latest development release.
	 *
	 * Reuses the cached releases list from get_releases() instead of
	 * making a duplicate API call.
	 */
	private function resolve_prerelease_manifest_url(): string {
		$fallback = sprintf(
			'https://github.com/%s/releases/latest/download/updates.json',
			$this->repo
		);

		$releases = $this->get_releases();

		if ( null === $releases ) {
			return $fallback;
		}

		foreach ( $releases as $release ) {
			if ( empty( $release['tag_name'] ) ) {
				continue;
			}

			if ( false === strpos( $release['tag_name'], 'development' ) ) {
				continue;
			}

			foreach ( $release['assets'] ?? [] as $asset ) {
				if ( 'updates.json' === ( $asset['name'] ?? '' ) ) {
					return $asset['browser_download_url'];
				}
			}

			return sprintf(
				'https://github.com/%s/releases/download/%s/updates.json',
				$this->repo,
				$release['tag_name']
			);
		}

		return $fallback;
	}

	/**
	 * Perform an HTTP GET request with standard defaults.
	 *
	 * @return string|null Response body on success, null on failure.
	 */
	private function remote_get( string $url, array $args = [] ): ?string {
		$version = defined( 'EP_THEME_UPDATE_VERSION' ) ? EP_THEME_UPDATE_VERSION : '2.0.0';

		$defaults = [
			'timeout'    => 15,
			'user-agent' => 'ExamplePress-Theme-Updater/' . $version . ' (+' . home_url() . ')',
			'headers'    => [
				'Accept' => 'application/json',
			],
		];

		// Merge headers separately so they don't get overwritten entirely.
		if ( isset( $args['headers'] ) ) {
			$args['headers'] = array_merge( $defaults['headers'], $args['headers'] );
		}

		$merged   = array_merge( $defaults, $args );
		$response = wp_remote_get( $url, $merged );

		if ( is_wp_error( $response ) ) {
			$this->log( 'HTTP request failed: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->log( sprintf( 'HTTP %d from %s', $code, $url ) );
			return null;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Log a debug message when WP_DEBUG is enabled.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ExamplePress Theme Updater] ' . $message );
		}
	}
}
