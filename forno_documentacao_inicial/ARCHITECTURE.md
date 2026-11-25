# ARCHITECTURE.md --- Arquitetura técnica

## 1. Objetivo

Construir um SaaS multi-tenant para pequenas padarias, com consistência
transacional entre vendas, caixa, produção e estoque. Priorizar
simplicidade operacional e capacidade de evolução. O MVP será um
monólito modular, não uma arquitetura de microserviços.

## 2. Stack proposta

  -----------------------------------------------------------------------
  Camada                              Tecnologia
  ----------------------------------- -----------------------------------
  Backend                             PHP 8.4 + Symfony 7

  API                                 REST/JSON, versionada em `/api/v1`

  Frontend                            Vue 3 + TypeScript + Vite

  UI                                  Biblioteca de componentes
                                      consistente, com foco em touch

  Banco                               PostgreSQL

  Cache/filas                         Redis + Symfony Cache/Messenger,
                                      quando necessário

  Arquivos                            Object storage compatível com S3
                                      para anexos e exportações

  Deploy                              Docker + Nginx + PHP-FPM

  Testes                              PHPUnit, Symfony WebTestCase,
                                      Vitest e Playwright

  CI                                  Composer, PHPStan/Psalm, PHP CS
                                      Fixer, ESLint, TypeScript, testes
  -----------------------------------------------------------------------

Fixar versões exatas no `composer.lock` e lockfile do frontend.
Verificar compatibilidade real da versão do Symfony com PHP 8.4 antes de
iniciar.

## 3. Topologia lógica

``` text
Desktop / Tablet Browser
        |
        | HTTPS
        v
Nginx / Reverse Proxy
        |
        +---- Vue PWA (assets estáticos)
        |
        +---- /api/v1 ---- Symfony Application
                              |
                              +---- PostgreSQL
                              |
                              +---- Redis (cache/queue)
                              |
                              +---- Object Storage
                              |
                              +---- External providers (future)
```

No desenvolvimento, Docker Compose deve subir backend, frontend,
PostgreSQL e Redis. Em produção, separar serviços por container, com
secrets fora da imagem.

## 4. Organização do repositório

``` text
forno/
├── backend/
│   ├── src/
│   │   ├── Shared/
│   │   ├── Identity/
│   │   ├── Tenant/
│   │   ├── Catalog/
│   │   ├── Inventory/
│   │   ├── Recipe/
│   │   ├── Production/
│   │   ├── Purchasing/
│   │   ├── Sales/
│   │   ├── Cash/
│   │   ├── Finance/
│   │   ├── Customer/
│   │   ├── Orders/
│   │   ├── Reporting/
│   │   └── Subscription/
│   ├── migrations/
│   └── tests/
├── frontend/
│   ├── src/
│   │   ├── app/
│   │   ├── modules/
│   │   ├── shared/
│   │   └── router/
│   └── tests/
├── infrastructure/
│   ├── docker/
│   └── nginx/
├── docs/
└── docker-compose.yml
```

Cada módulo deve conter, conforme a complexidade: - `Domain/`:
entidades, value objects, regras e interfaces; - `Application/`: casos
de uso/handlers, DTOs e orquestração; - `Infrastructure/`: Doctrine
repositories, integrações e persistência; - `Presentation/`:
controllers, requests e serializers.

Não criar camadas vazias por formalismo. Começar simples e separar
quando houver regra de domínio real.

## 5. Limites dos módulos

-   **Identity:** autenticação, credenciais, recuperação de senha.
-   **Tenant:** estabelecimento, associação de usuário, papéis e
    contexto ativo.
-   **Catalog:** produtos, ingredientes, categorias e unidades.
-   **Inventory:** lotes, saldos, movimentações, inventário, validade.
-   **Recipe:** fichas técnicas, versões, itens e cálculo de custo.
-   **Production:** ordens, consumo, rendimento e entrada de produto
    acabado.
-   **Purchasing:** fornecedores, pedidos e recebimentos.
-   **Sales:** vendas, itens, descontos, cancelamentos.
-   **Cash:** sessões de caixa, entradas, saídas, pagamentos e
    fechamento.
