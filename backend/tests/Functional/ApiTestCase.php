<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base para testes de API: requisições JSON, cadastro de tenants e troca de
 * usuário. Cada teste roda numa transação revertida (DAMA).
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers HTTP_* server keys
     *
     * @return array<string, mixed>
     */
    protected function api(string $method, string $uri, ?array $body = null, array $headers = []): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers];
        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        $content = (string) $this->client->getResponse()->getContent();
        if ('' === $content) {
            return [];
        }

        return json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }

    protected function responseStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function assertStatus(int $expected): void
    {
        self::assertSame($expected, $this->responseStatus(), (string) $this->client->getResponse()->getContent());
    }

    /**
     * Cadastra uma padaria + proprietário e deixa o cliente autenticado como ele.
     *
     * @return array<string, mixed> payload da sessão
     */
    protected function registerTenant(string $bakery, string $email, string $password = 'senha-segura-123'): array
    {
        $this->logout();
        $response = $this->api('POST', '/api/v1/auth/register', [
            'bakeryName' => $bakery,
            'name' => 'Dono '.$bakery,
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertStatus(201);

        return $response['data'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function login(string $email, string $password = 'senha-segura-123'): array
    {
        $this->logout();

        return $this->api('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    protected function logout(): void
    {
        $this->client->getCookieJar()->clear();
    }

    /**
     * Adiciona um membro ao tenant do usuário logado (precisa ser OWNER).
     */
    protected function addMember(string $email, string $role, string $password = 'senha-segura-123'): void
    {
        $this->api('POST', '/api/v1/members', [
            'name' => 'Membro '.$role,
            'email' => $email,
            'role' => $role,
            'password' => $password,
        ]);
        $this->assertStatus(201);
    }

    protected function unitId(string $code): string
    {
        foreach ($this->api('GET', '/api/v1/units')['data'] as $unit) {
            if ($unit['code'] === $code) {
                return $unit['id'];
            }
        }

        self::fail("Unidade {$code} não encontrada.");
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function createProduct(array $overrides = []): array
    {
        $response = $this->api('POST', '/api/v1/products', [
            'name' => 'Pão francês',
            'saleUnitId' => $this->unitId('KG'),
            'productionType' => 'MANUFACTURED',
            'salePrice' => '18.90',
            ...$overrides,
        ]);
        $this->assertStatus(201);

        return $response['data'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function createCategory(string $name, string $kind): array
    {
        $response = $this->api('POST', '/api/v1/categories', ['name' => $name, 'kind' => $kind]);
        $this->assertStatus(201);

        return $response['data'];
    }
}
