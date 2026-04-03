<?php

declare( strict_types=1 );

namespace ExamplePress\ThemeUpdate;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin orchestrator.
 *
 * Instantiates all classes with correct dependencies and calls
 * register_hooks() on each. Called once from the entry file.
 */
final class Plugin {

	private static bool $booted = false;

	/**
	 * Boot the plugin. Guarded against double-init.
	 */
	public static function init( string $plugin_file ): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		$cache            = new Cache();
		$github           = new GitHubClient( 'webmultipliers/examplepress-theme', $cache );
		$channel_resolver = new ChannelResolver();
		$updater          = new Updater( $github, $channel_resolver );
		$protection       = new Protection( plugin_basename( $plugin_file ) );
		$admin_page       = new AdminPage( $updater );
		$rest             = new RestController( $updater, $github, $channel_resolver, $cache );

		$cache->register_hooks();
		$github->register_hooks();
		$channel_resolver->register_hooks();
		$updater->register_hooks();
		$protection->register_hooks();
		$admin_page->register_hooks();
		$rest->register_hooks();
	}
}
