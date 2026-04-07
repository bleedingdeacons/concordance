<?php

declare(strict_types=1);

namespace Concordance\Admin;

if (!defined('ABSPATH')) {
    exit;
}

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

    public function __construct(ApiClient $client, ?Encryption $encryption = null)
    {
        $this->client     = $client;
        $this->encryption = $encryption ?? new Encryption();

        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
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

            <form action="options.php" method="post">
                <?php
                settings_fields('concordance_options');
                do_settings_sections('concordance');
                submit_button(esc_html__('Save Settings', 'concordance'));
                ?>
            </form>

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
