<?php
/**
 * Admin page template for ExamplePress Updates.
 *
 * This is a shell — all dynamic content is populated by assets/js/admin.js
 * via REST API calls on page load.
 *
 * @package ExamplePress\ThemeUpdate
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'ExamplePress Updates', 'examplepress-theme-update' ); ?></h1>

	<div id="ep-theme-updates">

		<!-- Notices container — populated by JS -->
		<div class="ep-notices"></div>

		<!-- Status Card -->
		<div class="ep-card ep-status-card">
			<h2><?php esc_html_e( 'Theme Status', 'examplepress-theme-update' ); ?></h2>
			<div class="ep-status-content ep-loading">
				<div class="ep-placeholder-lines">
					<span></span>
					<span></span>
					<span></span>
				</div>
			</div>
		</div>

		<!-- Channel Selector -->
		<div class="ep-card ep-channel-card">
			<h2><?php esc_html_e( 'Update Channel', 'examplepress-theme-update' ); ?></h2>
			<div class="ep-channel-content ep-loading">
				<div class="ep-placeholder-lines">
					<span></span>
					<span></span>
				</div>
			</div>
		</div>

		<!-- Version Pinning -->
		<div class="ep-card ep-pin-card">
			<h2><?php esc_html_e( 'Version Pinning', 'examplepress-theme-update' ); ?></h2>
			<div class="ep-pin-content ep-loading">
				<div class="ep-placeholder-lines">
					<span></span>
					<span></span>
				</div>
			</div>
		</div>

		<!-- Actions -->
		<div class="ep-card ep-actions-card">
			<h2><?php esc_html_e( 'Actions', 'examplepress-theme-update' ); ?></h2>
			<div class="ep-actions-content">
				<button type="button" class="button" id="ep-check-updates" disabled>
					<?php esc_html_e( 'Check for Updates', 'examplepress-theme-update' ); ?>
				</button>
				<button type="button" class="button button-primary" id="ep-install-update" disabled>
					<?php esc_html_e( 'Update Theme', 'examplepress-theme-update' ); ?>
				</button>
				<span class="spinner" id="ep-action-spinner"></span>
				<div class="ep-progress" id="ep-progress" hidden>
					<div class="ep-progress-bar"></div>
					<p class="ep-progress-message"></p>
				</div>
			</div>
		</div>

		<!-- Release History -->
		<div class="ep-card ep-releases-card">
			<h2><?php esc_html_e( 'Release History', 'examplepress-theme-update' ); ?></h2>
			<div class="ep-releases-content ep-loading">
				<div class="ep-placeholder-lines">
					<span></span>
					<span></span>
					<span></span>
					<span></span>
				</div>
			</div>
		</div>

	</div>
</div>
