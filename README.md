# ExamplePress Theme Update

Persistent update manager for the [ExamplePress](https://github.com/webmultipliers/examplepress-theme) WordPress theme. This companion plugin checks for new theme versions via GitHub Releases, injects them into WordPress's native updater, and provides a dedicated admin page for managing the full update lifecycle.

The plugin survives theme updates because it lives in `wp-content/plugins/`, not the theme directory. It cannot be deactivated while the ExamplePress theme is active.

## Requirements

- WordPress 6.9+
- PHP 8.0+
- ExamplePress theme installed

## Features

### Automatic Update Detection

The plugin hooks into WordPress's native theme update system (`pre_set_site_transient_update_themes`) and injects update payloads from the theme's GitHub Releases. Updates appear on the standard **Dashboard > Updates** screen and the **Appearance > Themes** page just like any other theme update.

### Admin Page (Appearance > EP Updates)

A dedicated admin page at **Appearance > EP Updates** provides full control over theme updates:

- **Theme Status** — Current installed version, latest available version, update availability badge, and last-checked timestamp.
- **Channel Selector** — Switch between **Stable** (production releases from `main`) and **Development** (pre-release builds from `development`). The selector is disabled when the channel is locked by a filter or constant.
- **Version Pinning** — Pin to a specific release version. The plugin will only offer updates up to the pinned version, never beyond it. Useful for staging environments or controlled rollouts.
- **One-Click Updates** — Install theme updates directly from the admin page using WordPress's native `Theme_Upgrader`.
- **Release History** — Browse all GitHub releases with collapsible release notes.
- **Force Check** — Clear the update cache and re-check GitHub immediately.

### Update Channels

Channel resolution follows a priority chain:

1. `examplepress_update_channel` filter (highest priority)
2. `EP_UPDATE_CHANNEL` constant in `wp-config.php`
3. Admin page setting (stored as `ep_update_channel` option)
4. Auto-detect from installed theme version (versions containing `dev`, `alpha`, `beta`, or `rc` route to development)
5. Default: `stable`

### Version Pinning

Pin to a specific version to prevent updates beyond that release:

- If the pinned version is newer than the installed version, it is offered as an update.
- If the installed version matches the pin, no update is offered.
- If the installed version somehow exceeds the pin, a warning is shown but no downgrade is attempted.

### Self-Protection

While the ExamplePress theme is active:

- The **Deactivate** and **Delete** links are removed from the Plugins list.
- Programmatic deactivation is blocked with `wp_die()`.
- If the theme is not active, an admin notice warns that the plugin has no effect.

## REST API

All admin page interactions use the `ep-theme-update/v1` REST API. All routes require the `update_themes` capability.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/status` | Current version, latest version, channel, pin, last checked |
| `GET` | `/releases` | Cached list of GitHub releases |
| `POST` | `/check` | Flush cache and return fresh status |
| `PUT` | `/channel` | Set update channel (`stable` or `development`) |
| `PUT` | `/pin` | Set or clear pinned version |
| `POST` | `/install` | Trigger theme update via `Theme_Upgrader` |

## Filters

| Filter | Description |
|--------|-------------|
| `examplepress_update_channel` | Override the update channel. Return `'stable'` or `'development'`. |
| `examplepress_update_manifest_url` | Override the `updates.json` manifest URL. Receives the URL and channel as arguments. |
| `examplepress_update_variant` | Override the preferred package variant (default: `'full'`). |

## Configuration

### Constants

Define in `wp-config.php`:

```php
// Lock the update channel (overrides admin page setting)
define( 'EP_UPDATE_CHANNEL', 'stable' );
```

### Legacy Force Check

Append `?ep_force_update_check=1&_wpnonce=<nonce>` to any wp-admin URL to flush the update cache. This works independently of the REST API and admin page.

## Architecture

The plugin uses a namespaced class architecture under `ExamplePress\ThemeUpdate\` with an SPL autoloader:

```
examplepress-theme-update.php   # Entry: constants, autoloader, Plugin::init()
inc/
  Plugin.php                    # Orchestrator
  Cache.php                     # Transient cache management
  GitHubClient.php              # GitHub API communication
  ChannelResolver.php           # Channel + pin resolution
  Updater.php                   # WordPress update pipeline
  Protection.php                # Deactivation protection
  AdminPage.php                 # Admin menu + asset enqueue
  RestController.php            # REST API routes
  views/
    admin-page.php              # Admin page HTML template
assets/
  css/admin.css                 # Admin page styles
  js/admin.js                   # Admin page JS (wp.apiFetch)
```

## How It Works

1. On every WordPress update check, the plugin fetches `updates.json` from the theme's GitHub Releases (cached for 6 hours).
2. If a newer version is available (respecting channel and pin settings), it injects the update into WordPress's `update_themes` transient.
3. WordPress then shows the update on its native screens.
4. When the user triggers an update (from WP's UI or the EP Updates page), the plugin hooks `upgrader_source_selection` to rename the extracted directory from GitHub's format (`examplepress-theme-v1.0.4/`) back to `examplepress-theme/`.

## License

Proprietary. Copyright Web Multipliers.
