<?php

declare(strict_types=1);

namespace Concordance\Admin\GroupListings;

if (!defined('ABSPATH')) {
    exit;
}

use Concordance\Api\ApiCache;
use Concordance\Common\ConcordanceConfiguration;
use Concordance\Models\GroupListing;

use function absint;
use function add_action;
use function admin_url;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function esc_url_raw;
use function get_current_screen;
use function get_option;
use function is_wp_error;
use function sanitize_text_field;
use function update_option;
use function wp_add_dashboard_widget;
use function wp_die;
use function wp_json_encode;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Group Listing Dashboard Widget
 *
 * Adds a dashboard panel listing all groups from the AAGBDB API
 * via the Concordance plugin's ApiCache and GroupListing model.
 *
 * Which fields appear on each card is controlled by the
 * concordance_dashboard_fields option, configured on the Concordance
 * settings page. The group name is always shown.
 */
class GroupListingDashboard
{
    private ApiCache $apiCache;

    /**
     * Fields that look like URLs and should be rendered as links.
     */
    private const URL_FIELDS = [
        'website', 'web', 'url', 'link', 'online_link', 'zoom',
    ];

    /**
     * Constructor
     *
     * @param ApiCache $apiCache Concordance API cache service
     */
    public function __construct(ApiCache $apiCache)
    {
        $this->apiCache = $apiCache;

        add_action('wp_dashboard_setup', [$this, 'registerDashboardWidget']);
        add_action('admin_head', [$this, 'addDashboardStyles']);
        add_action('admin_post_concordance_set_intergroup', [$this, 'handleSetIntergroup']);
        add_action('wp_ajax_concordance_filter_intergroup', [$this, 'ajaxFilterIntergroup']);
    }

    /**
     * Register the dashboard widget
     */
    public function registerDashboardWidget(): void
    {
        wp_add_dashboard_widget(
            'concordance_group_listings_dashboard',
            'National Group Listings',
            [$this, 'renderDashboardWidget'],
            null,
            null,
            'normal',
            'high'
        );
    }

    /**
     * Render the dashboard widget content
     */
    public function renderDashboardWidget(): void
    {
        $response = $this->apiCache->getGroups();

        if (is_wp_error($response)) {
            echo '<div class="gl-error">';
            echo '<span class="dashicons dashicons-warning"></span> ';
            echo '<span>' . esc_html($response->get_error_message()) . '</span>';
            echo '</div>';
            return;
        }

        $allGroups = GroupListing::collectionFromResponse($response);

        if (empty($allGroups)) {
            echo '<p class="gl-empty">No groups found from the AAGBDB API.</p>';
            return;
        }

        // Build the (intergroup ID => raw name) choice list from the FULL
        // set, so the dropdown shows every option regardless of the active
        // filter.
        $choices = $this->buildIntergroupChoices($allGroups);

        // Current site-wide filter (0 = no filter, show all).
        $filterId = (int) get_option(
            ConcordanceConfiguration::OPTION_INTERGROUP_ID,
            ConcordanceConfiguration::INTERGROUP_ID_ALL
        );

        // Apply the filter to produce the displayed set.
        if ($filterId === ConcordanceConfiguration::INTERGROUP_ID_ALL) {
            $groups = $allGroups;
        } else {
            $groups = array_values(array_filter(
                $allGroups,
                static fn(GroupListing $g) => $g->getIntergroupId() === $filterId
            ));
        }

        // Sort by day, then time, then name
        GroupListing::sort($groups, 'day,time,name');

        echo '<div class="gl-dashboard-widget">';

        // First row: dropdown form + count.
        $this->renderIntergroupSelector($choices, $filterId, count($groups));

        // Swappable region — replaced via AJAX when the dropdown changes.
        echo '<div class="gl-cards" id="gl-cards" data-concordance-cards>';
        $this->renderCards($groups);
        echo '</div>';

        echo '</div>';

        // Wire up the dropdown AJAX. MUST be after the cards container is
        // in the DOM, otherwise the IIFE's element lookups return null.
        $this->renderInlineScript();
    }

