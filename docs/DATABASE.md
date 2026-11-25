# DATABASE.md --- Modelo de dados detalhado

## 1. Convenções

-   PostgreSQL.
-   UUID como chave primária.
-   Todas as tabelas de negócio pertencem a um tenant, exceto tabelas
    globais de referência como `unit_definitions`.
-   Tabelas tenant-scoped incluem `tenant_id NOT NULL`.
-   Datas `created_at`, `updated_at` em UTC; tabelas de ledger também
    têm `occurred_at`.
-   Dinheiro em `NUMERIC`, quantidade em `NUMERIC`; nunca
    `FLOAT`/`REAL`.
-   FKs e índices devem ser criados via migrations.
-   Tabelas de movimentos, pagamentos e documentos finalizados não devem
    ser apagadas fisicamente.
-   Nomes físicos sugeridos em `snake_case`, entidades Doctrine em
    PascalCase.

## 2. Diagrama lógico simplificado

``` mermaid
erDiagram
  TENANT ||--o{ TENANT_USER : has
  USER ||--o{ TENANT_USER : belongs
  TENANT ||--o{ PRODUCT : owns
  TENANT ||--o{ INGREDIENT : owns
  TENANT ||--o{ SUPPLIER : owns
  TENANT ||--o{ CUSTOMER : owns
  PRODUCT ||--o{ RECIPE : has
  RECIPE ||--o{ RECIPE_ITEM : contains
  INGREDIENT ||--o{ RECIPE_ITEM : used_in
  RECIPE ||--o{ PRODUCTION_ORDER : planned_as
  PRODUCTION_ORDER ||--o{ STOCK_MOVEMENT : generates
  PRODUCT ||--o{ STOCK_MOVEMENT : moved
  INGREDIENT ||--o{ STOCK_MOVEMENT : moved
  CASH_SESSION ||--o{ SALE : contains
  SALE ||--o{ SALE_ITEM : contains
  SALE ||--o{ PAYMENT : paid_by
  CUSTOMER ||--o{ CUSTOMER_ORDER : places
  CUSTOMER_ORDER ||--o{ CUSTOMER_ORDER_ITEM : contains
```

## 3. Tabelas

### 3.1 `tenants`

Representa o estabelecimento/empresa que assina o Forno.

  -----------------------------------------------------------------------
  Campo                   Tipo                    Regras
  ----------------------- ----------------------- -----------------------
  id                      UUID                    PK

  name                    VARCHAR(160)            obrigatório

  legal_name              VARCHAR(180)            opcional

  tax_id                  VARCHAR(14)             opcional no cadastro
                                                  inicial; validar
                                                  formato quando
                                                  informado

  email                   VARCHAR(180)            opcional

  phone                   VARCHAR(30)             opcional

  timezone                VARCHAR(64)             default
                                                  `America/Sao_Paulo`

  currency                CHAR(3)                 default `BRL`

  status                  VARCHAR(20)             `ACTIVE`, `SUSPENDED`,
                                                  `CLOSED`

  created_at / updated_at TIMESTAMPTZ             obrigatório
  -----------------------------------------------------------------------

Índices: `tax_id` único quando não nulo, respeitando política de
cadastro.

### 3.2 `users`

  Campo                     Tipo           Regras
  ------------------------- -------------- --------------------------------------
  id                        UUID           PK
  name                      VARCHAR(160)   obrigatório
  email                     VARCHAR(180)   obrigatório, unique case-insensitive
  password_hash             VARCHAR(255)   obrigatório
  status                    VARCHAR(20)    `ACTIVE`, `INVITED`, `DISABLED`
  email_verified_at         TIMESTAMPTZ    nullable
  created_at / updated_at   TIMESTAMPTZ    obrigatório

### 3.3 `tenant_users`

Associação de usuário a estabelecimento.

  -----------------------------------------------------------------------
  Campo                   Tipo                    Regras
  ----------------------- ----------------------- -----------------------
  id                      UUID                    PK

  tenant_id               UUID                    FK tenants

  user_id                 UUID                    FK users

  role                    VARCHAR(30)             `OWNER`, `MANAGER`,
                                                  `CASHIER`,
                                                  `PRODUCTION`, `STOCK`

  permissions             JSONB                   opcional para exceções;
                                                  validar no backend

  status                  VARCHAR(20)             `ACTIVE`, `INVITED`,
                                                  `DISABLED`

  created_at              TIMESTAMPTZ             obrigatório
  -----------------------------------------------------------------------

