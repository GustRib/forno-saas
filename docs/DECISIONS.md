# DECISIONS.md — Decisões de implementação e pendências

Registro das decisões tomadas na implementação. Complementa `ARCHITECTURE.md` e
`DATABASE.md`, que não foram alterados; quando houver conflito, a especificação original
prevalece e esta página deve ser corrigida. A situação das histórias do backlog também é
acompanhada aqui.

## 1. Escopo entregue

### 1.1 Primeiro incremento (épicos 0, 1 e 2)

| História | Situação | Observações |
|----------|----------|-------------|
| FNO-001 Repositório, Docker Compose | Feito | Postgres 17, Redis 7, PHP-FPM 8.4, Nginx, Vite |
| FNO-002 CI | Feito | `.github/workflows/ci.yml` — ainda não executado no GitHub |
| FNO-003 Padrão de código | Feito | PHP CS Fixer (@Symfony), ESLint, `.editorconfig` |
| FNO-004 Health, logs, erros | Feito | `/api/v1/health`, logs JSON em prod com `request_id`/`tenant_id`, envelope de erro |
| FNO-010 Usuário e login | Feito | Hash `auto`, erro genérico, 5 tentativas/min |
| FNO-011 Estabelecimento + OWNER | Feito | Cadastro público, desligável por `FORNO_REGISTRATION_ENABLED` |
| FNO-012 Tenant ativo e isolamento | Feito | Testes de leitura, alteração e referência cruzada |
| FNO-013 Papéis e autorização por ação | Parcial | Permissões de catálogo, equipe e estoque; novos módulos acrescentam as suas |
| FNO-020 Categorias e unidades | Feito | Unidades padrão criadas com o tenant |
| FNO-021 Produtos | Feito | |
| FNO-022 Ingredientes | Feito | Fator de compra calculado quando a dimensão é a mesma |
| FNO-024 Conversão de unidades | Feito | Domínio + endpoint `/units/convert` |
| FR-005 Adicionar/desativar usuários | Parcial | Senha inicial definida pelo proprietário; convite por e-mail é FNO-014 (P1) |
| FR-100 Aviso fiscal | Feito | Faixa fixa no layout e campo `fiscal` na sessão |

### 1.2 Épico 3 — Estoque e ledger (P0)

| História | Situação | Observações |
|----------|----------|-------------|
| FNO-030 Movimentações imutáveis | Feito | Ledger `stock_movements` append-only no banco; tipo, quantidade, origem, usuário e data (FR-031) |
| FNO-031 Saldo inicial | Feito | Uma vez por item/local, só antes de qualquer outro movimento (FR-030) |
| FNO-032 Saldo por item e local | Feito | `stock_balances` mantida por trigger = soma do ledger; um local padrão por tenant |
| FNO-033 Perda e ajuste com motivo | Feito | Perda/descarte, devolução, ajuste de entrada/saída, estorno — motivo obrigatório (FR-035) |
| FR-036 Bloquear estoque negativo | Parcial | Bloqueado sempre (aplicação + `CHECK`); a exceção configurável e auditada não existe (P-09) |
| Aceite: saldo = soma dos movimentos | Verificado | Testes funcionais + comando `forno:stock:verify` |
| Aceite: concorrência sem saldo negativo | Verificado | `StockConcurrencyTest` com duas conexões reais |
| FNO-034 Lotes e validade (P1) | Não iniciado | `inventory_lots` e `stock_movements.inventory_lot_id` ficam para essa história |
| FNO-035 Alertas de mínimo/vencimento (P1) | Não iniciado | `minimum_stock` já existe no cadastro |

**Correções da auditoria do épico 3** (feitas junto com o épico 4, cada uma com teste que
falhava antes da correção):
1. `stock_balances` aceitava escrita direta (`UPDATE … SET quantity = 999`), o que quebrava
   "saldo = soma do ledger". Agora um trigger só deixa o ledger mudar o saldo (D-13).
2. `occurredAt` aceitava datas inexistentes ("2026-02-31", "25:00"), que o PHP "rolava" para
   outro dia. Agora são recusadas (422).
3. Estorno podia devolver estoque a um produto que deixou de controlar estoque. Agora 422
   `NOT_STOCK_TRACKED`.

### 1.3 Épico 4 — Receitas e custos