    /**
     * Render the cards portion of the widget (the part that gets swapped
     * by AJAX when the intergroup filter changes).
     *
     * @param GroupListing[] $groups Already filtered and sorted.
     * @return void
     */
    private function renderCards(array $groups): void
    {
        if (empty($groups)) {
            echo '<p class="gl-empty">' . esc_html__(
                'No groups match the selected intergroup. Choose a different intergroup above.',
                'concordance'
            ) . '</p>';
            return;
        }

        $visibleFields = $this->getVisibleFields();

        foreach ($groups as $group) {
            $this->renderGroupCard($group, $visibleFields);
        }
    }

    /**
     * Build a deduplicated, sorted map of intergroup ID => raw (uppercase) name.
     *
     * @param GroupListing[] $groups
     * @return array<int, string>
     */
    private function buildIntergroupChoices(array $groups): array
    {
        $choices = [];

        foreach ($groups as $group) {
            $id   = $group->getIntergroupId();
            $name = $group->getIntergroupName();

            if ($id <= 0) {
                continue;
            }

            // First occurrence wins.
            if (!isset($choices[$id])) {
                $choices[$id] = $name !== '' ? $name : ('Intergroup #' . $id);
            }
        }

        // Sort by Title-Cased name, case-insensitive, natural order.
        uasort($choices, static function (string $a, string $b): int {
            return strnatcasecmp(GroupListing::titleCase($a), GroupListing::titleCase($b));
        });

        return $choices;
    }

    /**
     * Render the interactive intergroup-selector row.
     *
     * Posts to admin-post.php which writes the site-wide option and
     * redirects back to the dashboard. CSRF-protected with a nonce.
     *
     * @param array<int, string> $choices Map of intergroup ID => raw name.
     * @param int                $current Currently saved filter ID.
     * @param int                $count   Number of groups in the filtered set.
     * @return void
     */
    private function renderIntergroupSelector(array $choices, int $current, int $count): void
    {
        $actionUrl = admin_url('admin-post.php');
        $returnUrl = admin_url('index.php'); // WordPress dashboard
        $selectId  = 'concordance-dashboard-intergroup';
        $formId    = 'concordance-dashboard-form';
        $countId   = 'concordance-dashboard-count';

        echo '<div class="gl-summary">';

        echo '<form method="post" action="' . esc_url($actionUrl) . '" id="' . esc_attr($formId) . '" class="gl-summary-item gl-summary-form">';
        echo '<input type="hidden" name="action" value="concordance_set_intergroup" />';
        echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr($returnUrl) . '" />';
        wp_nonce_field('concordance_set_intergroup', '_concordance_nonce');

        echo '<label for="' . esc_attr($selectId) . '" class="gl-summary-label">'
            . esc_html__('Intergroup:', 'concordance') . '</label> ';

        echo '<select name="intergroup_id" id="' . esc_attr($selectId) . '">';

        // "All" sentinel always present.
        $allSelected = $current === ConcordanceConfiguration::INTERGROUP_ID_ALL ? ' selected' : '';
        echo '<option value="' . esc_attr((string) ConcordanceConfiguration::INTERGROUP_ID_ALL) . '"' . $allSelected . '>'
            . esc_html__('All intergroups', 'concordance')
            . '</option>';

        // If the saved value isn't in $choices (e.g. that intergroup no
        // longer appears in the API data), keep it visible so it isn't
        // silently dropped.
        if ($current !== ConcordanceConfiguration::INTERGROUP_ID_ALL && !isset($choices[$current])) {
            echo '<option value="' . esc_attr((string) $current) . '" selected>'
                . sprintf(
                    /* translators: %d: intergroup ID */
                    esc_html__('Intergroup #%d (currently saved)', 'concordance'),
                    $current
                )
                . '</option>';
        }

        foreach ($choices as $id => $rawName) {
            $selected = $current === $id ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $id) . '"' . $selected . '>'
                . esc_html(GroupListing::titleCase($rawName))
                . '</option>';
        }

        echo '</select>';

        // No-JS fallback submit button (also shown briefly before JS binds).
        echo ' <noscript><button type="submit" class="button button-small">'
            . esc_html__('Apply', 'concordance') . '</button></noscript>';

        // Inline status indicator (hidden until the request is in flight).
        echo ' <span class="gl-summary-status" aria-live="polite" hidden></span>';

        echo '</form>';

        echo '<div class="gl-summary-item gl-summary-count-wrap">'
            . '<span class="gl-summary-count" id="' . esc_attr($countId) . '">' . esc_html((string) $count) . '</span> '
            . esc_html__('Groups', 'concordance')
            . '</div>';

