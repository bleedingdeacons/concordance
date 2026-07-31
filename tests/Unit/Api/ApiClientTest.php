<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Api;

use BleedingDeacons\WpMocks\Doubles\FakeWpHttp;
use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Concordance\Api\ApiClient;
use Concordance\Common\ConcordanceConfiguration;
use Concordance\Common\Encryption;
use WP_Error;

/**
 * @covers \Concordance\Api\ApiClient
 */
class ApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // parent::setUp() clears WpState; the HTTP double is separate state.
        FakeWpHttp::reset();
    }

    private function client(): ApiClient
    {
        return new ApiClient('test-key', 'https://api.test', 15);
    }

    private function respond(int $code, string $body): void
    {
        FakeWpHttp::pushResponse($code, $body);
    }

    public function testGetGroupsReturnsDecodedArrayOnSuccess(): void
    {
        $this->respond(200, '[{"groupName":"Test"}]');
        $result = $this->client()->getGroups(['page' => 2]);

        $this->assertSame([['groupName' => 'Test']], $result);
        // Query args were appended to the URL.
        $this->assertStringContainsString('page=2', FakeWpHttp::sentUrl(0));
    }

    public function testGetGroupEncodesTheId(): void
    {
        $this->respond(200, '{"groupName":"One"}');
        $result = $this->client()->getGroup('42/x');

        $this->assertSame(['groupName' => 'One'], $result);
        $this->assertStringContainsString('42%2Fx', FakeWpHttp::sentUrl(0));
    }

    public function testWpErrorResponseIsReturnedDirectly(): void
    {
        FakeWpHttp::push(new WP_Error('http_fail', 'boom'));
        $result = $this->client()->getGroups();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame([], $this->client()->getLastResponse());
    }

    public function testNon2xxBecomesApiError(): void
    {
        $this->respond(503, 'upstream down');
        $result = $this->client()->getGroups();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('concordance_api_error', $result->get_error_code());
    }

    public function testInvalidJsonBecomesJsonError(): void
    {
        $this->respond(200, 'not-json');
        $result = $this->client()->getGroups();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('concordance_json_error', $result->get_error_code());
    }

    public function testPostSendsJsonBody(): void
    {
        $this->respond(200, '{"ok":true}');
        $this->client()->post('/things/', ['a' => 1]);

        $this->assertSame('POST', FakeWpHttp::sentArgs(0)['method']);
        $this->assertSame('{"a":1}', FakeWpHttp::sentArgs(0)['body']);
    }

    public function testGetGenericHelper(): void
    {
        $this->respond(200, '{"x":1}');
        $this->assertSame(['x' => 1], $this->client()->get('/custom/'));
    }

    public function testTestConnectionReflectsSuccess(): void
    {
        $this->respond(200, '[]');
        $this->assertTrue($this->client()->testConnection());

        FakeWpHttp::push(new WP_Error('x', 'y'));
        $this->assertFalse($this->client()->testConnection());
    }

    public function testGetLastResponseAfterSuccess(): void
    {
        $this->respond(200, '[]');
        $client = $this->client();
        $client->getGroups();
        $this->assertNotSame([], $client->getLastResponse());
    }

    // ── constructor falling back to stored options ───────────────────────

    public function testConstructorReadsStoredOptionsWithEmptyKey(): void
    {
        WpState::$options = [
            ConcordanceConfiguration::OPTION_API_KEY => '',
            ConcordanceConfiguration::OPTION_API_BASE_URL => 'https://stored.test',
            ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT => 45,
        ];
        $this->respond(200, '[]');

        $client = new ApiClient(); // all nulls → read from options
        $client->getGroups();
        $this->assertStringStartsWith('https://stored.test', FakeWpHttp::sentUrl(0));
    }

    public function testConstructorDecryptsStoredApiKey(): void
    {
        $encrypted = (new Encryption())->encrypt('secret-key');
        WpState::$options = [ConcordanceConfiguration::OPTION_API_KEY => $encrypted];
        $this->respond(200, '[]');

        $client = new ApiClient(null, 'https://api.test', 10);
        $client->getGroups();
        $this->assertSame('secret-key', FakeWpHttp::sentArgs(0)['headers']['X-Api-Key']);
    }
}