| História | Situação | Evidência |
|----------|----------|-----------|
| FNO-040 Receita vinculada a produto (FR-020) | Feito | Só produto `MANUFACTURED`; rascunho por produto; `RecipeTest`, `RecipeSecurityAndIntegrityTest` |
| FNO-041 Ingredientes, quantidades e unidades (FR-021) | Feito | Unidade do item na dimensão da unidade de estoque do ingrediente; sem repetição; modo de preparo opcional |
| FNO-042 Custo de lote e unidade (FR-022) | Feito | `RecipeCostCalculator`; custo por unidade de rendimento e por unidade de venda; **aceite do BACKLOG verificado** (unitário e API) |
| FNO-043 Versionar receita (FR-023) | Feito | Rascunho → ativa → arquivada; versões publicadas imutáveis no banco (triggers) |
| FNO-044 Custo adicional e histórico de custo (FR-024, FR-025, P1) | Feito | Custos adicionais por lote; histórico de custo do ingrediente (data e origem); snapshots de custo da receita |
| Política de custo explícita e versionada (regra §5.9) | Feito | WAC-1, D-17 |

Ainda não implementado de propósito: lotes/validade, alertas, inventário por contagem em lote,
produção, compras, caixa, vendas, comandas, encomendas, despesas, relatórios, margem, assinatura
e fiscal. Os tipos de movimento dessas áreas (`PURCHASE_RECEIPT`, `PRODUCTION_*`, `SALE`) já
são aceitos pelo banco, mas nenhuma API os lança.

## 2. Decisões técnicas

### D-01 Symfony 7.4 LTS + PHP 8.4
Versão LTS da série 7, compatível com PHP 8.4 (verificado no container: PHP 8.4.28).

### D-02 Identificadores UUID v7 gerados no backend
UUID v7 (ordenável no tempo, bom para índices). Gerados na entidade; o banco não tem default.

### D-03 Referências entre entidades como UUID, sem associações Doctrine
Entidades guardam `categoryId`, `saleUnitId` etc. como UUID. Motivo: as FKs compostas
`(tenant_id, x_id)` de `DATABASE.md §4` não são representáveis no ORM. Validação do tenant
das referências é feita no caso de uso **e** pelo banco.
**Consequência:** as migrations são escritas à mão e são a fonte da verdade.
`doctrine:schema:update` e `doctrine:migrations:diff` mostram diferenças esperadas (FKs
compostas, índices parciais e funcionais, defaults) — **não aplicar** sem revisão.
O CI roda `doctrine:schema:validate --skip-sync` (só o mapeamento).

### D-04 Tenant ativo na sessão do servidor
O `TenantContext` resolve o tenant a partir do usuário autenticado + vínculo ativo
(`tenant_users.status = ACTIVE` e `tenants.status = ACTIVE`). O ID escolhido fica na sessão;
o cliente troca com `POST /api/v1/session/tenant`, que valida o vínculo. Sem seleção válida,
usa o vínculo mais antigo. O cliente nunca envia `tenant_id` nos cadastros.

### D-05 Autenticação por cookie de sessão + defesa CSRF sem token
Cookie `FORNOSESSID` HttpOnly, `SameSite=Lax`, `Secure` automático sob HTTPS. Requisições que
alteram estado precisam de `Content-Type: application/json` ou `X-Requested-With`, e são
recusadas se `Origin`/`Sec-Fetch-Site` indicarem outro site (`CsrfGuardSubscriber`).
Formulários HTML de terceiros não conseguem cumprir isso sem preflight CORS, que não é liberado.
**Pendente:** sessões em arquivo (padrão PHP). Com mais de um container PHP em produção,
mover sessões para Redis ou Postgres.

### D-06 Permissões em código, exceções no banco
Cada papel tem permissões padrão (`Tenant/Domain/Role.php`). `tenant_users.permissions` aceita
`{"grant": [...], "revoke": [...]}`; o OWNER sempre tem todas. Não há tela para exceções ainda.

| Permissão | OWNER | MANAGER | CASHIER | PRODUCTION | STOCK |
|-----------|:-----:|:-------:|:-------:|:----------:|:-----:|
| tenant.manage | ✓ | | | | |
| members.read | ✓ | ✓ | | | |
| members.manage | ✓ | | | | |
| catalog.read | ✓ | ✓ | ✓ | ✓ | ✓ |
| catalog.write | ✓ | ✓ | | | |
| inventory.read | ✓ | ✓ | | ✓ | ✓ |
| inventory.write | ✓ | ✓ | | ✓ | ✓ |
| inventory.reverse | ✓ | ✓ | | | |
| recipe.read | ✓ | ✓ | | ✓ | ✓ |
| recipe.write | ✓ | ✓ | | | |

