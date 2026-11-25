<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * FNO-004 (health, erros) e proteções transversais da API.
 */
final class PlatformTest extends ApiTestCase
{
    public function testHealthReportsDatabase(): void
    {
        $response = $this->api('GET', '/api/v1/health');

        self::assertSame('ok', $response['data']['checks']['app']);
        self::assertSame('ok', $response['data']['checks']['database']);
        self::assertContains($response['data']['checks']['redis'], ['ok', 'fail']);
    }

    public function testResponsesCarryRequestId(): void
    {
        $this->api('GET', '/api/v1/health', headers: ['HTTP_X_REQUEST_ID' => 'teste-req-12345']);
        self::assertSame('teste-req-12345', $this->client->getResponse()->headers->get('X-Request-Id'));

        $this->api('GET', '/api/v1/health');
        self::assertNotEmpty($this->client->getResponse()->headers->get('X-Request-Id'));
    }

    public function testUnknownRouteUsesErrorEnvelope(): void
    {
        $this->registerTenant('Padaria 404', 'dono@404.test');
        $response = $this->api('GET', '/api/v1/nao-existe');

        $this->assertStatus(404);
        self::assertSame('NOT_FOUND', $response['error']['code']);
    }

    public function testCrossOriginMutationIsRejected(): void
    {
        $this->registerTenant('Padaria CSRF', 'dono@csrf.test');

        $response = $this->api('POST', '/api/v1/categories', ['name' => 'X', 'kind' => 'BOTH'], ['HTTP_ORIGIN' => 'https://site-malicioso.test']);
        $this->assertStatus(403);
        self::assertSame('CSRF_REJECTED', $response['error']['code']);

        $this->api('POST', '/api/v1/categories', ['name' => 'X', 'kind' => 'BOTH'], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $this->assertStatus(403);
    }

    public function testFormPostWithoutJsonIsRejected(): void
    {
        $this->registerTenant('Padaria Form', 'dono@form.test');

        $this->client->request('POST', '/api/v1/categories', ['name' => 'X', 'kind' => 'BOTH']);
        $this->assertStatus(403);
    }

    public function testSameOriginMutationIsAccepted(): void
    {
        $this->registerTenant('Padaria Origem', 'dono@origem.test');

        $this->api('POST', '/api/v1/categories', ['name' => 'Ok', 'kind' => 'BOTH'], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->assertStatus(201);
    }
}
