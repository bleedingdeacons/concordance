<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Api;

use BleedingDeacons\WpMocks\Doubles\FakeWpHttp;
use BleedingDeacons\WpMocks\WpState;
use Concordance\Api\ApiClient;
use Concordance\Common\ConcordanceConfiguration;
use Concordance\Common\Encryption;
use WP_Error;

/*
 * Tests for ApiClient, the HTTP client for the AAGBDB Groups API, driven
 * through wp-mocks' FakeWpHttp.
 */

covers(\Concordance\Api\ApiClient::class);

function stubbedApiClient(): ApiClient
{
    return new ApiClient('test-key', 'https://api.test', 15);
}

function respondWith(int $code, string $body): void
{
    FakeWpHttp::pushResponse($code, $body);
}

beforeEach(function () {
    // The TestCase's setUp() clears WpState; the HTTP double is separate state.
    FakeWpHttp::reset();
});

it('returns the decoded array from getGroups on success', function () {
    respondWith(200, '[{"groupName":"Test"}]');
    $result = stubbedApiClient()->getGroups(['page' => 2]);

    expect($result)->toBe([['groupName' => 'Test']])
        // Query args were appended to the URL.
        ->and(FakeWpHttp::sentUrl(0))->toContain('page=2');
});

it('encodes the id in getGroup', function () {
    respondWith(200, '{"groupName":"One"}');
    $result = stubbedApiClient()->getGroup('42/x');

    expect($result)->toBe(['groupName' => 'One'])
        ->and(FakeWpHttp::sentUrl(0))->toContain('42%2Fx');
});

it('returns a WP_Error response directly', function () {
    FakeWpHttp::push(new WP_Error('http_fail', 'boom'));
    $result = stubbedApiClient()->getGroups();

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and(stubbedApiClient()->getLastResponse())->toBe([]);
});

it('turns a non-2xx status into an API error', function () {
    respondWith(503, 'upstream down');
    $result = stubbedApiClient()->getGroups();

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('concordance_api_error');
});

it('turns invalid JSON into a JSON error', function () {
    respondWith(200, 'not-json');
    $result = stubbedApiClient()->getGroups();

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('concordance_json_error');
});

it('sends a JSON body on post', function () {
    respondWith(200, '{"ok":true}');
    stubbedApiClient()->post('/things/', ['a' => 1]);

    expect(FakeWpHttp::sentArgs(0)['method'])->toBe('POST')
        ->and(FakeWpHttp::sentArgs(0)['body'])->toBe('{"a":1}');
});

it('has a generic get helper', function () {
    respondWith(200, '{"x":1}');
    expect(stubbedApiClient()->get('/custom/'))->toBe(['x' => 1]);
});

it('reflects success in testConnection', function () {
    respondWith(200, '[]');
    expect(stubbedApiClient()->testConnection())->toBeTrue();

    FakeWpHttp::push(new WP_Error('x', 'y'));
    expect(stubbedApiClient()->testConnection())->toBeFalse();
});

it('keeps the last response after a success', function () {
    respondWith(200, '[]');
    $client = stubbedApiClient();
    $client->getGroups();
    expect($client->getLastResponse())->not->toBe([]);
});

// ── constructor falling back to stored options ───────────────────────

it('reads the stored options when constructed with an empty key', function () {
    WpState::$options = [
        ConcordanceConfiguration::OPTION_API_KEY => '',
        ConcordanceConfiguration::OPTION_API_BASE_URL => 'https://stored.test',
        ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT => 45,
    ];
    respondWith(200, '[]');

    $client = new ApiClient(); // all nulls → read from options
    $client->getGroups();
    expect(FakeWpHttp::sentUrl(0))->toStartWith('https://stored.test');
});

it('decrypts the stored API key', function () {
    $encrypted = (new Encryption())->encrypt('secret-key');
    WpState::$options = [ConcordanceConfiguration::OPTION_API_KEY => $encrypted];
    respondWith(200, '[]');

    $client = new ApiClient(null, 'https://api.test', 10);
    $client->getGroups();
    expect(FakeWpHttp::sentArgs(0)['headers']['X-Api-Key'])->toBe('secret-key');
});
