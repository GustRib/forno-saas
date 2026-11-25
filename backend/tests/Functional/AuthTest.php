<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * FNO-010 (login) e FNO-011 (estabelecimento + proprietário).
 */
final class AuthTest extends ApiTestCase
{
    public function testRegistrationCreatesTenantWithOwnerAndDefaultUnits(): void
    {
        $session = $this->registerTenant('Padaria Central', 'dono@central.test');

        self::assertSame('dono@central.test', $session['user']['email']);
        self::assertSame('Padaria Central', $session['activeTenant']['name']);
        self::assertSame('OWNER', $session['activeTenant']['role']);
        self::assertSame('America/Sao_Paulo', $session['activeTenant']['timezone']);
        self::assertContains('catalog.write', $session['activeTenant']['permissions']);
        self::assertFalse($session['fiscal']['available']);

        $codes = array_column($this->api('GET', '/api/v1/units')['data'], 'code');
        sort($codes);
        self::assertSame(['DZ', 'G', 'KG', 'L', 'ML', 'UN'], $codes);
    }

    public function testPasswordIsStoredHashed(): void
    {
        $this->registerTenant('Padaria Hash', 'hash@padaria.test', 'minha-senha-123');

        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $hash = $connection->fetchOne('SELECT password_hash FROM users WHERE email = ?', ['hash@padaria.test']);

        self::assertNotSame('minha-senha-123', $hash);
        self::assertTrue(password_verify('minha-senha-123', $hash));
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->registerTenant('Padaria A', 'repetido@padaria.test');
        $this->logout();

        $response = $this->api('POST', '/api/v1/auth/register', [
            'bakeryName' => 'Padaria B',
            'name' => 'Outra pessoa',
            'email' => 'REPETIDO@padaria.test',
            'password' => 'senha-segura-123',
        ]);

        $this->assertStatus(409);
        self::assertSame('EMAIL_ALREADY_USED', $response['error']['code']);
    }

    public function testRegistrationValidatesFields(): void
    {
        $response = $this->api('POST', '/api/v1/auth/register', [
            'bakeryName' => '',
            'name' => 'Fulano',
            'email' => 'nao-e-email',
            'password' => '123',
        ]);

        $this->assertStatus(422);
        self::assertSame('VALIDATION_FAILED', $response['error']['code']);
        self::assertArrayHasKey('bakeryName', $response['error']['details']['fields']);
        self::assertArrayHasKey('email', $response['error']['details']['fields']);
        self::assertArrayHasKey('password', $response['error']['details']['fields']);
    }

    public function testLoginAndMe(): void
    {
        $this->registerTenant('Padaria Login', 'login@padaria.test');

        $response = $this->login('LOGIN@padaria.test');
        $this->assertStatus(200);
        self::assertSame('Padaria Login', $response['data']['activeTenant']['name']);

        $me = $this->api('GET', '/api/v1/auth/me');
        $this->assertStatus(200);
        self::assertSame('login@padaria.test', $me['data']['user']['email']);
    }

    public function testWrongPasswordAndUnknownEmailGiveSameGenericError(): void
    {
        $this->registerTenant('Padaria Erro', 'erro@padaria.test');

        $wrongPassword = $this->login('erro@padaria.test', 'senha-errada-000');
        $this->assertStatus(401);
        $unknown = $this->login('ninguem@padaria.test', 'senha-errada-000');
        $this->assertStatus(401);

        self::assertSame($wrongPassword, $unknown);
        self::assertSame('E-mail ou senha inválidos.', $unknown['error']['message']);
    }

    public function testLoginIsRateLimited(): void
    {
        // E-mail único: o limitador usa cache persistente (limpo a cada execução da suíte).
        $target = 'alvo-'.bin2hex(random_bytes(4)).'@padaria.test';

        for ($i = 0; $i < 5; ++$i) {
            $this->login($target, 'tentativa-'.$i);
            $this->assertStatus(401);
        }

        $response = $this->login($target, 'tentativa-final');
        $this->assertStatus(429);
        self::assertSame('TOO_MANY_LOGIN_ATTEMPTS', $response['error']['code']);
    }

    public function testProtectedEndpointRequiresAuthentication(): void
    {
        $response = $this->api('GET', '/api/v1/auth/me');

        $this->assertStatus(401);
        self::assertSame('UNAUTHENTICATED', $response['error']['code']);
    }

    public function testLogoutEndsSession(): void
    {
        $this->registerTenant('Padaria Logout', 'logout@padaria.test');

        $this->api('POST', '/api/v1/auth/logout');
        $this->assertStatus(204);

        $this->api('GET', '/api/v1/auth/me');
        $this->assertStatus(401);
    }

    public function testDisabledUserCannotLogIn(): void
    {
        $this->registerTenant('Padaria Desativa', 'dono@desativa.test');
        $this->addMember('caixa@desativa.test', 'CASHIER');

        $members = $this->api('GET', '/api/v1/members')['data'];
        $cashier = array_values(array_filter($members, static fn ($m) => 'caixa@desativa.test' === $m['email']))[0];
        $this->api('PUT', '/api/v1/members/'.$cashier['id'], ['role' => 'CASHIER', 'status' => 'DISABLED']);
        $this->assertStatus(200);

        // Credenciais corretas, mas sem associação ativa: autentica sem tenant.
        $this->login('caixa@desativa.test');
        $this->assertStatus(200);
        $this->api('GET', '/api/v1/products');
        $this->assertStatus(403);
    }
}