        echo '</div>';
    }

    /**
     * Emit the inline JS that hijacks the dropdown submission and swaps
     * the cards via AJAX.
     *
     * MUST be called AFTER the form, select, and cards container have
     * already been emitted to the page, otherwise the IIFE's element
     * lookups will return null and silently bail out.
     *
     * @return void
     */
    private function renderInlineScript(): void
    {
        $ajaxUrl  = admin_url('admin-ajax.php');
        $selectId = 'concordance-dashboard-intergroup';
        $formId   = 'concordance-dashboard-form';
        $countId  = 'concordance-dashboard-count';
        ?>
        <script>
        (function () {
            function init() {
                var form    = document.getElementById(<?php echo wp_json_encode($formId); ?>);
                var select  = document.getElementById(<?php echo wp_json_encode($selectId); ?>);
                var cards   = document.querySelector('[data-concordance-cards]');
                var countEl = document.getElementById(<?php echo wp_json_encode($countId); ?>);
                if (!form || !select || !cards || form.dataset.concordanceBound) { return; }
                form.dataset.concordanceBound = '1';

                var ajaxUrl = <?php echo wp_json_encode($ajaxUrl); ?>;
                var status  = form.querySelector('.gl-summary-status');

                function showStatus(text, isError) {
                    if (!status) { return; }
                    status.textContent = text;
                    status.hidden = false;
                    status.classList.toggle('is-error', !!isError);
                }
                function clearStatus() {
                    if (!status) { return; }
                    status.hidden = true;
                    status.textContent = '';
                    status.classList.remove('is-error');
                }

                // Intercept the form's submit too, so the no-JS Apply
                // button (briefly visible during page load) also uses AJAX
                // when JS is available.
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    runUpdate();
                });
                select.addEventListener('change', runUpdate);

                function runUpdate() {
                    var body = new FormData(form);
                    // admin-ajax expects the canonical wp_ajax action name.
                    body.set('action', 'concordance_filter_intergroup');

                    select.disabled = true;
                    cards.classList.add('is-loading');
                    showStatus(<?php echo wp_json_encode(__('Updating…', 'concordance')); ?>, false);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: body
                    }).then(function (res) {
                        return res.json().then(function (json) { return { ok: res.ok, json: json }; });
                    }).then(function (result) {
                        if (!result.ok || !result.json || result.json.success !== true) {
                            var msg = (result.json && result.json.data && result.json.data.message)
                                ? result.json.data.message
                                : <?php echo wp_json_encode(__('Couldn’t update — please try again.', 'concordance')); ?>;
                            throw new Error(msg);
                        }
                        var data = result.json.data || {};
                        cards.innerHTML = data.html || '';
                        if (countEl && typeof data.count === 'number') {
                            countEl.textContent = String(data.count);
                        }
                        clearStatus();
                    }).catch(function (err) {
                        showStatus(err && err.message ? err.message : <?php echo wp_json_encode(__('Couldn’t update — please try again.', 'concordance')); ?>, true);
                    }).then(function () {
                        select.disabled = false;
                        cards.classList.remove('is-loading');
                    });
                }
            }

            // The script element is emitted after the cards container, so
            // the elements should already be present. Belt and braces:
            // wait for DOMContentLoaded if we somehow got here too early.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
        <?php
    }

    /**
     * Handle the dashboard intergroup-filter form submission.
     *
     * Verifies the nonce and capability, writes the site-wide option,
     * then redirects back to the dashboard. The chosen value is
     * `absint()`-sanitised; unknown IDs simply produce an empty filtered
     * set on the next render (no error).
     *
     * @return void
     */
    public function handleSetIntergroup(): void
    {
        if (!current_user_can('edit_dashboard') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'concordance'), '', ['response' => 403]);
        }

        $nonce = isset($_POST['_concordance_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['_concordance_nonce']))
            : '';
        if (!wp_verify_nonce($nonce, 'concordance_set_intergroup')) {
            wp_die(esc_html__('Security check failed.', 'concordance'), '', ['response' => 403]);
        }

        $value = isset($_POST['intergroup_id']) ? absint($_POST['intergroup_id']) : 0;
        update_option(ConcordanceConfiguration::OPTION_INTERGROUP_ID, $value);

        $referer = isset($_POST['_wp_http_referer'])
            ? esc_url_raw(wp_unslash($_POST['_wp_http_referer']))
            : admin_url('index.php');

        wp_safe_redirect($referer);
        exit;
    }

    /**
     * AJAX endpoint — apply a new intergroup filter and return the
     * rendered cards HTML so the client can swap it in place.
     *
     * Echoes a JSON response shaped as:
     *   { success: true, data: { html: "...", count: 5 } }
     * on success, or the wp_send_json_error shape on failure.
     *
     * @return void
     */
    public function ajaxFilterIntergroup(): void
    {
        if (!current_user_can('edit_dashboard') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'concordance')], 403);
        }

        $nonce = isset($_POST['_concordance_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['_concordance_nonce']))
            : '';
        if (!wp_verify_nonce($nonce, 'concordance_set_intergroup')) {
            wp_send_json_error(['message' => __('Security check failed.', 'concordance')], 403);
        }

        $value = isset($_POST['intergroup_id']) ? absint($_POST['intergroup_id']) : 0;
        update_option(ConcordanceConfiguration::OPTION_INTERGROUP_ID, $value);

        // Re-fetch groups and apply the new filter.
        $response = $this->apiCache->getGroups();
        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => $response->get_error_message(),
            ], 500);
        }

        $allGroups = GroupListing::collectionFromResponse($response);

        if ($value === ConcordanceConfiguration::INTERGROUP_ID_ALL) {
            $groups = $allGroups;
        } else {
            $groups = array_values(array_filter(
                $allGroups,
                static fn(GroupListing $g) => $g->getIntergroupId() === $value
            ));
        }

        GroupListing::sort($groups, 'day,time,name');

        ob_start();
        $this->renderCards($groups);
        $html = ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'count' => count($groups),
        ]);
    }

    /**
     * Resolve the list of fields to display, filtered through the
     * DASHBOARD_FIELDS whitelist and ordered by it.
     *
     * @return array<string, string> Field key => human label
     */
    private function getVisibleFields(): array
    {
        $stored = get_option(
            ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS,
            ConcordanceConfiguration::DEFAULT_DASHBOARD_FIELDS
        );
        $selected = is_array($stored) ? $stored : ConcordanceConfiguration::DEFAULT_DASHBOARD_FIELDS;

        $visible = [];
        foreach (ConcordanceConfiguration::DASHBOARD_FIELDS as $key => $label) {
            if (in_array($key, $selected, true)) {
                $visible[$key] = $label;
            }
        }

        return $visible;
    }

    /**
     * Render a single group card.
     *
     * The card header always shows the group name. The body renders only
     * the fields that have been enabled in the settings page, in the order
     * defined by ConcordanceConfiguration::DASHBOARD_FIELDS.
     *
     * @param GroupListing $group         Group listing model
     * @param array<string, string> $visibleFields key => human label
     */
    private function renderGroupCard(GroupListing $group, array $visibleFields): void
    {
        $raw  = $group->getRaw();
        $name = $this->resolveName($raw);

        echo '<div class="gl-card">';

        // --- Header ---
        echo '<div class="gl-card-header">';
        echo '<div class="gl-card-title">';
        echo '<strong>' . esc_html($name ?: ((string) ($raw['groupName'] ?? 'Unknown Group'))) . '</strong>';
        echo '</div>';
        echo '</div>';

        // --- Body ---
        $fields = $this->getDisplayFields($raw, $visibleFields);

        if (!empty($fields)) {
            echo '<div class="gl-card-content">';

            foreach ($fields as $label => $value) {
                $isFullWidth = strlen((string) $value) > 80;
                $fieldClass = $isFullWidth ? 'gl-card-field gl-card-field-full' : 'gl-card-field';

                echo '<div class="' . esc_attr($fieldClass) . '">';
                echo '<div class="gl-field-label">' . esc_html($label) . '</div>';
                echo '<div class="gl-field-value">';
                echo $this->renderFieldValue($label, $value);
                echo '</div>';
                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div>'; // .gl-card
    }

    /**
     * Resolve the group name from common API key patterns.
     *
     * @param array $raw Raw API data
     * @return string
     */
    private function resolveName(array $raw): string
    {
        return (string) ($raw['groupName'] ?? $raw['name'] ?? $raw['title'] ?? '');
    }

    /**
     * Build a human-labelled array of fields to display, filtered to the
     * user's selected visible fields.
     *
     * Empty values, nested arrays/objects, and boolean false are skipped
     * so an enabled-but-empty field doesn't render an empty row.
     *
     * @param array<string, mixed>  $raw           Raw API data
     * @param array<string, string> $visibleFields key => label, in display order
     * @return array<string, string>               Label => display value
     */
    private function getDisplayFields(array $raw, array $visibleFields): array
    {
        $fields = [];

        foreach ($visibleFields as $key => $label) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            $value = $raw[$key];

            // Skip empty, null, nested arrays/objects
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }

            // Skip boolean false (e.g. an unset wheelchair flag)
            if ($value === false) {
                continue;
            }

            // Convert boolean true to 'Yes'
            if ($value === true) {
                $value = 'Yes';
            }

            $fields[$label] = (string) $value;
        }

        return $fields;
    }

    /**
     * Render a field value, detecting URLs and email addresses.
     *
     * @param string $label Human-readable label
     * @param string $value Raw value
     * @return string HTML
     */
    private function renderFieldValue(string $label, string $value): string
    {
        // Detect URL fields
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">' . esc_html($value) . '</a>';
        }

        // Detect email fields
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
        }

        return esc_html($value);
    }

    /**
     * Add custom styles for the dashboard widget
     */
    public function addDashboardStyles(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'dashboard') {
            return;
        }

        echo '<style>
            .gl-dashboard-widget {
                margin: -12px -12px 0 -12px;
            }

            .gl-summary {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                background: #f0f6fc;
                border-bottom: 1px solid #c3c4c7;
                margin-bottom: 12px;
            }

            .gl-summary-item {
                font-size: 12px;
                color: #50575e;
                font-weight: 500;
            }

            .gl-summary-form {
                display: flex;
                align-items: center;
                gap: 6px;
                margin: 0;
            }

            .gl-summary-form select {
                font-size: 13px;
                max-width: 240px;
            }

            .gl-summary-label {
                font-weight: 600;
            }

            .gl-summary-count-wrap {
                margin-left: auto;
            }

            .gl-summary-count {
                font-size: 16px;
                font-weight: 700;
                color: #1d2327;
                margin-right: 2px;
            }

            .gl-summary-status {
                font-size: 12px;
                color: #50575e;
                font-style: italic;
                margin-left: 6px;
            }

            .gl-summary-status.is-error {
                color: #b32d2e;
                font-style: normal;
            }

            .gl-cards.is-loading {
                opacity: 0.55;
                transition: opacity 0.15s;
                pointer-events: none;
            }

            .gl-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                margin: 0 0 8px 0;
                transition: box-shadow 0.2s;
            }

            .gl-card:hover {
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .gl-card:last-child {
                margin-bottom: 0;
            }

            .gl-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 16px;
                background: #f9f9f9;
                border-bottom: 1px solid #e0e0e0;
                border-radius: 4px 4px 0 0;
                gap: 12px;
            }

            .gl-card-title {
                flex: 1;
                min-width: 0;
                font-size: 14px;
            }

            .gl-card-title a {
                text-decoration: none;
                color: #2271b1;
            }

            .gl-card-title a:hover {
                color: #135e96;
            }

            .gl-card-content {
                padding: 10px 16px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .gl-card-field {
                min-width: 0;
            }

            .gl-card-field-full {
                grid-column: 1 / -1;
            }

            .gl-field-label {
                font-size: 10px;
                text-transform: uppercase;
                color: #666;
                font-weight: 600;
                letter-spacing: 0.5px;
                margin-bottom: 3px;
            }

            .gl-field-value {
                font-size: 13px;
                line-height: 1.5;
                word-wrap: break-word;
            }

            .gl-field-value a {
                color: #2271b1;
                text-decoration: none;
            }

            .gl-field-value a:hover {
                color: #135e96;
                text-decoration: underline;
            }

            .gl-no-data {
                color: #999;
                font-style: italic;
            }

            .gl-error {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 12px 16px;
                background: #fcf0f1;
                border-left: 4px solid #d63638;
                color: #d63638;
                font-size: 13px;
            }

            .gl-empty {
                color: #666;
                font-style: italic;
                padding: 8px 0;
            }

            @media (max-width: 600px) {
                .gl-card-content {
                    grid-template-columns: 1fr;
                }
            }
        </style>';
    }
}
