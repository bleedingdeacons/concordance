<?php

declare(strict_types=1);

namespace Concordance\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Concordance\Api\ApiCache;
use Concordance\Api\ApiClient;
use Concordance\Common\ConcordanceConfiguration;
use Concordance\Common\Encryption;
use Concordance\Models\GroupListing;
use Exception;

use function add_action;
use function add_options_page;
use function add_query_arg;
use function add_settings_field;
use function add_settings_section;
use function admin_url;
use function current_user_can;
use function do_settings_sections;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_option;
use function is_wp_error;
use function register_setting;
use function settings_fields;
use function submit_button;
use function wp_nonce_url;
use function wp_verify_nonce;

/**
 * Class SettingsAdmin
 *
 * Admin settings page for configuring the AAGBDB API connection.
 */
class SettingsAdmin
{
    private ApiClient $client;
    private Encryption $encryption;
    private ?ApiCache $cache;

    public function __construct(
        ApiClient $client,
        ?Encryption $encryption = null,
        ?ApiCache $cache = null
    ) {
        $this->client     = $client;
        $this->encryption = $encryption ?? new Encryption();
        $this->cache      = $cache;

        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'handleCacheFlush']);
        add_action('admin_footer', [$this, 'addDocsNewTabScript']);
    }

    /**
     * Register the top-level Concordance menu and Settings submenu.
     *
     * @return void
     */
    public function registerMenu(): void
    {
        add_menu_page(
            esc_html__('Concordance', 'concordance'),
            esc_html__('Concordance', 'concordance'),
            'manage_options',
            'concordance',
            [$this, 'renderSettingsPage'],
            'dashicons-cloud',
            30
        );

        add_submenu_page(
            'concordance',
            esc_html__('Settings', 'concordance'),
            esc_html__('Settings', 'concordance'),
            'manage_options',
            'concordance',
            [$this, 'renderSettingsPage']
        );

        // Documentation link — opens in a new tab via JavaScript
        add_submenu_page(
            'concordance',
            esc_html__('Documentation', 'concordance'),
            esc_html__('Documentation', 'concordance'),
            'manage_options',
            'concordance-docs',
            [$this, 'renderDocsRedirect']
        );
    }

    /**
     * Redirect to the docs HTML file. Fallback if JS new-tab doesn't fire.
     *
     * @return void
     */
    public function renderDocsRedirect(): void
    {
        $docsUrl = CONCORDANCE_PLUGIN_URL . 'assets/docs/concordance.html';
        echo '<script>window.open(' . wp_json_encode($docsUrl) . ', "_blank"); window.history.back();</script>';
        echo '<p>' . esc_html__('Opening documentation...', 'concordance') . ' ';
        echo '<a href="' . esc_url($docsUrl) . '" target="_blank">' . esc_html__('Click here if it did not open.', 'concordance') . '</a></p>';
    }

    /**
     * Add inline script to make the Documentation submenu link open in a new tab.
     *
     * @return void
     */
    public function addDocsNewTabScript(): void
    {
        $docsUrl = CONCORDANCE_PLUGIN_URL . 'assets/docs/concordance.html';
        ?>
        <script>
        (function() {
            var link = document.querySelector('a[href="admin.php?page=concordance-docs"]');
            if (link) {
                link.setAttribute('href', <?php echo wp_json_encode($docsUrl); ?>);
                link.setAttribute('target', '_blank');
            }
        })();
        </script>
        <?php
    }

    /**
     * Register settings, sections, and fields.
     *
     * @return void
     */
    public function registerSettings(): void
    {
        register_setting('concordance_options', ConcordanceConfiguration::OPTION_API_KEY, [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeAndEncryptApiKey'],
            'default'           => '',
        ]);

        register_setting('concordance_options', ConcordanceConfiguration::OPTION_CACHE_TTL, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => ConcordanceConfiguration::DEFAULT_CACHE_TTL,
        ]);

        register_setting('concordance_options', ConcordanceConfiguration::OPTION_API_BASE_URL, [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => ConcordanceConfiguration::DEFAULT_API_BASE_URL,
        ]);

        register_setting('concordance_options', ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => ConcordanceConfiguration::DEFAULT_REQUEST_TIMEOUT,
        ]);

        register_setting('concordance_options', ConcordanceConfiguration::OPTION_INTERGROUP_ID, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => ConcordanceConfiguration::INTERGROUP_ID_ALL,
        ]);

        register_setting('concordance_options', ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitizeDashboardFields'],
            'default'           => ConcordanceConfiguration::DEFAULT_DASHBOARD_FIELDS,
        ]);

        // ── API Configuration section ───────────────────────────────
        add_settings_section(
            'concordance_main_section',
            esc_html__('API Configuration', 'concordance'),
            function () {
                echo '<p>' . esc_html__('Configure your AAGBDB API connection below.', 'concordance') . '</p>';
            },
            'concordance'
        );

        add_settings_field(
            ConcordanceConfiguration::OPTION_API_KEY,
            esc_html__('API Key', 'concordance'),
            [$this, 'renderApiKeyField'],
            'concordance',
            'concordance_main_section'
        );

        add_settings_field(
            ConcordanceConfiguration::OPTION_CACHE_TTL,
            esc_html__('Cache Lifetime (seconds)', 'concordance'),
            [$this, 'renderCacheTtlField'],
            'concordance',
            'concordance_main_section'
        );

        add_settings_field(
            ConcordanceConfiguration::OPTION_API_BASE_URL,
            esc_html__('API Base URL', 'concordance'),
            [$this, 'renderApiBaseUrlField'],
            'concordance',
            'concordance_main_section'
        );

        add_settings_field(
            ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT,
            esc_html__('Request Timeout (seconds)', 'concordance'),
            [$this, 'renderRequestTimeoutField'],
            'concordance',
            'concordance_main_section'
        );

        // ── Dashboard Display section ───────────────────────────────
        add_settings_section(
            'concordance_dashboard_section',
            esc_html__('Dashboard Display', 'concordance'),
            function () {
                echo '<p>' . esc_html__(
                    'Choose which fields appear on each group card in the WordPress dashboard widget. The group name is always shown.',
                    'concordance'
                ) . '</p>';
            },
            'concordance'
        );

        add_settings_field(
            ConcordanceConfiguration::OPTION_INTERGROUP_ID,
            esc_html__('Intergroup Filter', 'concordance'),
            [$this, 'renderIntergroupIdField'],
            'concordance',
            'concordance_dashboard_section'
        );

        add_settings_field(
            ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS,
            esc_html__('Visible Fields', 'concordance'),
            [$this, 'renderDashboardFieldsField'],
            'concordance',
            'concordance_dashboard_section'
        );
    }

    /**
     * Render the API Key input field.
     *
     * @return void
     */
    public function renderApiKeyField(): void
    {
        $stored = get_option(ConcordanceConfiguration::OPTION_API_KEY, '');
        $val    = $this->encryption->decrypt($stored);
        echo '<input type="password" name="' . esc_attr(ConcordanceConfiguration::OPTION_API_KEY) . '" value="' . esc_attr($val) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">' . esc_html__('Your AAGBDB API key for authentication.', 'concordance') . '</p>';
        if (!extension_loaded('openssl')) {
            echo '<p class="description" style="color:#b32d2e;">'
                . esc_html__('⚠ The OpenSSL PHP extension is not available. The API key is obfuscated but not truly encrypted. Enable OpenSSL for AES-256-GCM encryption at rest.', 'concordance')
                . '</p>';
        }
    }

    /**
     * Sanitize and encrypt the API key before it is saved to wp_options.
     *
     * @param mixed $value The raw form input.
     * @return string      Encrypted (or obfuscated) value safe for storage.
     */
    public function sanitizeAndEncryptApiKey(mixed $value): string
    {
        $clean = sanitize_text_field((string) $value);

        if ($clean === '') {
            return '';
        }

        // If the submitted value is already encrypted (e.g. the form round-
        // tripped without the user changing the field) don't double-encrypt.
        if ($this->encryption->isEncrypted($clean)) {
            return $clean;
        }

        return $this->encryption->encrypt($clean);
    }

    /**
     * Render the Cache TTL input field.
     *
     * @return void
     */
    public function renderCacheTtlField(): void
    {
        $val = get_option(ConcordanceConfiguration::OPTION_CACHE_TTL, ConcordanceConfiguration::DEFAULT_CACHE_TTL);
        echo '<input type="number" name="' . esc_attr(ConcordanceConfiguration::OPTION_CACHE_TTL) . '" value="' . esc_attr((string) $val) . '" min="0" step="60" class="small-text" />';
        echo '<p class="description">' . esc_html__('How long to cache API responses. Set to 0 to disable caching.', 'concordance') . '</p>';
    }

    /**
     * Render the API Base URL input field.
     *
     * @return void
     */
    public function renderApiBaseUrlField(): void
    {
        $val = get_option(ConcordanceConfiguration::OPTION_API_BASE_URL, ConcordanceConfiguration::DEFAULT_API_BASE_URL);
        echo '<input type="url" name="' . esc_attr(ConcordanceConfiguration::OPTION_API_BASE_URL) . '" value="' . esc_attr($val) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Base URL for the AAGBDB API (e.g. https://aagbdb.org.uk/api).', 'concordance') . '</p>';
    }

    /**
     * Render the Request Timeout input field.
     *
     * @return void
     */
    public function renderRequestTimeoutField(): void
    {
        $val = get_option(ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT, ConcordanceConfiguration::DEFAULT_REQUEST_TIMEOUT);
        echo '<input type="number" name="' . esc_attr(ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT) . '" value="' . esc_attr((string) $val) . '" min="1" step="1" class="small-text" />';
        echo '<p class="description">' . esc_html__('HTTP request timeout in seconds.', 'concordance') . '</p>';
    }

    /**
     * Render the Intergroup filter dropdown.
     *
     * The list of intergroups is built from whatever the API has already
     * returned (cached). If the cache is empty or the API errored, we fall
     * back to showing only the currently-saved value so the user doesn't
     * silently lose their selection.
     *
     * @return void
     */
    public function renderIntergroupIdField(): void
    {
        $option   = ConcordanceConfiguration::OPTION_INTERGROUP_ID;
        $current  = (int) get_option($option, ConcordanceConfiguration::INTERGROUP_ID_ALL);
        $choices  = $this->getIntergroupChoices();
        $hasData  = !empty($choices);

        echo '<select name="' . esc_attr($option) . '" id="' . esc_attr($option) . '">';

        // "All" sentinel — always present.
        $allSelected = $current === ConcordanceConfiguration::INTERGROUP_ID_ALL ? ' selected' : '';
        echo '<option value="' . esc_attr((string) ConcordanceConfiguration::INTERGROUP_ID_ALL) . '"' . $allSelected . '>'
            . esc_html__('All intergroups', 'concordance')
            . '</option>';

        // If the cache is empty but we have a saved non-zero value, keep it
        // visible as a single option so the form doesn't appear to lose it.
        if (!$hasData && $current !== ConcordanceConfiguration::INTERGROUP_ID_ALL) {
            echo '<option value="' . esc_attr((string) $current) . '" selected>'
                . sprintf(
                    /* translators: %d: intergroup ID */
                    esc_html__('Intergroup #%d (currently saved)', 'concordance'),
                    $current
                )
                . '</option>';
        }

        foreach ($choices as $id => $name) {
            $selected = $current === $id ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $id) . '"' . $selected . '>'
                . esc_html(GroupListing::titleCase($name))
                . '</option>';
        }

        echo '</select>';

        if ($hasData) {
            echo '<p class="description">' . esc_html__(
                'Only groups belonging to this intergroup will appear in the dashboard widget. Select "All intergroups" to disable the filter.',
                'concordance'
            ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__(
                'No intergroup data is available yet — the list will populate once the API returns groups. Test the API connection below or wait for the cache to fill.',
                'concordance'
            ) . '</p>';
        }
    }

    /**
     * Build a deduplicated, sorted list of intergroups from cached API data.
     *
     * @return array<int, string> Map of intergroup ID => intergroup name,
     *                             sorted alphabetically by name.
     */
    private function getIntergroupChoices(): array
    {
        if ($this->cache === null) {
            return [];
        }

        $response = $this->cache->getGroups();
        if (is_wp_error($response)) {
            return [];
        }

        $groups  = GroupListing::collectionFromResponse($response);
        $choices = [];

        foreach ($groups as $group) {
            $id   = $group->getIntergroupId();
            $name = $group->getIntergroupName();

            if ($id <= 0) {
                continue;
            }

            // First occurrence wins; later duplicates are ignored.
            if (!isset($choices[$id])) {
                $choices[$id] = $name !== '' ? $name : ('Intergroup #' . $id);
            }
        }

        asort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    }

    /**
     * Render the Dashboard Fields checkbox grid.
     *
     * @return void
     */
    public function renderDashboardFieldsField(): void
    {
        $stored   = get_option(
            ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS,
            ConcordanceConfiguration::DEFAULT_DASHBOARD_FIELDS
        );
        $selected = is_array($stored) ? $stored : ConcordanceConfiguration::DEFAULT_DASHBOARD_FIELDS;
        $option   = ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS;

        echo '<fieldset class="concordance-fields-fieldset">';
        echo '<legend class="screen-reader-text">' . esc_html__('Dashboard fields', 'concordance') . '</legend>';

        // Hidden empty value so unchecking ALL boxes still submits the option
        // (otherwise unchecked checkboxes are absent from POST and the
        // sanitize callback never fires).
        echo '<input type="hidden" name="' . esc_attr($option) . '[]" value="" />';

        echo '<div class="concordance-fields-grid">';
        foreach (ConcordanceConfiguration::DASHBOARD_FIELDS as $key => $label) {
            $isChecked = in_array($key, $selected, true);
            $id        = 'concordance-field-' . esc_attr($key);

            echo '<label for="' . esc_attr($id) . '" class="concordance-field-label">';
            echo '<input type="checkbox"'
                . ' id="' . esc_attr($id) . '"'
                . ' name="' . esc_attr($option) . '[]"'
                . ' value="' . esc_attr($key) . '"'
                . ($isChecked ? ' checked' : '')
                . ' />';
            echo ' ' . esc_html($label);
            echo '</label>';
        }
        echo '</div>';

        echo '<p>';
        echo '<button type="button" class="button button-secondary" data-concordance-fields-action="all">'
            . esc_html__('Select all', 'concordance') . '</button> ';
        echo '<button type="button" class="button button-secondary" data-concordance-fields-action="none">'
            . esc_html__('Select none', 'concordance') . '</button> ';
        echo '<button type="button" class="button button-secondary" data-concordance-fields-action="defaults">'
            . esc_html__('Restore defaults', 'concordance') . '</button>';
        echo '</p>';

        echo '</fieldset>';

        // A small bit of inline CSS + JS to make the grid presentable and the
        // helper buttons functional. Kept inline (rather than enqueued) to
        // mirror the existing dashboard widget approach.
        ?>
        <style>
            .concordance-fields-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 6px 16px;
                margin: 8px 0 12px;
                max-width: 760px;
            }
            .concordance-field-label {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 13px;
                line-height: 1.4;
            }
        </style>
        <script>
        (function () {
            var fieldset = document.querySelector('.concordance-fields-fieldset');
            if (!fieldset) { return; }
            var defaults = <?php echo wp_json_encode(array_values(ConcordanceConfiguration::DEFAULT_DASHBOARD_FIELDS)); ?>;
            var checkboxes = fieldset.querySelectorAll('input[type="checkbox"]');

            fieldset.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-concordance-fields-action]');
                if (!btn) { return; }
                var action = btn.getAttribute('data-concordance-fields-action');
                checkboxes.forEach(function (cb) {
                    if (action === 'all') {
                        cb.checked = true;
                    } else if (action === 'none') {
                        cb.checked = false;
                    } else if (action === 'defaults') {
                        cb.checked = defaults.indexOf(cb.value) !== -1;
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Sanitize the dashboard fields setting.
     *
     * Accepts only keys present in the DASHBOARD_FIELDS whitelist and
     * returns them in the canonical whitelist order. An all-empty submission
     * (e.g. the user unchecked everything) is honoured and stored as [].
     *
     * @param mixed $value The raw form input.
     * @return string[]    Sanitised list of field keys.
     */
    public function sanitizeDashboardFields(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowed = array_keys(ConcordanceConfiguration::DASHBOARD_FIELDS);
        $clean   = [];

        foreach ($allowed as $key) {
            if (in_array($key, $value, true)) {
                $clean[] = $key;
            }
        }

        return $clean;
    }

    /**
     * Handle the "Flush Cache" GET action.
     *
     * Runs on admin_init so the redirect happens before any output is sent.
     * Verifies the nonce and the user's capability, calls ApiCache::flush(),
     * then redirects back to the settings page with a success/error flag in
     * the query string. The flag is rendered by renderCacheFlushNotices().
     *
     * @return void
     */
    public function handleCacheFlush(): void
    {
        if (!isset($_GET['concordance_flush_cache'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'concordance_flush_cache_nonce')) {
            wp_safe_redirect(add_query_arg(
                ['concordance_flushed' => 'invalid'],
                admin_url('admin.php?page=concordance')
            ));
            exit;
        }

        if ($this->cache === null) {
            wp_safe_redirect(add_query_arg(
                ['concordance_flushed' => 'unavailable'],
                admin_url('admin.php?page=concordance')
            ));
            exit;
        }

        try {
            $deleted = $this->cache->flush();
            wp_safe_redirect(add_query_arg(
                ['concordance_flushed' => (string) $deleted],
                admin_url('admin.php?page=concordance')
            ));
            exit;
        } catch (Exception $e) {
            wp_safe_redirect(add_query_arg(
                ['concordance_flushed' => 'error'],
                admin_url('admin.php?page=concordance')
            ));
            exit;
        }
    }

    /**
     * Render the full settings page.
     *
     * @return void
     */
    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php $this->renderCacheFlushNotices(); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('concordance_options');
                do_settings_sections('concordance');
                submit_button(esc_html__('Save Settings', 'concordance'));
                ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Cache Maintenance', 'concordance'); ?></h2>
            <?php $this->renderCacheMaintenance(); ?>

            <hr />
            <h2><?php esc_html_e('Connection Test', 'concordance'); ?></h2>
            <?php $this->renderConnectionTest(); ?>

            <hr />
            <h2><?php esc_html_e('Usage', 'concordance'); ?></h2>
            <?php $this->renderUsageTable(); ?>
        </div>
        <?php
    }

    /**
     * Render an admin notice for the cache flush result, if one is queued
     * via the concordance_flushed query string flag.
     *
     * @return void
     */
    private function renderCacheFlushNotices(): void
    {
        if (!isset($_GET['concordance_flushed'])) {
            return;
        }

        $flag = sanitize_text_field(wp_unslash($_GET['concordance_flushed']));

        if ($flag === 'invalid') {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('Cache flush request was rejected (invalid security token).', 'concordance')
                . '</p></div>';
            return;
        }

        if ($flag === 'unavailable') {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('Cache service is unavailable. Please contact your administrator.', 'concordance')
                . '</p></div>';
            return;
        }

        if ($flag === 'error') {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('Cache flush failed. Check the error log for details.', 'concordance')
                . '</p></div>';
            return;
        }

        if (ctype_digit($flag)) {
            $count = (int) $flag;
            echo '<div class="notice notice-success is-dismissible"><p>'
                /* translators: %d: number of cached entries cleared */
                . sprintf(esc_html__('Cache flushed: %d cached entries cleared.', 'concordance'), $count)
                . '</p></div>';
        }
    }

    /**
     * Render the cache maintenance section.
     *
     * @return void
     */
    private function renderCacheMaintenance(): void
    {
        $flushUrl = wp_nonce_url(
            add_query_arg(
                'concordance_flush_cache',
                '1',
                admin_url('admin.php?page=concordance')
            ),
            'concordance_flush_cache_nonce'
        );
        ?>
        <p><?php esc_html_e(
            'Force-clear all cached API responses. Useful after the AAGBDB data has changed and you do not want to wait for the cache to expire.',
            'concordance'
        ); ?></p>
        <p>
            <a href="<?php echo esc_url($flushUrl); ?>"
               class="button button-secondary"
               onclick="return confirm('<?php echo esc_attr(esc_js(__(
                   'Clear all cached AAGBDB responses now?',
                   'concordance'
               ))); ?>');">
                <?php esc_html_e('Flush Cache', 'concordance'); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Render the connection test section.
     *
     * @return void
     */
    private function renderConnectionTest(): void
    {
        try {
            if ( isset( $_GET['concordance_test'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'concordance_test_nonce' ) ) {
                $result = $this->client->getGroups();

                if (is_wp_error($result)) {
                    echo '<div class="notice notice-error"><p><strong>' . esc_html__('Error:', 'concordance') . '</strong> '
                         . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    $groups = GroupListing::collectionFromResponse($result);
                    echo '<div class="notice notice-success"><p>'
                            /* translators: %d: number of groups returned by the API */
                         . sprintf(esc_html__('Success! Received %d group(s) from the API.', 'concordance'), count($groups))
                         . '</p></div>';

                    // Log the first raw result to the browser console. Useful
                    // for inspecting the actual API payload shape (e.g. when
                    // working out which keys to enable in Visible Fields).
                    $firstRaw = $this->extractFirstRawResult($result);
                    if ($firstRaw !== null) {
                        $payload = wp_json_encode($firstRaw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if ($payload !== false) {
                            echo '<script>console.log('
                                . wp_json_encode(__('[Concordance] First API result:', 'concordance'))
                                . ', '
                                . $payload
                                . ');</script>';
                            echo '<p class="description">'
                                . esc_html__('The first result has been logged to the browser console — open DevTools to inspect the raw API payload.', 'concordance')
                                . '</p>';
                        }
                    }
                }
            }
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Error:', 'concordance') . '</strong> '
                 . esc_html($e->getMessage()) . '</p></div>';
        }

        $testUrl = wp_nonce_url(
            add_query_arg('concordance_test', '1', admin_url('admin.php?page=concordance')),
            'concordance_test_nonce'
        );
        ?>
        <p>
            <a href="<?php echo esc_url($testUrl); ?>" class="button button-secondary">
                <?php esc_html_e('Test API Connection', 'concordance'); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Pull the first raw record from an API response.
     *
     * Mirrors the logic in GroupListing::collectionFromResponse so we
     * unwrap "results" / "data" envelopes the same way.
     *
     * @param mixed $response The decoded API response.
     * @return array<string, mixed>|null The first raw record, or null if none.
     */
    private function extractFirstRawResult(mixed $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }

        $items = $response;

        if (isset($response['results']) && is_array($response['results'])) {
            $items = $response['results'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            $items = $response['data'];
        }

        // Single-record envelope: a record object rather than a list.
        if (!empty($items) && !isset($items[0]) && isset($items['id'])) {
            return $items;
        }

        if (empty($items) || !isset($items[0]) || !is_array($items[0])) {
            return null;
        }

        return $items[0];
    }

    /**
     * Render the usage reference table.
     *
     * @return void
     */
    private function renderUsageTable(): void
    {
        ?>
        <table class="widefat striped" style="max-width:700px">
            <thead>
                <tr>
                    <th><?php esc_html_e('Feature', 'concordance'); ?></th>
                    <th><?php esc_html_e('Example', 'concordance'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php esc_html_e('REST Proxy (all)', 'concordance'); ?></td>
                    <td><code>/wp-json/<?php echo esc_html(ConcordanceConfiguration::REST_NAMESPACE); ?>/groups</code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('REST Proxy (single)', 'concordance'); ?></td>
                    <td><code>/wp-json/<?php echo esc_html(ConcordanceConfiguration::REST_NAMESPACE); ?>/groups/{id}</code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('PHP (via container)', 'concordance'); ?></td>
                    <td><code>concordance()->get(ApiClient::class)->getGroups();</code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('PHP (cached)', 'concordance'); ?></td>
                    <td><code>concordance()->get(ApiCache::class)->getGroups();</code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('WP-CLI (list)', 'concordance'); ?></td>
                    <td><code>wp concordance list</code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('WP-CLI (test)', 'concordance'); ?></td>
                    <td><code>wp concordance test</code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('WP-CLI (cache)', 'concordance'); ?></td>
                    <td><code>wp concordance flush-cache</code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('WP-CLI (config)', 'concordance'); ?></td>
                    <td><code>wp concordance config</code></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

}
