<?php

declare(strict_types=1);

namespace Concordance\Managers;

if (!defined('ABSPATH')) {
    exit;
}

use Concordance\Api\ApiClient;
use Concordance\Api\ApiCache;
use Concordance\Common\ConcordanceConfiguration;
use Concordance\Models\GroupListing;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Exception;

use function add_action;
use function current_user_can;
use function is_wp_error;
use function register_rest_route;
use function sanitize_text_field;

/**
 * Class GroupListingManager
 *
 * Manages groups display via REST API proxy routes.
 */
class GroupListingManager
{
    private ApiClient $client;
    private ApiCache $cache;

    public function __construct(ApiClient $client, ApiCache $cache)
    {
        $this->client = $client;
        $this->cache = $cache;

        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    /**
     * Register REST API proxy routes.
     *
     * @return void
     */
    public function registerRestRoutes(): void
    {
        register_rest_route(ConcordanceConfiguration::REST_NAMESPACE, '/groups', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetGroups'],
            'permission_callback' => function () {
                return current_user_can('read');
            },
            'args' => [
                'page' => [
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value > 0;
                    },
                ],
                'per_page' => [
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value > 0 && (int) $value <= 100;
                    },
                ],
                'intergroup' => [
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return is_numeric($value);
                    },
                ],
            ],
        ]);

        register_rest_route(ConcordanceConfiguration::REST_NAMESPACE, '/groups/(?P<id>[\w-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetSingleGroup'],
            'permission_callback' => function () {
                return current_user_can('read');
            },
            'args' => [
                'id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * REST callback: fetch all groups.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function restGetGroups(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Only forward explicitly allowed query parameters to the upstream API
            $allowedParams = ['page', 'per_page', 'intergroup'];
            $queryArgs = array_intersect_key(
                $request->get_query_params(),
                array_flip($allowedParams)
            );

            $response = $this->cache->getGroups($queryArgs);

            if (is_wp_error($response)) {
                $errorData = $response->get_error_data();
                $status = is_array($errorData) && isset($errorData['status']) ? (int) $errorData['status'] : 502;
                return new WP_REST_Response(
                    ['error' => $response->get_error_message()],
                    $status
                );
            }

            $groups = GroupListing::collectionFromResponse($response);

            return new WP_REST_Response(
                array_map(static fn(GroupListing $g) => $g->toArray(), $groups),
                200
            );

        } catch (Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Concordance REST Error: ' . $e->getMessage());
            return new WP_REST_Response(
                ['error' => 'Internal server error'],
                500
            );
        }
    }

    /**
     * REST callback: fetch a single group.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function restGetSingleGroup(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $response = $this->cache->getGroup($request->get_param('id'));

            if (is_wp_error($response)) {
                $errorData = $response->get_error_data();
                $status = is_array($errorData) && isset($errorData['status']) ? (int) $errorData['status'] : 502;
                return new WP_REST_Response(
                    ['error' => $response->get_error_message()],
                    $status
                );
            }

            $group = GroupListing::fromArray($response);

            return new WP_REST_Response($group->toArray(), 200);

        } catch (Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Concordance REST Error: ' . $e->getMessage());
            return new WP_REST_Response(
                ['error' => 'Internal server error'],
                500
            );
        }
    }

}