Unique: `(tenant_id, user_id)`. Índice `(user_id, status)`.

### 3.4 `categories`

  Campo                     Tipo           Regras
  ------------------------- -------------- ----------------------------------
  id                        UUID           PK
  tenant_id                 UUID           FK
  name                      VARCHAR(100)   obrigatório
  kind                      VARCHAR(20)    `SELLABLE`, `INGREDIENT`, `BOTH`
  sort_order                INTEGER        default 0
  active                    BOOLEAN        default true
  created_at / updated_at   TIMESTAMPTZ    obrigatório

Unique `(tenant_id, kind, name)`.

### 3.5 `units_of_measure`

Unidades configuradas/permitidas para cada tenant.

  -----------------------------------------------------------------------
  Campo                   Tipo                    Regras
  ----------------------- ----------------------- -----------------------
  id                      UUID                    PK

  tenant_id               UUID                    FK

  code                    VARCHAR(12)             `KG`, `G`, `L`, `ML`,
                                                  `UN`, etc.

  name                    VARCHAR(40)             obrigatório

  dimension               VARCHAR(20)             `MASS`, `VOLUME`,
                                                  `COUNT`, `LENGTH`

  base_factor             NUMERIC(18,9)           conversão para
                                                  unidade-base da
                                                  dimensão

  is_base                 BOOLEAN                 default false
  -----------------------------------------------------------------------

Unique `(tenant_id, code)`. Não permitir conversão automática entre
dimensões diferentes.

### 3.6 `products`

Itens comercializados. Um produto pode ser fabricado ou comprado pronto.

  Campo                     Tipo            Regras
  ------------------------- --------------- -------------------------------------
  id                        UUID            PK
  tenant_id                 UUID            FK
  category_id               UUID            FK nullable
  name                      VARCHAR(160)    obrigatório
  sku                       VARCHAR(64)     nullable
  barcode                   VARCHAR(64)     nullable
  description               TEXT            nullable
  sale_unit_id              UUID            FK units
  stock_tracked             BOOLEAN         default true
  production_type           VARCHAR(20)     `MANUFACTURED`, `RESALE`, `SERVICE`
  sale_price                NUMERIC(14,2)   \>= 0
  active                    BOOLEAN         default true
  created_at / updated_at   TIMESTAMPTZ     obrigatório

Índices: `(tenant_id, active, name)`, unique parcial `(tenant_id, sku)`
quando preenchido; barcode também unique parcial por tenant.

### 3.7 `ingredients`

Insumos usados em receitas.

  Campo                      Tipo            Regras
  -------------------------- --------------- -------------------
  id                         UUID            PK
  tenant_id                  UUID            FK
  category_id                UUID            FK nullable
  name                       VARCHAR(160)    obrigatório
  sku                        VARCHAR(64)     nullable
  stock_unit_id              UUID            FK units
  purchase_unit_id           UUID            FK units nullable
  purchase_to_stock_factor   NUMERIC(18,6)   \> 0; opcional
  average_unit_cost          NUMERIC(18,6)   \>= 0
  minimum_stock              NUMERIC(18,6)   \>= 0
  active                     BOOLEAN         default true
  created_at / updated_at    TIMESTAMPTZ     obrigatório

Unique parcial `(tenant_id, sku)`. O custo médio é um valor de apoio;
movimentos e recebimentos devem permitir reconstrução.

### 3.8 `suppliers`

  Campo                     Tipo           Regras
  ------------------------- -------------- --------------
  id                        UUID           PK
  tenant_id                 UUID           FK
  name                      VARCHAR(160)   obrigatório
  tax_id                    VARCHAR(14)    nullable
  contact_name              VARCHAR(120)   nullable
  email                     VARCHAR(180)   nullable
  phone                     VARCHAR(30)    nullable
  notes                     TEXT           nullable
  active                    BOOLEAN        default true
  created_at / updated_at   TIMESTAMPTZ    obrigatório