Estoque (épico 3): REQUIREMENTS §2 diz que Produção/Estoque "registram perdas, recebimentos e
inventário" e que o proprietário "autoriza cancelamentos e ajustes". Interpretação adotada:
produção e estoque lançam saldo inicial, perdas, devoluções e ajustes; **estornar** um lançamento
fica com proprietário e gerente. O caixa não acessa o estoque (só "consulta catálogo e comandas").
Validar com os pilotos — ver P-11.

### D-07 Decimais como string de ponta a ponta
API recebe e devolve decimais como string (`"18.90"`); números JSON em campos monetários são
recusados (422). PHP usa bcmath (`Shared/Domain/Decimal`) com arredondamento *half up*.
O frontend só converte a digitação pt-BR (`18,90` → `18.90`) e formata a exibição.

### D-08 Unidades
Unidade-base por dimensão: G (massa), ML (volume), UN (contagem). Padrões criados com o tenant
via evento `TenantCreated` na mesma transação: G, KG, ML, L, UN, DZ. Unidades-base não podem
ser redefinidas nem excluídas. Código, dimensão e fator de uma unidade **em uso** não mudam
(isso reinterpretaria quantidades); só o nome. Conversão massa↔volume não existe — exige
densidade cadastrada (futuro).

### D-09 Ingrediente: unidade de compra
Se a unidade de compra for da mesma dimensão da de estoque, o fator é calculado (KG→G = 1000).
Se não (ex.: dúzia → gramas), o fator é obrigatório. `average_unit_cost` é editável
manualmente até existir o recebimento de compras — ver pendência P-01.

### D-10 SKU e código de barras
SKU único por tenant sem diferenciar maiúsculas (índice em `LOWER(sku)`); código de barras
único por tenant. Mesmo SKU pode existir em tenants diferentes.

### D-11 Erros e logs
Envelope `{"error": {"code", "message", "details"}}` com mensagens em pt-BR. Exceções não
tratadas viram 500 genérico e são logadas com stack trace. Em produção, logs JSON em stderr
com `request_id` (vindo do Nginx ou gerado) e `tenant_id`; nunca senha ou dados pessoais.

### D-12 Testes
Cada teste funcional roda numa transação revertida (DAMA DoctrineTestBundle) no banco
`forno_test`. Testes rodam com `APP_DEBUG=0` para não revalidar arquivos a cada requisição
(lento em bind mounts); o bootstrap limpa o cache e aplica as migrations.
`StockConcurrencyTest` (grupo `concurrency`) é a exceção: usa duas conexões reais com dados
gravados de fato e limpa o que criou com `session_replication_role = replica` (exige
superusuário no banco de teste, como no Docker e no CI).

### D-13 Ledger de estoque com invariantes no banco (épico 3)
`stock_movements` é o ledger (DATABASE §3.14) e `stock_balances` é o saldo materializado que
ARCHITECTURE §9 permite ("pode ser materializado, mas reconstruível"). O PostgreSQL garante:

| Invariante | Mecanismo |
|------------|-----------|
| Movimento nunca é alterado nem apagado | trigger `trg_stock_movements_immutable` (SQLSTATE 23001) |
| Todo movimento atualiza o saldo na mesma transação | trigger `trg_stock_movements_apply` (AFTER INSERT) |
| Saldo só muda pelo ledger (auditoria do épico 3) | trigger `trg_stock_balances_guard`: fora do trigger do ledger só se cria linha com saldo 0 |
| Saldo nunca negativo (FR-036) | `CHECK (quantity >= 0)` em `stock_balances` |
| Todos os movimentos do item/local na mesma unidade | trigger compara com `stock_balances.unit_id` |
| Sinal coerente com o tipo | `ck_stock_movements_sign` |
| Motivo em perda, ajuste, devolução e estorno | `ck_stock_movements_reason` |
| Estorno espelha o original, no máximo um por movimento | trigger + índice único em `reversal_of_id` |
| Um saldo inicial por item/local | índices únicos parciais |
| Tenant das referências | FKs compostas `(tenant_id, x_id)` em tudo |

