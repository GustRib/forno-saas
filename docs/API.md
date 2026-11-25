# API.md — Referência da API v1

Base: `/api/v1`. JSON, autenticação por cookie de sessão (`FORNOSESSID`).
Mutações exigem `Content-Type: application/json` (ou `X-Requested-With: XMLHttpRequest`).

## Envelope

```json
{ "data": { } }
{ "data": [ ], "meta": { "page": 1, "limit": 25, "total": 42 } }
{ "error": { "code": "VALIDATION_FAILED", "message": "Alguns campos não são válidos.", "details": { "fields": { "name": ["Informe o nome."] } } } }
```

Códigos de erro frequentes: `UNAUTHENTICATED` (401), `FORBIDDEN` (403), `TENANT_REQUIRED` (403),
`CSRF_REJECTED` (403), `NOT_FOUND` (404), `CONFLICT`/`DUPLICATE_*` (409), `VALIDATION_FAILED` (422),
`TOO_MANY_LOGIN_ATTEMPTS` (429), `INTERNAL_ERROR` (500). Erros do estoque: ver a seção Estoque.

Registros de outro tenant respondem **404** (nunca 403), para não revelar que existem.
Decimais são strings: `"salePrice": "18.90"`, `"averageUnitCost": "0.0045"`.

## Sistema

| Método | Caminho | Acesso | Descrição |
|--------|---------|--------|-----------|
| GET | `/health` | público | `{status, checks: {app, database, redis}}`; 503 se algo falhar |

## Autenticação e sessão

| Método | Caminho | Acesso | Corpo / descrição |
|--------|---------|--------|-------------------|
| POST | `/auth/register` | público | `{bakeryName, name, email, password}` → cria padaria + OWNER e já autentica (201) |
| POST | `/auth/login` | público | `{email, password}` → sessão |
| POST | `/auth/logout` | autenticado | 204 |
| GET | `/auth/me` | autenticado | sessão |
| POST | `/session/tenant` | autenticado | `{tenantId}` → troca o estabelecimento ativo (valida vínculo) |

Formato da sessão:

```json
{
  "user": { "id": "…", "name": "Ana", "email": "ana@padaria.com" },
  "activeTenant": { "id": "…", "name": "Padaria Central", "timezone": "America/Sao_Paulo", "currency": "BRL",
                    "role": "OWNER", "roleLabel": "Proprietário", "permissions": ["catalog.read", "…"] },
  "tenants": [{ "id": "…", "name": "Padaria Central", "role": "OWNER" }],
  "fiscal": { "available": false, "notice": "Emissão fiscal não disponível nesta versão. …" }
}
```

## Equipe

| Método | Caminho | Permissão | Corpo |
|--------|---------|-----------|-------|
| GET | `/members` | members.read | — |
| POST | `/members` | members.manage | `{name, email, role, password}` — senha ignorada se o e-mail já tiver conta |
| PUT | `/members/{id}` | members.manage | `{role, status: ACTIVE\|DISABLED}` — não altera a si mesmo nem remove o último OWNER ativo |

Papéis: `OWNER`, `MANAGER`, `CASHIER`, `PRODUCTION`, `STOCK`. Só OWNER concede OWNER.

## Catálogo

Leitura: `catalog.read`. Escrita: `catalog.write`. Atualizações usam `PUT` com o objeto completo.

### Categorias

| Método | Caminho | Corpo / filtros |
|--------|---------|-----------------|
| GET | `/categories` | `?kind=SELLABLE\|INGREDIENT\|BOTH&active=true\|false` |
| GET | `/categories/{id}` | |
| POST | `/categories` | `{name, kind, sortOrder?}` |
| PUT | `/categories/{id}` | `{name, kind, sortOrder, active}` — tipo não muda se já houver itens |

### Unidades

| Método | Caminho | Corpo / filtros |
|--------|---------|-----------------|
| GET | `/units` | |
| POST | `/units` | `{code, name, dimension: MASS\|VOLUME\|COUNT\|LENGTH, baseFactor}` |
| PUT | `/units/{id}` | idem; em uso → só `name` muda |
| DELETE | `/units/{id}` | só se não for base nem estiver em uso |
| GET | `/units/convert` | `?quantity=1.5&from={id}&to={id}` → `{quantity, from, to, result}`; 422 `INCOMPATIBLE_UNITS` |

### Produtos

| Método | Caminho | Corpo / filtros |
|--------|---------|-----------------|
| GET | `/products` | `?q=&categoryId=&active=&productionType=&page=&limit=` (limit ≤ 100); `q` busca nome (contém) ou SKU/código de barras (exato) |
| GET | `/products/{id}` | |
| POST | `/products` | `{name, saleUnitId, productionType: MANUFACTURED\|RESALE\|SERVICE, salePrice, categoryId?, sku?, barcode?, description?, stockTracked?, active?}` |
| PUT | `/products/{id}` | idem |

