/**
 * ExamplePress Theme Updates — Admin Page JS
 *
 * Dependencies: wp-api-fetch, wp-i18n
 * All data fetching and mutations use the ep-theme-update/v1 REST API.
 */
( function () {
	'use strict';

	const { apiFetch } = wp;
	const { __ }       = wp.i18n;
	const config       = window.epThemeUpdate || {};
	const ns           = config.restNamespace || 'ep-theme-update/v1';

	// ── DOM refs ──────────────────────────────────────────────────────
	let $notices, $statusContent, $channelContent, $pinContent;
	let $releasesContent, $checkBtn, $installBtn, $reinstallBtn, $spinner, $progress;

	// ── State ─────────────────────────────────────────────────────────
	let currentStatus  = null;
	let releasesList   = [];
	let isInstalling   = false;

	// ── Init ──────────────────────────────────────────────────────────
	document.addEventListener( 'DOMContentLoaded', function () {
		$notices         = document.querySelector( '.ep-notices' );
		$statusContent   = document.querySelector( '.ep-status-content' );
		$channelContent  = document.querySelector( '.ep-channel-content' );
		$pinContent      = document.querySelector( '.ep-pin-content' );
		$releasesContent = document.querySelector( '.ep-releases-content' );
		$checkBtn        = document.getElementById( 'ep-check-updates' );
		$installBtn      = document.getElementById( 'ep-install-update' );
		$reinstallBtn    = document.getElementById( 'ep-reinstall' );
		$spinner         = document.getElementById( 'ep-action-spinner' );
		$progress        = document.getElementById( 'ep-progress' );

		$checkBtn.addEventListener( 'click', handleCheckUpdates );
		$installBtn.addEventListener( 'click', handleInstallUpdate );
		$reinstallBtn.addEventListener( 'click', handleReinstall );

		// Initial data load.
		loadStatus();
		loadReleases();
	} );

	// ── API helpers ───────────────────────────────────────────────────

	function api( method, endpoint, data ) {
		const opts = {
			path:   ns + endpoint,
			method: method,
		};

		if ( data && ( method === 'POST' || method === 'PUT' ) ) {
			opts.data = data;
		}

		return apiFetch( opts );
	}

	// ── Data loading ──────────────────────────────────────────────────

	function loadStatus() {
		api( 'GET', '/status' )
			.then( function ( status ) {
				currentStatus = status;
				renderStatus( status );
				renderChannel( status );
				renderPin( status );
				updateButtons( status );
				$checkBtn.disabled = false;
			} )
			.catch( function ( err ) {
				showNotice( 'error', err.message || __( 'Failed to load status.', 'examplepress-theme-update' ) );
				$checkBtn.disabled = false;
			} );
	}

	function loadReleases() {
		api( 'GET', '/releases' )
			.then( function ( data ) {
				releasesList = data.releases || [];
				renderReleases( releasesList );
				// Re-render pin dropdown now that we have releases.
				if ( currentStatus ) {
					renderPin( currentStatus );
				}
			} )
			.catch( function ( err ) {
				$releasesContent.classList.remove( 'ep-loading' );
				$releasesContent.innerHTML = '<p class="ep-empty">' +
					esc( err.message || __( 'Failed to load releases.', 'examplepress-theme-update' ) ) +
					'</p>';
			} );
	}

	// ── Event handlers ────────────────────────────────────────────────

	function handleCheckUpdates() {
		$checkBtn.disabled = true;
		showSpinner( true );

		api( 'POST', '/check' )
			.then( function ( status ) {
				currentStatus = status;
				renderStatus( status );
				renderChannel( status );
				updateButtons( status );
				showNotice( 'success', __( 'Update check complete.', 'examplepress-theme-update' ) );
				// Also refresh releases.
				loadReleases();
			} )
			.catch( function ( err ) {
				showNotice( 'error', err.message || __( 'Could not reach the update server.', 'examplepress-theme-update' ) );
			} )
			.finally( function () {
				$checkBtn.disabled = false;
				showSpinner( false );
			} );
	}

	function handleInstallUpdate() {
		if ( isInstalling ) return;

		isInstalling        = true;
		$installBtn.disabled = true;
		$checkBtn.disabled   = true;
		showProgress( true, __( 'Installing update…', 'examplepress-theme-update' ) );

		const data = {};
		if ( currentStatus && currentStatus.pinned_version ) {
			data.version = currentStatus.pinned_version;
		}

		api( 'POST', '/install', Object.keys( data ).length ? data : undefined )
			.then( function ( result ) {
				showProgress( false );
				showNotice( 'success', result.message || __( 'Theme updated successfully.', 'examplepress-theme-update' ) );
				// Refresh status.
				loadStatus();
			} )
			.catch( function ( err ) {
				showProgress( false );
				showNotice( 'error', err.message || __( 'Update failed.', 'examplepress-theme-update' ) );
			} )
			.finally( function () {
				isInstalling = false;
				$checkBtn.disabled = false;
				if ( currentStatus ) {
					updateButtons( currentStatus );
				}
			} );
	}

	function handleReinstall() {
		if ( isInstalling ) return;
		if ( ! currentStatus || ! currentStatus.current_version ) return;

		var confirmed = window.confirm(
			__( 'Reinstall ExamplePress v', 'examplepress-theme-update' ) +
			currentStatus.current_version + '?\n\n' +
			__( 'This will replace the current theme files with a clean copy from the release. Any local modifications to theme files will be lost.', 'examplepress-theme-update' )
		);

		if ( ! confirmed ) return;

		isInstalling          = true;
		$reinstallBtn.disabled = true;
		$installBtn.disabled   = true;
		$checkBtn.disabled     = true;
		showProgress( true, __( 'Reinstalling theme…', 'examplepress-theme-update' ) );

		api( 'POST', '/reinstall' )
			.then( function ( result ) {
				showProgress( false );
				showNotice( 'success', result.message || __( 'Theme reinstalled successfully.', 'examplepress-theme-update' ) );
				loadStatus();
			} )
			.catch( function ( err ) {
				showProgress( false );
				showNotice( 'error', err.message || __( 'Reinstall failed.', 'examplepress-theme-update' ) );
			} )
			.finally( function () {
				isInstalling = false;
				$checkBtn.disabled = false;
				if ( currentStatus ) {
					updateButtons( currentStatus );
				}
			} );
	}

	function handleChannelChange( channel ) {
		showSpinner( true );

		api( 'PUT', '/channel', { channel: channel } )
			.then( function ( status ) {
				currentStatus = status;
				renderStatus( status );
				renderChannel( status );
				renderPin( status );
				updateButtons( status );
				showNotice( 'success', __( 'Channel updated.', 'examplepress-theme-update' ) );
			} )
			.catch( function ( err ) {
				showNotice( 'error', err.message || __( 'Failed to update channel.', 'examplepress-theme-update' ) );
				// Re-render to reset radio state.
				if ( currentStatus ) {
					renderChannel( currentStatus );
				}
			} )
			.finally( function () {
				showSpinner( false );
			} );
	}

	function handlePinVersion( version ) {
		showSpinner( true );

		api( 'PUT', '/pin', { version: version || null } )
			.then( function ( status ) {
				currentStatus = status;
				renderStatus( status );
				renderPin( status );
				updateButtons( status );
				var msg = version
					? __( 'Pinned to version ', 'examplepress-theme-update' ) + version + '.'
					: __( 'Version pin cleared.', 'examplepress-theme-update' );
				showNotice( 'success', msg );
			} )
			.catch( function ( err ) {
				showNotice( 'error', err.message || __( 'Failed to update pin.', 'examplepress-theme-update' ) );
			} )
			.finally( function () {
				showSpinner( false );
			} );
	}

	// ── Renderers ─────────────────────────────────────────────────────

	function renderStatus( status ) {
		var badgeClass = 'ep-badge--current';
		var badgeText  = __( 'Up to date', 'examplepress-theme-update' );

		if ( ! status.current_version ) {
			badgeClass = 'ep-badge--error';
			badgeText  = __( 'Theme not installed', 'examplepress-theme-update' );
		} else if ( status.update_available ) {
			badgeClass = 'ep-badge--update';
			badgeText  = __( 'Update available', 'examplepress-theme-update' );
		}

		var lastChecked = status.last_checked
			? new Date( status.last_checked * 1000 ).toLocaleString()
			: __( 'Never', 'examplepress-theme-update' );

		$statusContent.classList.remove( 'ep-loading' );
		$statusContent.innerHTML =
			'<div class="ep-status-grid">' +
				'<div class="ep-status-item">' +
					'<span class="ep-status-label">' + esc( __( 'Installed Version', 'examplepress-theme-update' ) ) + '</span>' +
					'<span class="ep-status-value">' + esc( status.current_version || '—' ) + '</span>' +
				'</div>' +
				'<div class="ep-status-item">' +
					'<span class="ep-status-label">' + esc( __( 'Latest Version', 'examplepress-theme-update' ) ) + '</span>' +
					'<span class="ep-status-value">' + esc( status.latest_version || '—' ) + '</span>' +
				'</div>' +
				'<div class="ep-status-item">' +
					'<span class="ep-status-label">' + esc( __( 'Status', 'examplepress-theme-update' ) ) + '</span>' +
					'<span class="ep-badge ' + badgeClass + '">' + esc( badgeText ) + '</span>' +
				'</div>' +
				'<div class="ep-status-item">' +
					'<span class="ep-status-label">' + esc( __( 'Last Checked', 'examplepress-theme-update' ) ) + '</span>' +
					'<span class="ep-status-value">' + esc( lastChecked ) + '</span>' +
				'</div>' +
			'</div>';
	}

	function renderChannel( status ) {
		var locked   = status.channel_source === 'filter' || status.channel_source === 'constant';
		var channel  = status.channel;

		$channelContent.classList.remove( 'ep-loading' );

		if ( locked ) {
			var sourceLabel = status.channel_source === 'filter'
				? __( 'a WordPress filter', 'examplepress-theme-update' )
				: __( 'the EP_UPDATE_CHANNEL constant', 'examplepress-theme-update' );

			$channelContent.innerHTML =
				'<div class="ep-channel-options">' +
					'<div class="ep-channel-option">' +
						'<input type="radio" disabled checked> ' +
						'<label>' + esc( channel === 'stable' ? __( 'Stable', 'examplepress-theme-update' ) : __( 'Development', 'examplepress-theme-update' ) ) +
						' <span class="ep-channel-desc">(' + esc( __( 'locked', 'examplepress-theme-update' ) ) + ')</span></label>' +
					'</div>' +
				'</div>' +
				'<div class="ep-channel-locked">' +
					esc( __( 'Channel is controlled by ', 'examplepress-theme-update' ) + sourceLabel + __(  ' and cannot be changed here.', 'examplepress-theme-update' ) ) +
				'</div>';
			return;
		}

		$channelContent.innerHTML =
			'<div class="ep-channel-options">' +
				'<div class="ep-channel-option">' +
					'<input type="radio" name="ep-channel" id="ep-channel-stable" value="stable"' +
						( channel === 'stable' ? ' checked' : '' ) + '>' +
					'<label for="ep-channel-stable">' +
						esc( __( 'Stable', 'examplepress-theme-update' ) ) +
						'<span class="ep-channel-desc">' + esc( __( 'Production-ready releases from the main branch.', 'examplepress-theme-update' ) ) + '</span>' +
					'</label>' +
				'</div>' +
				'<div class="ep-channel-option">' +
					'<input type="radio" name="ep-channel" id="ep-channel-development" value="development"' +
						( channel === 'development' ? ' checked' : '' ) + '>' +
					'<label for="ep-channel-development">' +
						esc( __( 'Development', 'examplepress-theme-update' ) ) +
						'<span class="ep-channel-desc">' + esc( __( 'Pre-release builds from the development branch.', 'examplepress-theme-update' ) ) + '</span>' +
					'</label>' +
				'</div>' +
			'</div>';

		// Bind channel radio change events.
		var radios = $channelContent.querySelectorAll( 'input[name="ep-channel"]' );
		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				handleChannelChange( this.value );
			} );
		} );
	}

	function renderPin( status ) {
		$pinContent.classList.remove( 'ep-loading' );

		var pinned = status.pinned_version;

		// Build options from releases list.
		var options = '<option value="">' + esc( __( '— No pin (use latest)', 'examplepress-theme-update' ) ) + '</option>';

		releasesList.forEach( function ( release ) {
			var selected = ( pinned === release.version ) ? ' selected' : '';
			var label    = release.version;

			if ( release.prerelease ) {
				label += ' (' + __( 'prerelease', 'examplepress-theme-update' ) + ')';
			}

			options += '<option value="' + esc( release.version ) + '"' + selected + '>' + esc( label ) + '</option>';
		} );

		var html =
			'<div class="ep-pin-controls">' +
				'<select id="ep-pin-select">' + options + '</select>' +
				'<button type="button" class="button" id="ep-pin-save">' +
					esc( __( 'Save Pin', 'examplepress-theme-update' ) ) +
				'</button>';

		if ( pinned ) {
			html += '<button type="button" class="button" id="ep-pin-clear">' +
				esc( __( 'Clear Pin', 'examplepress-theme-update' ) ) +
				'</button>';
		}

		html += '</div>';

		// Show warning if installed version exceeds pinned version.
		if ( pinned && status.current_version && status.current_version !== pinned ) {
			var cmp = versionCompare( status.current_version, pinned );

			if ( cmp > 0 ) {
				html += '<div class="ep-pin-notice">' +
					esc( __( 'Installed version ', 'examplepress-theme-update' ) + status.current_version +
						__( ' is newer than pinned version ', 'examplepress-theme-update' ) + pinned +
						__( '. Clear the pin or pin to a newer version.', 'examplepress-theme-update' ) ) +
					'</div>';
			}
		}

		$pinContent.innerHTML = html;

		// Bind events.
		var $pinSave = document.getElementById( 'ep-pin-save' );
		if ( $pinSave ) {
			$pinSave.addEventListener( 'click', function () {
				var sel = document.getElementById( 'ep-pin-select' );
				handlePinVersion( sel.value );
			} );
		}

		var $pinClear = document.getElementById( 'ep-pin-clear' );
		if ( $pinClear ) {
			$pinClear.addEventListener( 'click', function () {
				handlePinVersion( '' );
			} );
		}
	}

	function renderReleases( releases ) {
		$releasesContent.classList.remove( 'ep-loading' );

		if ( ! releases.length ) {
			$releasesContent.innerHTML = '<p class="ep-empty">' +
				esc( __( 'No releases found.', 'examplepress-theme-update' ) ) + '</p>';
			return;
		}

		var html = '<ul class="ep-releases-list">';

		releases.forEach( function ( release ) {
			var dateStr = release.date
				? new Date( release.date ).toLocaleDateString()
				: '';

			var badges = '';
			if ( release.prerelease ) {
				badges += ' <span class="ep-badge ep-badge--prerelease">' +
					esc( __( 'prerelease', 'examplepress-theme-update' ) ) + '</span>';
			}

			html +=
				'<li class="ep-release-item">' +
					'<div class="ep-release-header">' +
						'<span class="ep-release-toggle">&#9654;</span>' +
						'<span class="ep-release-version">' + esc( release.version ) + '</span>' +
						badges +
						'<span class="ep-release-name">' + esc( release.name || '' ) + '</span>' +
						'<span class="ep-release-date">' + esc( dateStr ) + '</span>' +
					'</div>' +
					'<div class="ep-release-body">' + esc( release.body || __( 'No release notes.', 'examplepress-theme-update' ) ) + '</div>' +
				'</li>';
		} );

		html += '</ul>';
		$releasesContent.innerHTML = html;

		// Bind toggle events.
		$releasesContent.querySelectorAll( '.ep-release-header' ).forEach( function ( header ) {
			header.addEventListener( 'click', function () {
				this.parentElement.classList.toggle( 'ep-open' );
			} );
		} );
	}

	// ── UI helpers ────────────────────────────────────────────────────

	function updateButtons( status ) {
		$installBtn.disabled   = ! status.update_available || isInstalling || ! status.current_version;
		$reinstallBtn.disabled = ! status.current_version || isInstalling;
	}

	function showSpinner( visible ) {
		if ( $spinner ) {
			$spinner.classList.toggle( 'is-active', visible );
		}
	}

	function showProgress( visible, message ) {
		if ( ! $progress ) return;

		$progress.hidden = ! visible;

		var msgEl = $progress.querySelector( '.ep-progress-message' );
		if ( msgEl ) {
			msgEl.textContent = message || '';
		}
	}

	function showNotice( type, message ) {
		if ( ! $notices ) return;

		var div       = document.createElement( 'div' );
		div.className = 'notice notice-' + type + ' is-dismissible';
		div.innerHTML = '<p>' + esc( message ) + '</p>' +
			'<button type="button" class="notice-dismiss"></button>';

		$notices.prepend( div );

		// Dismiss on click.
		div.querySelector( '.notice-dismiss' ).addEventListener( 'click', function () {
			div.remove();
		} );

		// Auto-dismiss after 10 seconds.
		setTimeout( function () {
			if ( div.parentNode ) {
				div.remove();
			}
		}, 10000 );
	}

	/**
	 * Escape HTML entities.
	 */
	function esc( str ) {
		if ( ! str ) return '';
		var div       = document.createElement( 'div' );
		div.textContent = String( str );
		return div.innerHTML;
	}

	/**
	 * Simple semver comparison. Returns -1, 0, or 1.
	 */
	function versionCompare( a, b ) {
		var pa = a.split( '.' ).map( Number );
		var pb = b.split( '.' ).map( Number );
		var len = Math.max( pa.length, pb.length );

		for ( var i = 0; i < len; i++ ) {
			var na = pa[ i ] || 0;
			var nb = pb[ i ] || 0;
			if ( na > nb ) return 1;
			if ( na < nb ) return -1;
		}

		return 0;
	}
} )();