-   **Finance:** despesas, contas a pagar/receber e fluxo financeiro.
-   **Customer:** clientes.
-   **Orders:** encomendas, mesas e comandas.
-   **Reporting:** consultas agregadas e indicadores.
-   **Subscription:** plano, limites e status.
-   **Fiscal (futuro):** documentos fiscais, provedor e status de
    autorização. Não implementar emissão no MVP.

Módulos podem chamar casos de uso públicos de outros módulos; evitar
acesso direto a tabelas internas/repositories de outro módulo. Eventos
internos podem desacoplar efeitos secundários, mas operações críticas
devem manter consistência transacional.

## 6. Multi-tenancy

Estratégia inicial: **banco compartilhado, schema compartilhado,
`tenant_id` em todas as tabelas de negócio**.

Regras: 1. O tenant ativo é resolvido no backend a partir da associação
autenticada do usuário; nunca confiar em `tenant_id` enviado pelo
cliente sem validar associação. 2. Toda query de negócio inclui tenant.
3. Chaves estrangeiras entre entidades tenant-scoped devem incluir
`tenant_id` quando viável, para impedir referências cruzadas no nível do
banco. 4. Criar um `TenantContext` por requisição e injetá-lo nos casos
de uso. 5. Testar tentativas de leitura, alteração e associação de IDs
de outro tenant. 6. Não usar filtros Doctrine como única barreira de
segurança; a autorização deve ser explícita e testada. 7. Avaliar
PostgreSQL Row-Level Security numa fase posterior, após desenho
cuidadoso de pool/conexões e contexto de sessão.

## 7. Identificadores e dados

-   IDs: UUID v7 ou UUID aleatório, gerados no backend. Escolher um
    padrão único.
-   Dinheiro: `NUMERIC(14,2)` para valores finais; custos unitários e
    cálculos intermediários podem exigir escala maior, por exemplo
    `NUMERIC(18,6)`.
-   Quantidade: `NUMERIC(18,6)` ou precisão maior quando a operação
    exigir.
-   Datas: `TIMESTAMPTZ`, persistidas em UTC.
-   Fuso: configuração por tenant, padrão `America/Sao_Paulo`.
-   Soft delete: somente cadastros em que fizer sentido. Não aplicar a
    movimentos financeiros, vendas ou estoque.
-   Estado: enums/check constraints no banco quando apropriado; evitar
    estados livres digitados.

## 8. Consistência transacional

### Concluir venda

Dentro de uma transação: 1. Validar sessão de caixa aberta. 2. Revalidar
preço, desconto, quantidades e permissões. 3. Calcular total no backend.
4. Criar venda e itens. 5. Criar pagamentos. 6. Criar movimentos de
caixa. 7. Criar movimentos de estoque conforme política de baixa. 8.
Marcar venda como concluída. 9. Commit.

Se qualquer etapa falhar, rollback integral. Usar chave de idempotência
para evitar duplicação por double-click/retry de rede.

### Concluir produção

Dentro de uma transação: 1. Bloquear/validar ordem e versão da receita.
2. Validar disponibilidade dos ingredientes. 3. Registrar consumo dos
ingredientes. 4. Registrar entrada do produto acabado. 5. Registrar
quantidade produzida e perdas. 6. Marcar ordem concluída. 7. Commit.

### Cancelamentos

Não apagar movimentos. Gerar movimentos reversos ligados ao registro
original. Bloquear cancelamento duplicado e exigir permissão/motivo.

## 9. Estoque e custo

Manter ledger de movimentações, não apenas um campo de saldo. O saldo
pode ser materializado/cacheado, mas deve ser reconstruível a partir do
ledger.

Tipos iniciais: - `OPENING_BALANCE` - `PURCHASE_RECEIPT` -
`PRODUCTION_CONSUMPTION` - `PRODUCTION_OUTPUT` - `SALE` - `WASTE` -
`ADJUSTMENT_IN` - `ADJUSTMENT_OUT` - `RETURN_IN` - `REVERSAL`

Lotes e validade são importantes para perecíveis. Aplicar FEFO
(first-expire, first-out) como sugestão de separação, não como baixa
silenciosa sem rastreabilidade.

A política de custo deve ser isolada num serviço de domínio.
Recomendação inicial: custo médio ponderado para estoque de
ingredientes, com snapshot do custo aplicado em cada ordem de produção.
Confirmar com contador/usuários antes de fechar a regra.