`SERVICE` sempre tem `stockTracked=false`. Categoria precisa ser `SELLABLE` ou `BOTH`.

### Ingredientes

| Método | Caminho | Corpo / filtros |
|--------|---------|-----------------|
| GET | `/ingredients` | `?q=&categoryId=&active=&page=&limit=` |
| GET | `/ingredients/{id}` | |
| POST | `/ingredients` | `{name, stockUnitId, averageUnitCost, minimumStock, categoryId?, sku?, purchaseUnitId?, purchaseToStockFactor?, active?}` |
| PUT | `/ingredients/{id}` | idem |

`purchaseToStockFactor` é calculado se a unidade de compra tiver a mesma dimensão da de estoque;
caso contrário é obrigatório. Categoria precisa ser `INGREDIENT` ou `BOTH`.

Com movimentações de estoque lançadas, `stockUnitId` (ingrediente) e `saleUnitId` (produto) não
podem mudar (422 no campo), e um produto só desliga `stockTracked` com saldo zero. Com ficha
técnica aberta (rascunho ou ativa), o produto não deixa de ser `MANUFACTURED` nem a unidade de
venda muda de dimensão, e um ingrediente usado não muda a unidade de estoque de dimensão.

`averageUnitCost` do ingrediente é o custo médio da política WAC-1 (DECISIONS D-17). Alterá-lo
no `PUT` substitui a média e entra no histórico (`MANUAL`).

| Método | Caminho | Descrição |
|--------|---------|-----------|
| GET | `/ingredients/{id}/cost-history` | `catalog.read`. Histórico de custo, mais recente primeiro (`?page=&limit=`) |

```json
{ "id": "…", "previousCost": "0.004", "newCost": "0.0045", "source": "STOCK_MOVEMENT",
  "sourceLabel": "Média ponderada após entrada de estoque", "stockMovementId": "…",
  "costPolicy": "WAC-1", "changedAt": "2026-11-25T12:00:00+00:00", "changedBy": { "id": "…", "name": "Ana" } }
```

`source`: `INITIAL` (custo existente quando o histórico foi criado), `MANUAL` (cadastro),
`STOCK_MOVEMENT` (entrada com custo ou estorno dela).

## Estoque

Permissões: leitura `inventory.read`; lançamentos `inventory.write`; estorno `inventory.reverse`.
Quantidades são strings decimais (até 6 casas) **na unidade de estoque do item** (a de estoque
do ingrediente ou a de venda do produto). `itemType`: `INGREDIENT` ou `PRODUCT` (produtos com
`stockTracked=false` não têm estoque). Sem `locationId`, usa o local padrão do tenant.

| Método | Caminho | Corpo / filtros |
|--------|---------|-----------------|
| GET | `/stock-locations` | locais do tenant (no MVP, só "Estoque geral") |
| GET | `/stock/balances` | `?itemType=INGREDIENT\|PRODUCT&q=&locationId=&includeInactive=1&page=&limit=` — todos os itens estocáveis com saldo (0 se nunca movimentado) |
| GET | `/stock/items/{itemType}/{itemId}` | item, `balances` por local, `totalQuantity`, `hasMovements` |
| GET | `/stock/items/{itemType}/{itemId}/movements` | histórico, mais recente primeiro (`?locationId=&page=&limit=`) |
| POST | `/stock/opening-balances` | `{itemType, itemId, quantity, unitId?, unitCost?, reason?, occurredAt?, locationId?}` |
| POST | `/stock/adjustments` | `{itemType, itemId, type: WASTE\|ADJUSTMENT_IN\|ADJUSTMENT_OUT\|RETURN_IN, quantity, reason, unitId?, unitCost?, occurredAt?, locationId?}` |
| POST | `/stock/movements/{id}/reversal` | `{reason}` — estorna um lançamento manual |

- `quantity` é sempre positiva; o sinal vem do tipo. `unitId` pode ser outra unidade da mesma
  dimensão: o valor é convertido para a unidade de estoque sem arredondar.
- `unitCost` (por unidade de estoque) só em entradas (`OPENING_BALANCE`, `ADJUSTMENT_IN`,
  `RETURN_IN`); em **ingredientes** recalcula o custo médio (WAC-1, D-17) e gera uma linha no
  histórico de custo; em produtos fica só registrado. Estornar uma entrada com custo também
  recalcula o custo (ver D-17).
- `occurredAt`: ISO 8601 com fuso (`2026-11-25T08:00:00-03:00`); pode ser passado, não futuro.

Movimento:

```json
{
  "id": "…", "itemType": "INGREDIENT", "itemId": "…", "locationId": "…",
  "type": "WASTE", "typeLabel": "Perda / descarte", "quantityDelta": "-250.5", "unitId": "…",
  "unitCost": null, "reason": "Saco rasgado", "sourceType": "MANUAL", "sourceId": null,
  "reversalOfId": null, "reversed": false, "reversible": true,
  "occurredAt": "2026-11-25T11:00:00+00:00", "createdAt": "…", "createdBy": { "id": "…", "name": "Ana" }
}
```

| Erro | Quando |
|------|--------|
| 422 `INSUFFICIENT_STOCK` | saída ou estorno deixaria o saldo negativo (`details.available`, `details.unit`) |
| 422 `INCOMPATIBLE_UNITS` | unidade informada de outra dimensão |
| 422 `INEXACT_CONVERSION` | a conversão para a unidade de estoque exigiria arredondamento |
| 409 `OPENING_BALANCE_NOT_ALLOWED` | o item já tem movimentação no local |
| 409 `ALREADY_REVERSED` | o lançamento já foi estornado |
| 422 `NOT_REVERSIBLE` | estorno de estorno, ou movimento de outro módulo |
| 409 `STOCK_CONFLICT` | conflito detectado pelo banco (recarregue e tente de novo) |
| 422 `NOT_STOCK_TRACKED` | estorno de lançamento de produto que deixou de controlar estoque |
| 422 `occurredAt` | data inexistente (ex.: 31/02, 25:00) ou no futuro |

## Fichas técnicas

Permissões: leitura `recipe.read`; criação, edição, versões, ativação e registro de custo
`recipe.write`. Fluxo de versões: `DRAFT` (editável) → `ACTIVE` (ativar arquiva a ativa
anterior) → `ARCHIVED`. Versões publicadas não mudam (409 `RECIPE_NOT_DRAFT`).

| Método | Caminho | Corpo / descrição |
|--------|---------|-------------------|
| GET | `/recipes` | `?productId=&status=DRAFT\|ACTIVE\|ARCHIVED&page=&limit=` (limit padrão 100) |
| GET | `/recipes/{id}` | detalhe com itens, custos adicionais, `cost` (estimativa ao vivo) e `versions` |
| POST | `/recipes` | cria rascunho (versão n+1): `{productId, name, expectedYieldQty, yieldUnitId, instructions?, items: [{ingredientId, quantity, unitId, notes?}], additionalCosts: [{description, amount}]}` |
| PUT | `/recipes/{id}` | substitui o conteúdo do rascunho (mesmo corpo, sem `productId`) |
| DELETE | `/recipes/{id}` | descarta rascunho (204) |
| POST | `/recipes/{id}/new-version` | copia uma versão publicada para um novo rascunho (201) |
| POST | `/recipes/{id}/activate` | publica o rascunho, arquiva a anterior e grava snapshot `ACTIVATION` |
| GET | `/recipes/{id}/cost-snapshots` | histórico de custo da versão, mais recente primeiro |
| POST | `/recipes/{id}/cost-snapshots` | registra o custo atual da versão ativa (`MANUAL`, 201) |

Regras: produto `MANUFACTURED`; unidade de cada item na dimensão da unidade de estoque do
ingrediente; rendimento na dimensão da unidade de venda; ingrediente não se repete; no máximo
um rascunho e uma ativa por produto. Rascunho pode ser salvo sem itens, mas não ativado.

`cost` (calculado com o custo vigente; dinheiro com 2 casas, unitários com 6):

```json
{
  "lines": [{ "ingredientId": "…", "ingredientName": "Farinha de trigo", "quantity": "10", "unitCode": "KG",
              "stockQuantity": "10000", "stockUnitCode": "G", "unitCost": "0.0045", "lineCost": "45.000000",
              "costChangedAt": "2026-11-25T12:00:00+00:00", "costSource": "STOCK_MOVEMENT" }],
  "ingredientsCost": "53.43", "additionalCost": "7.50", "totalCost": "60.93",
  "costPerYieldUnit": "3.584118", "costPerSaleUnit": "3.584118",
  "complete": true, "warnings": []
}
```

Avisos (`warnings[].code`): `NO_ITEMS`, `INGREDIENT_MISSING`, `UNIT_INCOMPATIBLE`,
`YIELD_UNIT_INCOMPATIBLE` (impedem ativar: 422 `RECIPE_INCOMPLETE`), `ZERO_COST`,
`INACTIVE_INGREDIENT` (só alertam). Outros erros: 409 `DRAFT_EXISTS`, 409 `RECIPE_NOT_DRAFT`,
409 `RECIPE_IS_DRAFT` (nova versão a partir de rascunho), 422 `RECIPE_NOT_ACTIVE` (snapshot fora
da versão ativa); campos de linha usam caminhos como `items[0][unitId]`.