**Concorrência:** o caso de uso faz `SELECT … FOR UPDATE` na linha de saldo antes de validar;
lançamentos simultâneos no mesmo item/local esperam um pelo outro e o segundo vê o saldo já
atualizado. Mesmo sem esse bloqueio, o `UPDATE` do trigger trava a linha e o `CHECK` recusa o
negativo. O comando `forno:stock:verify` confere saldo × `SUM(quantity_delta)` para todos os
tenants (deve rodar em monitoramento periódico).

*Detalhe de implementação:* o trigger insere a linha de saldo com 0 (`ON CONFLICT DO NOTHING`) e
depois soma o delta — um UPSERT direto falharia, pois o PostgreSQL avalia o `CHECK` na linha
proposta do INSERT antes de resolver o conflito.

### D-14 Quantidades do ledger sempre na unidade de estoque
REQUIREMENTS §5.3: cada item tem uma unidade-base. O ledger grava `quantity_delta` na unidade
de estoque do item — `stock_unit_id` do ingrediente ou `sale_unit_id` do produto — e
`unit_id` é sempre essa unidade. O usuário pode informar outra unidade **da mesma dimensão**
(ex.: kg para um item em g); a conversão é exata ou é recusada (`INEXACT_CONVERSION`), nunca
arredondada (§5.2). A quantidade/unidade originalmente digitada não é guardada.
Consequência no Catalog: depois do primeiro movimento, a unidade de estoque/venda do item não
muda, e um produto só desliga "controlar estoque" com saldo zero. O módulo Catalog consulta isso
pela porta `StockUsageChecker`, implementada pelo Inventory (sem acessar as tabelas do outro módulo).

### D-15 Lançamentos manuais e estornos
- Saldo inicial (`OPENING_BALANCE`): só quando o item ainda não tem movimento no local. Depois,
  a correção é ajuste. Não existe "zerar e relançar o saldo inicial".
- Ajustes (`POST /stock/adjustments`): `WASTE` (perda e descarte — o motivo diferencia),
  `RETURN_IN` (devolução ao estoque), `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`.
- Estorno (`REVERSAL`): espelha um lançamento manual com sinal invertido, exige motivo e não
  pode deixar o saldo negativo. Movimentos de compra, produção e venda serão estornados pelos
  cancelamentos dos seus módulos. Um estorno não é estornado.
- `source_type = 'MANUAL'` para tudo que nasce na tela de estoque (FR-031 "origem").
- `occurred_at` pode ser retroativo (ex.: saldo inicial na data da contagem), nunca no futuro
  (tolerância de 5 min). A verificação de saldo considera o saldo **atual**, não o histórico na data.
- Custo: entradas aceitam `unit_cost` opcional (por unidade de estoque), gravado no movimento.
  *Desde o épico 4*, em ingredientes ele recalcula o custo médio pela política WAC-1 (D-17);
  em produtos continua só registrado.
- Sem chave de idempotência: o frontend bloqueia duplo envio. A chave exigida por
  ARCHITECTURE §8 para vendas será implementada no épico 6 — ver P-10.

### D-16 Local de estoque padrão
DATABASE §3.12: um local "Estoque geral" por tenant, criado com o tenant (evento
`TenantCreated`) e, para tenants existentes, pela migration. Não há tela nem API para criar
locais no MVP; as APIs já aceitam `locationId` opcional (padrão = local padrão).

### D-17 Política de custo WAC-1 (resolve P-01)
**Decisão:** custo médio ponderado (*weighted average cost*), por ingrediente e por tenant,
somando o saldo de todos os locais. Identificador versionado: `WAC-1`
(`Shared/Domain/CostPolicy::CURRENT`), gravado em cada mudança de custo feita pelo estoque e em
cada snapshot de custo de receita — REQUIREMENTS §5.9 exige política "explícita e versionada".
Implementação isolada num serviço de domínio (`Inventory/Domain/WeightedAverageCost`), como pede
ARCHITECTURE §9.

**Por quê:** ARCHITECTURE §9 recomenda "custo médio ponderado para estoque de ingredientes, com
snapshot do custo aplicado"; REQUIREMENTS §5.9 aceita "custo médio definido".
*Rejeitadas:* último custo de compra (oscila a cada recebimento e não existe fluxo de compra ainda);
FIFO (exige lotes — FNO-034, P1, não implementado).

**O que muda o custo (`ingredients.average_unit_cost`, NUMERIC(18,6), por unidade de estoque):**