### 3.9 `customers`

  Campo                     Tipo           Regras
  ------------------------- -------------- -------------
  id                        UUID           PK
  tenant_id                 UUID           FK
  name                      VARCHAR(160)   obrigatório
  phone                     VARCHAR(30)    nullable
  email                     VARCHAR(180)   nullable
  tax_id                    VARCHAR(14)    nullable
  notes                     TEXT           nullable
  created_at / updated_at   TIMESTAMPTZ    obrigatório

Índices `(tenant_id, name)` e `(tenant_id, phone)`.

### 3.10 `recipes`

Ficha técnica versionada.

  Campo                Tipo            Regras
  -------------------- --------------- -------------------------------
  id                   UUID            PK
  tenant_id            UUID            FK
  product_id           UUID            FK products
  version              INTEGER         \>= 1
  name                 VARCHAR(160)    obrigatório
  expected_yield_qty   NUMERIC(18,6)   \> 0
  yield_unit_id        UUID            FK units
  instructions         TEXT            nullable
  status               VARCHAR(20)     `DRAFT`, `ACTIVE`, `ARCHIVED`
  effective_from       DATE            nullable
  created_by           UUID            FK users
  created_at           TIMESTAMPTZ     obrigatório

Unique `(tenant_id, product_id, version)`. Uma receita ativa por produto
(índice parcial).

### 3.11 `recipe_items`

  Campo           Tipo            Regras
  --------------- --------------- ----------------
  id              UUID            PK
  tenant_id       UUID            FK
  recipe_id       UUID            FK recipes
  ingredient_id   UUID            FK ingredients
  quantity        NUMERIC(18,6)   \> 0
  unit_id         UUID            FK units
  notes           VARCHAR(255)    nullable

Validar compatibilidade de dimensão e conversão no domínio. Não somar
massa com volume sem densidade explícita.

### 3.12 `stock_locations`

No MVP, criar uma localização padrão por tenant (ex.: estoque geral),
mas modelar localização para evolução.

  Campo        Tipo           Regras
  ------------ -------------- ---------------
  id           UUID           PK
  tenant_id    UUID           FK
  name         VARCHAR(100)   obrigatório
  is_default   BOOLEAN        default false

Unique `(tenant_id, name)`.

### 3.13 `inventory_lots`

Lote físico de um ingrediente ou produto.

  Campo               Tipo            Regras
  ------------------- --------------- -------------
  id                  UUID            PK
  tenant_id           UUID            FK
  ingredient_id       UUID            FK nullable
  product_id          UUID            FK nullable
  stock_location_id   UUID            FK
  lot_code            VARCHAR(80)     nullable
  received_at         TIMESTAMPTZ     nullable
  expires_at          DATE            nullable
  initial_qty         NUMERIC(18,6)   \>= 0
  unit_cost           NUMERIC(18,6)   \>= 0
  created_at          TIMESTAMPTZ     obrigatório

Exatamente um entre `ingredient_id` e `product_id` deve estar
preenchido. O saldo corrente deriva dos movimentos do lote.

### 3.14 `stock_movements`

Ledger imutável de estoque.

  -----------------------------------------------------------------------
  Campo                   Tipo                    Regras
  ----------------------- ----------------------- -----------------------
  id                      UUID                    PK

  tenant_id               UUID                    FK

  ingredient_id           UUID                    FK nullable

  product_id              UUID                    FK nullable

  inventory_lot_id        UUID                    FK nullable

  stock_location_id       UUID                    FK

  movement_type           VARCHAR(32)             enum documentado

  quantity_delta          NUMERIC(18,6)           diferente de zero;
                                                  positivo entra,
                                                  negativo sai

  unit_id                 UUID                    FK

  unit_cost               NUMERIC(18,6)           nullable

  source_type             VARCHAR(40)             origem: `SALE`,
                                                  `PRODUCTION`,
                                                  `PURCHASE`, etc.

  source_id               UUID                    nullable

  reversal_of_id          UUID                    FK stock_movements
                                                  nullable

  reason                  VARCHAR(255)            obrigatório para
                                                  ajuste/perda

  occurred_at             TIMESTAMPTZ             obrigatório

  created_by              UUID                    FK users

  created_at              TIMESTAMPTZ             obrigatório
  -----------------------------------------------------------------------