## 10. API

-   Prefixo `/api/v1`.
-   JSON com envelope consistente e erros padronizados.
-   Paginação por cursor ou page/limit com limites máximos.
-   Validação de DTO no backend.
-   OpenAPI gerado e versionado.
-   Não expor entidades Doctrine diretamente.
-   Autenticação por sessão segura ou token conforme estratégia de
    frontend; para frontend próprio no mesmo domínio, preferir cookie
    HttpOnly, Secure, SameSite e proteção CSRF.
-   CORS restrito aos domínios necessários.

Endpoints ilustrativos: - `POST /api/v1/sales` -
`POST /api/v1/sales/{id}/complete` - `POST /api/v1/sales/{id}/cancel` -
`POST /api/v1/production-orders` -
`POST /api/v1/production-orders/{id}/complete` -
`POST /api/v1/cash-sessions` - `POST /api/v1/cash-sessions/{id}/close` -
`GET /api/v1/dashboard`

## 11. Frontend e experiência responsiva

-   Layout desktop com navegação lateral e área principal.
-   Tablet com navegação compacta e alvos de toque grandes.
-   PDV deve priorizar busca rápida, categorias e carrinho visível.
-   Formulários longos devem ser divididos em etapas.
-   Estados de carregamento, vazio, erro e sucesso explícitos.
-   Não guardar dados sensíveis em localStorage.
-   PWA pode oferecer instalação e cache de assets; isso não significa
    funcionamento offline de vendas.
-   Não habilitar service worker para cache de respostas autenticadas
    sem política de invalidação e isolamento.

## 12. Segurança e LGPD

-   HTTPS obrigatório em produção.
-   Segredos via secret manager/environment seguro, nunca commitados.
-   Hash de senha via componente Symfony PasswordHasher.
-   Rate limiting em login e endpoints sensíveis.
-   CSRF se autenticação por cookie.
-   Controle de acesso no backend.
-   Auditoria de ações críticas.
-   Backups criptografados e restauração testada.
-   Logs não devem registrar senha, token, dados completos de cartão ou
    dados pessoais desnecessários.
-   Definir política de retenção, exportação e exclusão conforme
    obrigações legais.
-   Contratos com provedores e operadores devem ser avaliados sob LGPD.

## 13. Fiscal futuro

Não implementar emissão no MVP. Preparar: - módulo independente; -
interface `FiscalDocumentProvider`; - entidades para documento,
tentativa, status e referência externa; - associação entre venda e
documento fiscal; - idempotência e retentativas; - armazenamento seguro
de XML/PDF e protocolo; - logs de auditoria.

A escolha do provedor deve considerar UF, regime tributário, tipos de
documento, contingência, homologação, custos e suporte. Nunca assumir
que um único fluxo serve para todas as padarias.

## 14. Observabilidade e operação

-   Logs JSON com request ID e tenant ID (sem dados sensíveis).
-   Monitoramento de exceções e latência.
-   Health checks de aplicação, banco e Redis.
-   Backups automáticos diários e retenção definida.
-   Teste de restauração periódico.
-   Migrações executadas como etapa controlada de deploy.
-   Deploy com rollback documentado.
-   Métricas de uso: vendas registradas, caixas fechados, ordens
    concluídas e erros.

## 15. Estratégia de testes

-   Unitários: cálculos de custo, conversão de unidades, descontos e
    regras de status.
-   Integração: repositories, transações, estoque, caixa e persistência.
-   API: autorização, validação e isolamento de tenant.
-   E2E: cadastrar produto, produzir, vender, fechar caixa e consultar
    dashboard.
-   Concorrência: duas vendas simultâneas não podem consumir o mesmo
    saldo abaixo de zero.
-   Idempotência: retry de conclusão não duplica venda, pagamento ou
    movimento.
-   Frontend: fluxos principais em desktop e tablet.

## 16. Sequência de entrega

1.  Bootstrap, CI, ambiente Docker e autenticação.
2.  Tenant, usuários e autorização.
3.  Catálogo e unidades.
4.  Estoque ledger e inventário inicial.
5.  Receitas e custos.
6.  Produção.
7.  Caixa e vendas.
8.  Comandas e encomendas.
9.  Despesas, dashboard e relatórios.
10. Piloto, correções e preparação fiscal futura.