| Evento | Efeito |
|--------|--------|
| Cadastro do ingrediente (FR-014: custo de aquisição obrigatório) | Define o custo; histórico `MANUAL` |
| Edição do custo no cadastro | Substitui o custo médio (correção explícita do usuário); histórico `MANUAL` se o valor mudou |
| Entrada com `unit_cost` (saldo inicial, ajuste de entrada, devolução; no futuro, recebimento de compra) | `novo = (Q·médio + q·c) ÷ (Q + q)`; com `Q = 0`, `novo = c`. Histórico `STOCK_MOVEMENT` com o ID do movimento |
| Entrada **sem** `unit_cost` | Valorizada pelo custo médio vigente → custo não muda |
| Saída (perda, ajuste de saída; no futuro, venda e consumo na produção) | Consome pelo custo médio vigente → custo não muda |
| Estorno de entrada **com** custo | `novo = (Q·médio − q·c) ÷ (Q − q)`. Se `Q − q = 0` ou o valor restante ficaria negativo (houve consumo pelo médio depois da entrada), o custo **não muda** |
| Estorno de saída ou de entrada sem custo | Custo não muda |

`Q` = saldo total do ingrediente (todos os locais) *antes* do movimento, lido com a linha do
ingrediente bloqueada (`SELECT … FOR UPDATE`). Ordem de bloqueio fixa: ingrediente → saldo. A
edição manual do custo usa o mesmo bloqueio, então custo e saldo nunca são lidos fora de ordem.
Produtos **não** têm custo de estoque neste épico (o custo do produto acabado e o snapshot por
ordem de produção — regra §5.10 — ficam para o épico 5); `unit_cost` em entradas de produto só
fica registrado no movimento.

**Custo desconhecido:** não existe — FR-014 torna o custo de aquisição obrigatório no cadastro e
a coluna é `NOT NULL`. O valor 0 é aceito (pode ser real ou "não informado"; o sistema não sabe
distinguir), e a ficha técnica mostra o aviso `ZERO_COST` para o usuário confirmar.

**Precisão e arredondamento:** cálculos em bcmath com escala 12; nada em ponto flutuante.
Custo médio: arredondado *half up* para 6 casas a cada mudança (o arredondamento acumulado ao
longo de muitas entradas é de no máximo 0,0000005 por evento — aceito). Quantidades: 6 casas,
sem arredondar (conversão exata, D-14).

**Histórico de custo (`ingredient_cost_history`, append-only no banco):** custo anterior, novo,
origem (`INITIAL` — custo vigente quando a migration criou o histórico; `MANUAL`;
`STOCK_MOVEMENT` + movimento + política), autor e data. É a "data e origem do custo usado no
cálculo" do FR-025 e permite reconstruir o custo médio.

**Isolamento:** histórico e custos são tenant-scoped (FKs compostas, consultas com tenant
explícito); o histórico só é lido via `GET /ingredients/{id}/cost-history` do tenant ativo.

