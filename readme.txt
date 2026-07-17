=== Concordance ===
Contributors: thebleedingdeacons
Tags: api, groups, directory, listings, aagbdb
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.6.5
Build date: 2026/07/17 15:47:19
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

API client for the AAGBDB Groups API. Standalone plugin with PSR-11 container.

== Description ==

Concordance provides a clean interface for consuming the [AAGBDB](https://aagbdb.org.uk) Groups API from within WordPress. It exposes group listing data through a REST proxy, an admin dashboard widget, WP-CLI commands.

**Key features:**

* **REST API proxy** — exposes `/wp-json/concordance/v1/groups` and `/wp-json/concordance/v1/groups/{id}` so front-end code or external consumers can fetch group data through your WordPress site without exposing API keys.
* **Transient caching** — API responses are cached using WordPress transients with a configurable TTL (default 15 minutes). Set TTL to 0 to disable caching entirely.
* **PSR-11 dependency container** — a lightweight, lazy-loading singleton container that other plugins can tap into via the `concordance()` helper function.
* **Admin settings page** — configure the API key, base URL, cache TTL, and request timeout from Settings → Concordance. Includes a one-click connection test.
* **Dashboard widget** — displays AAGBDB group listings directly on the WordPress dashboard, sorted by day and time.
* **WP-CLI commands** — list groups, fetch individual records, test the connection, view configuration, and flush the cache from the command line.
* **Immutable value objects** — the `GroupListing` model provides type-safe access to API data with built-in sorting, serialization, and display helpers.
* **Build script** — a cross-platform PHP build script packages the plugin into a distributable `.zip` archive.

== Installation ==

= From a .zip archive =

1. Download or build the `concordance.zip` archive.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload the `.zip` file and click **Install Now**.
4. Activate the plugin.

= Manual installation =

1. Clone or copy the `concordance` directory into `wp-content/plugins/`.
2. Run Composer to install dependencies:

`cd wp-content/plugins/concordance && composer install --no-dev --optimize-autoloader`

3. Activate the plugin from the WordPress admin.

= After activation =

Navigate to **Settings → Concordance** to enter your AAGBDB API key and adjust cache, timeout, and base URL settings.

== Frequently Asked Questions ==

= Where do I get an AAGBDB API key? =

Contact the AAGBDB organisation at [aagbdb.org.uk](https://aagbdb.org.uk) to request API access credentials.

= How do I access group data from my theme or another plugin? =

Use the global `concordance()` helper to resolve services from the PSR-11 container:

`$cache = concordance()->get(\Concordance\Api\ApiCache::class);`
`$groups = $cache->getGroups();`

You can also hook into the `concordance/loaded` action which fires once the plugin is fully initialised.

= Can I disable caching? =

Yes. Set the **Cache Lifetime** to `0` on the settings page (Settings → Concordance) or via WP-CLI.

= What WP-CLI commands are available? =

All commands live under the `wp concordance` namespace:

* `wp concordance list` — list all groups (supports `--format`, `--fields`, `--limit`, `--intergroup`, `--sort`, `--no-cache`)
* `wp concordance get {id}` — fetch a single group by ID
* `wp concordance test` — test the API connection
* `wp concordance config` — display current configuration
* `wp concordance flush-cache` — clear all cached API responses
* `wp concordance version` — display the plugin version

= Does the REST API require authentication? =

Yes. Both REST endpoints require the requesting user to have the `read` capability, meaning any logged-in WordPress user can access them. Unauthenticated requests are rejected.

= What happens on deactivation? =

All transient caches (prefixed with `concordance_`) are removed. Stored options (API key, settings) are preserved so they persist through deactivate/reactivate cycles.

== Configuration Reference ==

The following settings are available under Settings → Concordance:

* **API Key** — your AAGBDB API key for authentication.
* **API Base URL** — the root URL for the AAGBDB API (default: `https://aagbdb.org.uk/api`).
* **Cache Lifetime** — how long API responses are cached in seconds (default: `900`). Set to `0` to disable.
* **Request Timeout** — HTTP request timeout in seconds (default: `30`).

All settings are stored in `wp_options` under the keys `concordance_api_key`, `concordance_api_base_url`, `concordance_cache_ttl`, and `concordance_request_timeout`.

== Architecture ==

Concordance follows a service-oriented architecture with a PSR-11 container at its core. All services are registered as lazy singletons and only instantiated when first requested.

* `ApiClient` — standalone HTTP client using `wp_remote_request`
* `ApiCache` — transient caching layer (depends on ApiClient)
* `GroupListingManager` — REST route registration and handlers
* `SettingsAdmin` — admin settings page and connection test
* `GroupListingDashboard` — dashboard widget
* `ConcordanceCli` — WP-CLI command handler