Exatamente um entre `ingredient_id` e `product_id`. Índices
`(tenant_id, ingredient_id, occurred_at)`,
`(tenant_id, product_id, occurred_at)`,
`(tenant_id, source_type, source_id)`. Não permitir UPDATE/DELETE pela
aplicação para movimentos postados.

### 3.15 `production_orders`

  -----------------------------------------------------------------------
  Campo                   Tipo                    Regras
  ----------------------- ----------------------- -----------------------
  id                      UUID                    PK

  tenant_id               UUID                    FK

  recipe_id               UUID                    FK

  recipe_version          INTEGER                 snapshot

  planned_lots            NUMERIC(18,6)           \> 0

  planned_output_qty      NUMERIC(18,6)           \> 0

  actual_output_qty       NUMERIC(18,6)           nullable

  status                  VARCHAR(20)             `DRAFT`, `PLANNED`,
                                                  `IN_PROGRESS`,
                                                  `COMPLETED`,
                                                  `CANCELLED`

  planned_for             DATE                    nullable

  started_at /            TIMESTAMPTZ             nullable
  completed_at                                    

  notes                   TEXT                    nullable

  created_by              UUID                    FK users

  created_at / updated_at TIMESTAMPTZ             obrigatório
  -----------------------------------------------------------------------

### 3.16 `production_order_items`

Snapshot dos insumos previstos/consumidos na ordem.

  Campo                 Tipo            Regras
  --------------------- --------------- ----------
  id                    UUID            PK
  tenant_id             UUID            FK
  production_order_id   UUID            FK
  ingredient_id         UUID            FK
  planned_qty           NUMERIC(18,6)   \> 0
  actual_qty            NUMERIC(18,6)   nullable
  unit_id               UUID            FK
  unit_cost_snapshot    NUMERIC(18,6)   \>= 0

### 3.17 `cash_sessions`

  Campo                   Tipo            Regras
  ----------------------- --------------- --------------------------
  id                      UUID            PK
  tenant_id               UUID            FK
  opened_by               UUID            FK users
  closed_by               UUID            FK users nullable
  status                  VARCHAR(16)     `OPEN`, `CLOSED`
  opening_amount          NUMERIC(14,2)   \>= 0
  expected_amount         NUMERIC(14,2)   nullable
  counted_amount          NUMERIC(14,2)   nullable
  difference_amount       NUMERIC(14,2)   nullable
  opened_at / closed_at   TIMESTAMPTZ     nullable conforme status
  notes                   TEXT            nullable

No MVP, limitar uma sessão aberta por tenant. Se futuramente houver
caixas simultâneos, introduzir `cash_registers`.

### 3.18 `sales`

  Campo                     Tipo            Regras
  ------------------------- --------------- -----------------------------------
  id                        UUID            PK
  tenant_id                 UUID            FK
  cash_session_id           UUID            FK
  customer_id               UUID            FK nullable
  sale_number               BIGINT          sequência por tenant
  status                    VARCHAR(20)     `DRAFT`, `COMPLETED`, `CANCELLED`
  channel                   VARCHAR(20)     `COUNTER`, `TABLE`, `ORDER`
  subtotal                  NUMERIC(14,2)   \>= 0
  discount_total            NUMERIC(14,2)   \>= 0
  total                     NUMERIC(14,2)   \>= 0
  notes                     TEXT            nullable
  completed_at              TIMESTAMPTZ     nullable
  cancelled_at              TIMESTAMPTZ     nullable
  cancelled_by              UUID            FK users nullable
  cancellation_reason       VARCHAR(255)    nullable
  created_by                UUID            FK users
  created_at / updated_at   TIMESTAMPTZ     obrigatório

Unique `(tenant_id, sale_number)`. A numeração deve ser gerada com
mecanismo seguro contra concorrência.