**Em aberto:** validar com contador/usuários (ARCHITECTURE §9 pede "confirmar antes de fechar a
regra") — P-13. Uma mudança de regra gera `WAC-2` sem reescrever o histórico.

### D-18 Fichas técnicas: versões, custo ao vivo e snapshots (épico 4)
- **Versões (FR-023, DATABASE §3.10):** cada versão é uma linha de `recipes`. Rascunho (`DRAFT`)
  é o único estado editável; ativar arquiva a versão ativa anterior (no máximo uma ativa e um
  rascunho por produto, índices parciais). Uma "alteração relevante" = qualquer alteração
  publicada: o fluxo é sempre *nova versão → editar → ativar*. Triggers impedem alterar/excluir
  versões publicadas, seus itens e custos adicionais, e só permitem `ACTIVE → ARCHIVED`.
  Rascunhos podem ser descartados.
- **Vínculo (FR-020):** só produto `MANUFACTURED`. Enquanto houver ficha aberta (rascunho ou
  ativa), o Catalog não deixa o produto mudar de tipo nem a unidade de venda mudar de dimensão,
  nem um ingrediente usado mudar a unidade de estoque de dimensão (porta `RecipeUsageChecker`).
- **Unidades:** unidade de cada item na mesma dimensão da unidade de estoque do ingrediente;
  rendimento na mesma dimensão da unidade de venda do produto (pré-requisito para a entrada do
  produto acabado no épico 5). Massa ↔ volume exige densidade: não suportado.
- **Custo estimado (FR-022):** calculado *ao vivo* a cada leitura, com o custo vigente de cada
  ingrediente — não é armazenado na receita. Mostra custo por lote (ingredientes, adicionais,
  total), por unidade de rendimento e por unidade de venda. Totais em dinheiro com 2 casas
  (*half up*); custos unitários com 6 casas, calculados do total **não** arredondado.
  Avisos: `NO_ITEMS`, `INGREDIENT_MISSING`, `UNIT_INCOMPATIBLE`, `YIELD_UNIT_INCOMPATIBLE`
  (impedem ativar), `ZERO_COST`, `INACTIVE_INGREDIENT` (só alertam).
- **Custos adicionais (FR-024):** linhas por lote (descrição + valor, NUMERIC(14,2)),
  separadas do custo de ingredientes.
- **Histórico de custo da receita (FNO-044):** `recipe_cost_snapshots`, append-only. Um snapshot
  é gravado ao ativar uma versão e quando o usuário pede ("Registrar custo atual", só na versão
  ativa). Guarda totais, custo por unidade, política (`WAC-1`) e, em JSON, cada linha com o custo,
  a data e a origem usados. Mudanças de custo dos ingredientes **não** alteram snapshots.
  O snapshot por ordem de produção (regra §5.10) é do épico 5.
- **Permissões:** `recipe.read` para proprietário, gerente, produção e estoque ("Produção
  consulta receitas"); `recipe.write` para proprietário e gerente. O caixa não acessa ("não altera
  receitas, custos"). Ver P-14.
- **Fora do escopo:** margem (DATABASE §6.6), custo de mão de obra calculado, sub-receitas,
  densidade, importação de receitas.

## 3. Pendências e riscos

- **P-01 Política de custo** — *resolvida no épico 4*: WAC-1 (D-17). Validação externa em P-13.
- **P-02 Auditoria** (NFR-004): a tabela `audit_logs` ainda não existe. Os movimentos de
  estoque já guardam autor, instante, entidade e motivo no próprio ledger; trocas de
  papel/situação de membros ainda não são auditadas.
- **P-03 OpenAPI** (Definition of Done): a API está documentada à mão em `API.md`. Avaliar
  `nelmio/api-doc-bundle` para gerar e versionar o OpenAPI.
- **P-04 Convite e recuperação de senha** (FNO-014 / FR-006, P1): exige envio de e-mail.
- **P-05 Sessões em Redis** para mais de um container PHP (D-05).
- **P-06 Testes E2E (Playwright)** e teste manual em tablet real (FNO-124).
- **P-07 CI** foi escrito mas ainda não rodou no GitHub (o repositório não tem remoto).
- **P-08 Exclusão de produtos/ingredientes:** só desativação (`active=false`). Exclusão física
  deve continuar proibida quando houver movimentos de estoque ou vendas (o banco já impede:
  FKs do ledger).
- **P-09 Exceção de estoque negativo** (FR-036): "configurada explicitamente e auditada". Exige
  P-02 e uma configuração por tenant/item; o `CHECK` do banco precisará considerar essa
  configuração. Até lá, negativo é sempre bloqueado.
- **P-10 Idempotência** (ARCHITECTURE §8): implementar chave de idempotência junto com a
  conclusão de venda (épico 6); avaliar estender aos lançamentos de estoque.
- **P-11 Matriz de permissões do estoque** (D-06): confirmar com os pilotos se o papel Estoque
  pode lançar ajustes sem aprovação do gerente.
- **P-12 Inventário por contagem**: hoje o usuário calcula a diferença e lança um ajuste. Uma
  tela "informe a contagem e o sistema calcula o ajuste" é melhoria futura, fora do backlog atual.
- **P-13 Validar WAC-1 com contador e pilotos** (ARCHITECTURE §9). Pontos a confirmar: edição
  manual do custo substituir a média; estorno que não reverte o custo quando houve consumo; uso
  do saldo de todos os locais.
- **P-14 Visibilidade de custo por papel:** hoje quem tem `recipe.read` (produção, estoque) vê o
  custo estimado. Confirmar se custo deve ter permissão própria.
- **P-15 Custo do produto acabado** e snapshot por ordem de produção: épico 5 (regra §5.10).
- **P-16 Concorrência entre ativações:** duas ativações simultâneas do mesmo produto são
  barradas pelos índices únicos (a segunda recebe 409 `DUPLICATE`), não por bloqueio explícito.
  Aceito para o porte do MVP.
