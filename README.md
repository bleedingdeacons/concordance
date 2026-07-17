# Concordance

**API client for the AAGBDB Groups API — a standalone WordPress plugin with a PSR-11 dependency container.**

Concordance provides a clean interface for consuming the [AAGBDB](https://aagbdb.org.uk) Groups API from within WordPress. It exposes group listing data through a REST proxy, an admin dashboard widget, WP-CLI commands, 

**Version:** 1.6.5
**Requires:** WordPress 6.0+ · PHP 8.1+
**License:** MIT (Modified — see [License](#license))
**Author:** [The Bleeding Deacons](mailto:thebleedingdeacons@gmail.com)

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [REST API](#rest-api)
  - [PHP (Service Container)](#php-service-container)
  - [WP-CLI](#wp-cli)
  - [Admin Dashboard Widget](#admin-dashboard-widget)
- [Architecture](#architecture)
- [Building for Production](#building-for-production)
- [Configuration Reference](#configuration-reference)
- [License](#license)

---

## Features

- **REST API proxy** — exposes `/wp-json/concordance/v1/groups` and `/wp-json/concordance/v1/groups/{id}` so front-end code or external consumers can fetch group data through your WordPress site without exposing API keys.
- **Transient caching** — API responses are cached using WordPress transients with a configurable TTL (default 15 minutes). Caching can be disabled entirely by setting TTL to 0.
- **Admin settings page** — configure the API key, base URL, cache TTL, and request timeout from *Settings → Concordance* in wp-admin. Includes a one-click connection test.
- **Dashboard widget** — displays AAGBDB group listings directly on the WordPress dashboard, sorted by day and time.
- **WP-CLI commands** — list groups, fetch individual records, test the connection, view configuration, and flush the cache from the command line.
- **Immutable value objects** — the `GroupListing` model provides type-safe access to API data with built-in sorting, serialization, and display helpers.
- **Build script** — a cross-platform PHP build script packages the plugin into a distributable `.zip` archive.

---

## Installation

### From a .zip archive

1. Download or build the `concordance.zip` archive.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload the `.zip` file and click **Install Now**.
4. Activate the plugin.

### Manual installation

1. Clone or copy the `concordance` directory into `wp-content/plugins/`.
2. Run Composer to install dependencies:

```bash
cd wp-content/plugins/concordance
composer install --no-dev --optimize-autoloader
```

3. Activate the plugin from the WordPress admin.

On activation, the plugin stores a default API key and a 15-minute cache TTL in `wp_options`. These can be changed on the settings page.

---

## Configuration

Navigate to **Concordance → Settings** in the WordPress admin. The following options are available:

| Setting | Description | Default |
|---------|-------------|---------|
| **API Key** | Your AAGBDB API key for authentication. | Pre-populated on activation |
| **API Base URL** | The root URL for the AAGBDB API. | `https://aagbdb.org.uk/api` |
| **Cache Lifetime** | How long API responses are cached (in seconds). Set to `0` to disable. | `900` (15 minutes) |
| **Request Timeout** | HTTP request timeout in seconds. | `30` |

All settings are stored in the `wp_options` table under the keys `concordance_api_key`, `concordance_api_base_url`, `concordance_cache_ttl`, and `concordance_request_timeout`.

---

## Usage

### REST API

Concordance registers two REST routes under the `concordance/v1` namespace. Both require the requesting user to have the `read` capability (i.e. any logged-in user).

**List all groups:**

```
GET /wp-json/concordance/v1/groups
```

Responses are served from the transient cache when available. Query parameters are forwarded to the upstream API.

**Fetch a single group:**

```
GET /wp-json/concordance/v1/groups/{id}
```

Returns a single group record by its ID.

### PHP (Service Container)

The global `concordance()` function returns a PSR-11 container. You can resolve any registered service by class name:

```php
use Concordance\Api\ApiClient;
use Concordance\Api\ApiCache;

// Direct API call (no caching)
$client = concordance()->get(ApiClient::class);
$groups = $client->getGroups();

// Cached API call
$cache = concordance()->get(ApiCache::class);
$groups = $cache->getGroups();

// Fetch a single group
$group = $cache->getGroup(42);
```

You can also hook into the plugin's initialization:

```php
add_action('concordance/loaded', function (\Psr\Container\ContainerInterface $container) {
    // Plugin is ready — register your own services or consume data
});
```

### WP-CLI

Concordance registers all commands under the `wp concordance` namespace.

**List all groups:**

```bash
wp concordance list
wp concordance list --format=json
wp concordance list --fields=groupName,day,startTime --limit=10
wp concordance list --intergroup=1
wp concordance list --sort=day,time,name
wp concordance list --no-cache
```

The `--sort` flag accepts comma-separated fields: `day`, `time`, and `name`. The `--intergroup` flag filters by intergroup ID.

**Fetch a single group:**

```bash
wp concordance get 42
wp concordance get 42 --format=json
```

**Test the API connection:**

```bash
wp concordance test
```

**View current configuration:**

```bash
wp concordance config
```

Displays the version, base URL, masked API key, cache TTL, timeout, and REST namespace.

**Flush the transient cache:**

```bash
wp concordance flush-cache
```

**Display the plugin version:**

```bash
wp concordance version
```

### Admin Dashboard Widget

When the plugin is active, a dashboard widget titled **AAGBDB Group Listings** appears on the main WordPress dashboard. It displays group cards filtered to intergroup ID 1, sorted by day, time, and name. Each card renders all fields returned by the API in a responsive two-column grid layout with automatic URL and email detection.

---

## Architecture

Concordance follows a service-oriented architecture with a PSR-11 container at its core.

```
concordance/
├── Concordance.php                          # Plugin bootstrap & WordPress hooks
├── composer.json                            # Dependencies & PSR-4 autoloading
├── build.php                                # Cross-platform build/packaging script
├── assets/
│   └── docs/concordance.html                # Bundled HTML documentation
└── src/
    ├── Plugin.php                           # Service registration & initialization
    ├── Common/
    │   └── ConcordanceConfiguration.php     # Constants (option keys, defaults)
    ├── Core/
    │   └── Container.php                    # PSR-11 DI container
    ├── Api/
    │   ├── ApiClient.php                    # HTTP client (wp_remote_request)
    │   └── ApiCache.php                     # Transient caching layer
    ├── Models/
    │   └── GroupListing.php                 # Immutable value object + sorting
    ├── Managers/
    │   └── GroupListingManager.php          # REST route registration & handlers
    ├── Admin/
    │   ├── SettingsAdmin.php                # Settings page & connection test
    │   └── GroupListings/
    │       └── GroupListingDashboard.php    # Dashboard widget
    └── Cli/
        └── ConcordanceCli.php              # WP-CLI commands
```

**Service dependency graph:**

- `ApiClient` — standalone, reads config from `wp_options`
- `ApiCache` → depends on `ApiClient`
- `GroupListingManager` → depends on `ApiClient` + `ApiCache`
- `SettingsAdmin` → depends on `ApiClient`
- `GroupListingDashboard` → depends on `ApiCache`
- `ConcordanceCli` → depends on `ApiClient` + `ApiCache`

All services are registered as lazy singletons — they are only instantiated when first requested from the container.

---

## Building for Production

The included `build.php` script packages the plugin into a distributable `.zip` archive, stripping development files (tests, editor configs, Composer files, etc.) and keeping only what's needed at runtime.

```bash
# Production build
composer build
# or
php build.php build:production

# Development build (includes tests)
composer build:dev

# Clean the build directory
composer build:clean
```

You can override the version number with `--version=X.X` and add `--clean` to wipe the build directory before packaging.

---

## Configuration Reference

All option keys and defaults are centralised in `ConcordanceConfiguration`:

| Constant | Option Key | Default |
|----------|-----------|---------|
| `OPTION_API_KEY` | `concordance_api_key` | *(set on activation)* |
| `OPTION_CACHE_TTL` | `concordance_cache_ttl` | `900` |
| `OPTION_API_BASE_URL` | `concordance_api_base_url` | `https://aagbdb.org.uk/api` |
| `OPTION_REQUEST_TIMEOUT` | `concordance_request_timeout` | `30` |
| `CACHE_PREFIX` | — | `concordance_` |
| `REST_NAMESPACE` | — | `concordance/v1` |

On deactivation, the plugin cleans up all transient caches (keys prefixed with `concordance_`). Stored options are *not* removed on deactivation, so settings persist through deactivate/reactivate cycles.

---

## License

MIT License (Modified) — Copyright © 2025 The Bleeding Deacons.

This software is provided under the standard MIT license with one additional restriction: the licensee may not sell the Software, alone or as part of an aggregate software distribution containing the Software.

See [LICENSE](./LICENSE) for the full text.