### 3.19 `sale_items`

  Campo                   Tipo            Regras
  ----------------------- --------------- -------------
  id                      UUID            PK
  tenant_id               UUID            FK
  sale_id                 UUID            FK
  product_id              UUID            FK
  product_name_snapshot   VARCHAR(160)    obrigatório
  quantity                NUMERIC(18,6)   \> 0
  unit_id                 UUID            FK
  unit_price              NUMERIC(14,4)   \>= 0
  discount_amount         NUMERIC(14,2)   \>= 0
  line_total              NUMERIC(14,2)   \>= 0

Snapshot impede que alteração posterior do cadastro reescreva histórico.

### 3.20 `payments`

  -----------------------------------------------------------------------
  Campo                   Tipo                    Regras
  ----------------------- ----------------------- -----------------------
  id                      UUID                    PK

  tenant_id               UUID                    FK

  sale_id                 UUID                    FK nullable

  customer_order_id       UUID                    FK nullable

  cash_session_id         UUID                    FK nullable

  method                  VARCHAR(24)             `CASH`, `PIX`, `DEBIT`,
                                                  `CREDIT`, `TRANSFER`,
                                                  `OTHER`

  amount                  NUMERIC(14,2)           \> 0

  status                  VARCHAR(16)             `PENDING`, `CONFIRMED`,
                                                  `REFUNDED`, `CANCELLED`

  external_reference      VARCHAR(120)            nullable

  paid_at                 TIMESTAMPTZ             nullable

  created_by              UUID                    FK users

  created_at              TIMESTAMPTZ             obrigatório
  -----------------------------------------------------------------------

Regra: pagamento deve pertencer à venda ou encomenda conforme fluxo; não
pode ser duplicado ao converter encomenda em venda. Taxas e liquidação
de cartão serão evolução.

### 3.21 `cash_movements`

  -----------------------------------------------------------------------
  Campo                   Tipo                    Regras
  ----------------------- ----------------------- -----------------------
  id                      UUID                    PK

  tenant_id               UUID                    FK

  cash_session_id         UUID                    FK

  payment_id              UUID                    FK nullable

  movement_type           VARCHAR(20)             `OPENING`, `SALE_IN`,
                                                  `EXPENSE_OUT`,
                                                  `MANUAL_IN`,
                                                  `MANUAL_OUT`,
                                                  `REVERSAL`

  amount                  NUMERIC(14,2)           positivo; tipo define
                                                  direção

  description             VARCHAR(255)            obrigatório

  source_type / source_id VARCHAR(40)/UUID        nullable

  occurred_at             TIMESTAMPTZ             obrigatório

  created_by              UUID                    FK users
  -----------------------------------------------------------------------

### 3.22 `expenses`

  Campo                     Tipo            Regras
  ------------------------- --------------- -----------------------------
  id                        UUID            PK
  tenant_id                 UUID            FK
  supplier_id               UUID            FK nullable
  description               VARCHAR(180)    obrigatório
  category                  VARCHAR(80)     obrigatório
  amount                    NUMERIC(14,2)   \> 0
  due_date                  DATE            nullable
  status                    VARCHAR(20)     `OPEN`, `PAID`, `CANCELLED`
  paid_at                   TIMESTAMPTZ     nullable
  notes                     TEXT            nullable
  created_by                UUID            FK users
  created_at / updated_at   TIMESTAMPTZ     obrigatório

### 3.23 `customer_orders`

  -----------------------------------------------------------------------
  Campo                   Tipo                    Regras
  ----------------------- ----------------------- -----------------------
  id                      UUID                    PK

  tenant_id               UUID                    FK

  customer_id             UUID                    FK

  order_number            BIGINT                  sequência por tenant

  status                  VARCHAR(20)             `QUOTE`, `CONFIRMED`,
                                                  `IN_PRODUCTION`,
                                                  `READY`, `PICKED_UP`,
                                                  `CANCELLED`

  pickup_at               TIMESTAMPTZ             obrigatório

  subtotal                NUMERIC(14,2)           \>= 0

  discount_total          NUMERIC(14,2)           \>= 0

  total                   NUMERIC(14,2)           \>= 0

  notes                   TEXT                    nullable

  created_by              UUID                    FK users

  created_at / updated_at TIMESTAMPTZ             obrigatório
  -----------------------------------------------------------------------

