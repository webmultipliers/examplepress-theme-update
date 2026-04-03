<?php

declare( strict_types=1 );

namespace ExamplePress\ThemeUpdate;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for the EP Theme Update admin page.
 *
 * Namespace: ep-theme-update/v1
 *
 * Routes:
 *   GET    /status    — current version, latest, channel, pin, last checked
 *   GET    /releases  — cached GitHub releases list
 *   POST   /check     — flush cache, re-fetch, return fresh status
 *   PUT    /channel   — set channel (stable or development)
 *   PUT    /pin       — set or clear pinned version
 *   POST   /install   — trigger Theme_Upgrader for a version
 */
final class RestController {

	private const NAMESPACE = 'ep-theme-update/v1';

	private Updater         $updater;
	private GitHubClient    $github;
	private ChannelResolver $channel_resolver;
	private Cache           $cache;

	public function __construct(
		Updater $updater,
		GitHubClient $github,
		ChannelResolver $channel_resolver,
		Cache $cache
	) {
		$this->updater          = $updater;
		$this->github           = $github;
		$this->channel_resolver = $channel_resolver;
		$this->cache            = $cache;
	}

	/**
	 * Register REST routes on rest_api_init.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_status' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/releases', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_releases' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/check', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'check_for_updates' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/channel', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_channel' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'channel' => [
					'type'     => 'string',
					'enum'     => [ 'stable', 'development' ],
					'required' => true,
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/pin', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_pin' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'version' => [
					'type'     => [ 'string', 'null' ],
					'required' => true,
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/install', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'install_update' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'version' => [
					'type'     => 'string',
					'required' => false,
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/reinstall', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'reinstall' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'version' => [
					'type'     => 'string',
					'required' => false,
				],
			],
		] );
	}

	/**
	 * Permission check: user must have update_themes capability.
	 */
	public function check_permission(): bool {
		return current_user_can( 'update_themes' );
	}

	// -----------------------------------------------------------------
	//  Handlers
	// -----------------------------------------------------------------

	/**
	 * GET /status — return current update status.
	 */
	public function get_status(): \WP_REST_Response {
		return rest_ensure_response( $this->updater->get_status() );
	}

	/**
	 * GET /releases — return simplified GitHub releases list.
	 */
	public function get_releases(): \WP_REST_Response {
		$releases = $this->github->get_releases();

		if ( null === $releases ) {
			return new \WP_REST_Response( [ 'releases' => [] ], 200 );
		}

		$simplified = array_map( function ( array $release ): array {
			return [
				'tag'        => $release['tag_name'] ?? '',
				'name'       => $release['name'] ?? '',
				'version'    => $this->github->extract_version_from_tag( $release['tag_name'] ?? '' ),
				'prerelease' => $release['prerelease'] ?? false,
				'date'       => $release['published_at'] ?? '',
				'body'       => $release['body'] ?? '',
			];
		}, $releases );

		return rest_ensure_response( [ 'releases' => array_values( $simplified ) ] );
	}

	/**
	 * POST /check — flush cache and return fresh status.
	 */
	public function check_for_updates(): \WP_REST_Response {
		$this->cache->flush();

		return rest_ensure_response( $this->updater->get_status() );
	}

	/**
	 * PUT /channel — set update channel.
	 */
	public function update_channel( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$channel = $request->get_param( 'channel' );
		$success = $this->channel_resolver->set_channel( $channel );

		if ( ! $success ) {
			$source = $this->channel_resolver->get_channel_source();

			return new \WP_Error(
				'ep_channel_locked',
				sprintf(
					'Channel is locked by a %s and cannot be changed via the admin UI.',
					$source
				),
				[ 'status' => 409 ]
			);
		}

		$this->cache->flush();

		return rest_ensure_response( $this->updater->get_status() );
	}

	/**
	 * PUT /pin — set or clear pinned version.
	 */
	public function update_pin( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$version = $request->get_param( 'version' );

		if ( null !== $version && '' !== $version ) {
			$version = sanitize_text_field( $version );

			// Validate the version exists in the releases list.
			$releases = $this->github->get_releases();

			if ( null !== $releases ) {
				$found = false;

				foreach ( $releases as $release ) {
					$tag_version = $this->github->extract_version_from_tag( $release['tag_name'] ?? '' );

					if ( $tag_version === $version ) {
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					return new \WP_Error(
						'ep_version_not_found',
						sprintf( 'Version %s was not found in the releases list.', $version ),
						[ 'status' => 404 ]
					);
				}
			}
		} else {
			$version = null;
		}

		$this->channel_resolver->set_pinned_version( $version );
		$this->cache->flush();

		return rest_ensure_response( $this->updater->get_status() );
	}

	/**
	 * POST /install — trigger theme update.
	 */
	public function install_update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$version = $request->get_param( 'version' );
		$version = $version ? sanitize_text_field( $version ) : null;

		$result = $this->updater->install_version( $version );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'ep_install_failed',
				$result['message'],
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /reinstall — reinstall the current (or specified) version.
	 *
	 * Forces a fresh install of the same version to reset the theme
	 * directory to a clean state, removing any local modifications.
	 */
	public function reinstall( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$version = $request->get_param( 'version' );
		$version = $version ? sanitize_text_field( $version ) : null;

		$result = $this->updater->reinstall( $version );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'ep_reinstall_failed',
				$result['message'],
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( $result );
	}
}
