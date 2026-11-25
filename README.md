# Forno

ERP SaaS para pequenas padarias de operação mista (balcão, mesas, encomendas e produção própria).

A especificação completa está em [`docs/`](docs/README.md). Implementados até agora (ver o
[BACKLOG](docs/BACKLOG.md)): épicos 0–2 — fundação, identidade/multi-tenant e catálogo — e o
**épico 3, estoque e ledger** (FNO-030 a FNO-033) e o **épico 4, receitas e custos** (FNO-040 a FNO-044). A situação de cada história, as decisões e as
pendências estão em [`docs/DECISIONS.md`](docs/DECISIONS.md); os endpoints, em
[`docs/API.md`](docs/API.md).

| Camada   | Tecnologia                                              |
|----------|---------------------------------------------------------|
| Backend  | PHP 8.4, Symfony 7.4 LTS, Doctrine ORM 3, PostgreSQL 17 |
| Frontend | Vue 3, TypeScript, Vite, Pinia, Vue Router              |
| Infra    | Docker Compose, Nginx, PHP-FPM, Redis                   |
| Testes   | PHPUnit 13 + DAMA (transação por teste), Vitest         |

## Subir o ambiente

Pré-requisito: Docker Desktop.

```powershell
docker compose up -d --build
```

```powershell
docker compose exec backend composer install
```

```powershell
docker compose exec backend php bin/console doctrine:migrations:migrate -n
```

Acesse **http://localhost:8080** e clique em *Cadastre sua padaria*. O primeiro usuário vira
proprietário (OWNER) e recebe as unidades padrão (g, kg, ml, L, un, dz).

| Serviço    | Endereço                               |
|------------|----------------------------------------|
| App + API  | http://localhost:8080 (Nginx)          |
| API        | http://localhost:8080/api/v1           |
| Health     | http://localhost:8080/api/v1/health    |
| PostgreSQL | localhost:5432 (forno / forno)         |

O Nginx serve o frontend (Vite, com hot reload) e a API no mesmo domínio, para que o cookie
de sessão seja *same-origin*.

**Conta de demonstração no banco local** (criada durante o desenvolvimento; só existe no seu
volume `db-data`): `demo@forno.test` / `forno-demo-123` — padaria "Padaria Demonstração".

> **Desempenho em dev no Windows:** `vendor/` e `var/` ficam em volumes Docker; a API responde
> em ~1–2 s e o primeiro carregamento completo da página pelo Vite leva ~15–20 s. É custo do bind
> mount, não do código. Para ficar rápido, clone o projeto dentro do WSL2.

## Testes e qualidade

Backend (dentro do container):

```powershell
docker compose exec backend composer test
```

```powershell
docker compose exec backend composer ci
```

`composer ci` roda PHP CS Fixer, PHPStan (nível 6), `lint:container`, validação do mapeamento
Doctrine e PHPUnit. O PHPUnit cria e migra o banco `forno_test` automaticamente.

Frontend (no host, com Node 22+, ou no container `frontend`):

```powershell
cd frontend; npm run lint; npm run type-check; npm test
```

O pipeline de CI está em [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

## Estrutura

```text
backend/src/
  Shared/     envelope de API, erros, request-id, CSRF, decimais, health
  Identity/   usuários, login JSON, sessão
  Tenant/     estabelecimento, vínculos, papéis, permissões, TenantContext
  Catalog/    categorias, unidades (+ conversão), produtos, ingredientes
  Inventory/  locais, ledger de movimentações, saldos, estornos, política de custo WAC-1
  Recipe/     fichas técnicas versionadas, cálculo de custo, snapshots
backend/migrations/   migrations escritas à mão (fonte da verdade do schema)
frontend/src/
  app/        layout, sessão (Pinia), estilos
  modules/    auth, dashboard, catalog, inventory, recipes, members
  shared/     cliente HTTP, componentes de UI, formatação decimal
infrastructure/       Dockerfile PHP, Nginx, init do Postgres
docs/                 especificação original + decisões e API
```

## Regras que o código garante

- **Isolamento de tenant:** o tenant ativo vem da sessão do servidor e de um vínculo ativo em
  `tenant_users`; toda consulta recebe o tenant explicitamente; FKs compostas `(tenant_id, id)`
  impedem referências cruzadas no próprio banco. Testes em `tests/Functional/TenantIsolationTest.php`.
- **Autorização no backend:** `#[IsGranted('catalog.write')]` etc., resolvido pelo `PermissionVoter`
  a partir do papel no tenant ativo. O frontend só esconde botões.
- **Dinheiro e quantidades** em `NUMERIC` e strings decimais de ponta a ponta (bcmath no PHP);
  um preço enviado como número JSON é recusado.
- **Conversão de unidades** só dentro da mesma dimensão (massa, volume, contagem).
- **Login:** senha com hash `auto` do Symfony, mensagem genérica, limite de 5 tentativas/minuto.
- **Estoque:** o ledger `stock_movements` é append-only (o banco recusa UPDATE/DELETE); cada
  movimento atualiza `stock_balances` por trigger na mesma transação; saldo negativo é recusado
  pela aplicação e por `CHECK`; lançamentos concorrentes no mesmo item são serializados por
  bloqueio de linha. Correções são estornos. `php bin/console forno:stock:verify` confere
  saldo × soma do ledger.
- **Custo:** política explícita e versionada `WAC-1` (custo médio ponderado, DECISIONS D-17):
  entradas de ingrediente com custo recalculam a média; toda mudança fica em
  `ingredient_cost_history` (data e origem). Fichas técnicas calculam o custo estimado ao vivo e
  guardam snapshots imutáveis; versões publicadas não mudam (triggers no banco).
- **Aviso fiscal** visível em todas as telas: o MVP não emite documentos fiscais.