### 3.24 `customer_order_items`

  Campo                   Tipo            Regras
  ----------------------- --------------- -------------
  id                      UUID            PK
  tenant_id               UUID            FK
  customer_order_id       UUID            FK
  product_id              UUID            FK
  product_name_snapshot   VARCHAR(160)    obrigatório
  quantity                NUMERIC(18,6)   \> 0
  unit_id                 UUID            FK
  unit_price              NUMERIC(14,4)   \>= 0
  line_total              NUMERIC(14,2)   \>= 0
  notes                   VARCHAR(255)    nullable

### 3.25 `audit_logs`

  Campo         Tipo           Regras
  ------------- -------------- -----------------------------------
  id            UUID           PK
  tenant_id     UUID           FK
  user_id       UUID           FK nullable
  action        VARCHAR(80)    obrigatório
  entity_type   VARCHAR(80)    obrigatório
  entity_id     UUID           obrigatório
  before_data   JSONB          nullable, filtrar dados sensíveis
  after_data    JSONB          nullable, filtrar dados sensíveis
  reason        VARCHAR(255)   nullable
  ip_address    INET           nullable
  created_at    TIMESTAMPTZ    obrigatório

Não registrar senhas, tokens, dados completos de cartão ou segredos.

### 3.26 `subscriptions`

  Campo                                       Tipo           Regras
  ------------------------------------------- -------------- --------------------------------------------
  id                                          UUID           PK
  tenant_id                                   UUID           FK
  plan_code                                   VARCHAR(40)    obrigatório
  status                                      VARCHAR(20)    `TRIAL`, `ACTIVE`, `PAST_DUE`, `CANCELLED`
  started_at                                  TIMESTAMPTZ    obrigatório
  current_period_start / current_period_end   TIMESTAMPTZ    nullable
  provider                                    VARCHAR(40)    nullable
  external_customer_id                        VARCHAR(120)   nullable
  created_at / updated_at                     TIMESTAMPTZ    obrigatório

## 4. Integridade referencial multi-tenant

Para tabelas tenant-scoped, preferir chaves únicas compostas
`(tenant_id, id)` e FKs compostas `(tenant_id, related_id)`. Isso
impede, no próprio banco, que uma venda do tenant A referencie um
produto do tenant B.

Exemplo conceitual: - `products UNIQUE (tenant_id, id)` -
`sales (tenant_id, id)` com unique; -
`sale_items FOREIGN KEY (tenant_id, sale_id) REFERENCES sales(tenant_id, id)`; -
`sale_items FOREIGN KEY (tenant_id, product_id) REFERENCES products(tenant_id, id)`.

O Doctrine pode exigir mapeamento explícito/ajuste para FKs compostas.
Se isso complicar a primeira migration, não remover a validação de
tenant da aplicação; registrar a decisão e adicionar constraints em
migration dedicada.

## 5. Índices recomendados

-   Índices de leitura por tenant e data: vendas, movimentos de estoque,
    ordens de produção, despesas.
-   `(tenant_id, status)` para filas operacionais.
-   `(tenant_id, name)` para buscas de cadastros.
-   `(tenant_id, expires_at)` para alertas de validade.
-   `(tenant_id, occurred_at)` para ledger.
-   Unique parcial para SKU/barcode não nulos.
-   Índices em FKs; PostgreSQL não cria automaticamente índice no lado
    referenciador.

Evitar indexar todos os campos sem medir consultas.

## 6. Decisões de implementação

1.  Implementar primeiro tenants, users, tenant_users, categories,
    units, products e ingredients.
2.  Criar ledger de estoque antes de construir produção e venda.
3.  Criar snapshots de nome, preço e custo nos registros históricos.
4.  Usar transações explícitas nos casos de uso críticos.
5.  Usar locks (`SELECT ... FOR UPDATE`) ou estratégia equivalente para
    prevenir overselling/estoque negativo sob concorrência.
6.  Definir política de custo antes de exibir "margem" ao usuário.
7.  Criar migrations pequenas e reversíveis sempre que possível.
8.  O SQL de referência não substitui migrations Doctrine revisadas.
