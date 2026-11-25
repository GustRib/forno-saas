<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * FR-002 / FR-005: associação explícita e gestão de usuários do tenant.
 */
final class MembersTest extends ApiTestCase
{
    public function testExistingUserCanBelongToTwoTenantsAndSwitch(): void
    {
        $this->registerTenant('Padaria Um', 'multi@padaria.test');
        $this->registerTenant('Padaria Dois', 'dono@dois.test');
        $this->addMember('multi@padaria.test', 'MANAGER');

        $session = $this->login('multi@padaria.test')['data'];
        self::assertCount(2, $session['tenants']);
        self::assertSame('Padaria Um', $session['activeTenant']['name']);

        $dois = array_values(array_filter($session['tenants'], static fn ($t) => 'Padaria Dois' === $t['name']))[0];
        $switched = $this->api('POST', '/api/v1/session/tenant', ['tenantId' => $dois['id']])['data'];
        $this->assertStatus(200);
        self::assertSame('Padaria Dois', $switched['activeTenant']['name']);
        self::assertSame('MANAGER', $switched['activeTenant']['role']);

        // A escolha persiste na sessão.
        self::assertSame('Padaria Dois', $this->api('GET', '/api/v1/auth/me')['data']['activeTenant']['name']);
    }

    public function testCannotAddSameMemberTwice(): void
    {
        $this->registerTenant('Padaria Dup', 'dono@dup.test');
        $this->addMember('caixa@dup.test', 'CASHIER');

        $response = $this->api('POST', '/api/v1/members', ['name' => 'Caixa', 'email' => 'caixa@dup.test', 'role' => 'CASHIER', 'password' => 'senha-segura-123']);
        $this->assertStatus(409);
        self::assertSame('ALREADY_MEMBER', $response['error']['code']);
    }

    public function testNewUserRequiresInitialPassword(): void
    {
        $this->registerTenant('Padaria Senha', 'dono@senha.test');

        $this->api('POST', '/api/v1/members', ['name' => 'Novo', 'email' => 'novo@senha.test', 'role' => 'CASHIER']);
        $this->assertStatus(422);
    }

    public function testOwnerCannotChangeOwnMembership(): void
    {
        $session = $this->registerTenant('Padaria Self', 'dono@self.test');
        $members = $this->api('GET', '/api/v1/members')['data'];
        $own = array_values(array_filter($members, static fn ($m) => $m['userId'] === $session['user']['id']))[0];

        $response = $this->api('PUT', '/api/v1/members/'.$own['id'], ['role' => 'CASHIER', 'status' => 'ACTIVE']);
        $this->assertStatus(422);
        self::assertSame('CANNOT_CHANGE_SELF', $response['error']['code']);
    }

    public function testCannotUpdateMemberOfAnotherTenant(): void
    {
        $this->registerTenant('Padaria X', 'dono@x.test');
        $this->addMember('caixa@x.test', 'CASHIER');
        $memberId = array_values(array_filter($this->api('GET', '/api/v1/members')['data'], static fn ($m) => 'caixa@x.test' === $m['email']))[0]['id'];

        $this->registerTenant('Padaria Y', 'dono@y.test');
        $this->api('PUT', '/api/v1/members/'.$memberId, ['role' => 'MANAGER', 'status' => 'ACTIVE']);
        $this->assertStatus(404);
    }
}
