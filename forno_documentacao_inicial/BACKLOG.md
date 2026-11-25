# BACKLOG.md --- Backlog priorizado

## 1. Estratégia

Priorizar o menor caminho até um piloto que consiga registrar cadastros,
estoque, produção e vendas de forma consistente. Não começar por
dashboards sofisticados, integrações fiscais ou microserviços.

Estimativas são relativas (P = pequeno, M = médio, G = grande), não
promessas de prazo. Reestimar após o primeiro sprint.

## 2. Épicos e ordem

  -------------------------------------------------------------------------
                  Ordem Épico            Prioridade       Resultado
  --------------------- ---------------- ---------------- -----------------
                      0 Fundação e       P0               Projeto
                        qualidade                         executável, CI e
                                                          Docker

                      1 Identidade e     P0               Usuários isolados
                        tenants                           por
                                                          estabelecimento

                      2 Catálogo e       P0               Produtos,
                        unidades                          ingredientes e
                                                          medidas

                      3 Estoque e ledger P0               Saldo rastreável
                                                          e inventário
                                                          inicial

                      4 Receitas e       P0               Fichas técnicas e
                        custos                            custo estimado

                      5 Produção         P0               Ordem consome
                                                          insumos e gera
                                                          produto

                      6 Caixa e vendas   P0               Venda consistente
                                                          com pagamento e
                                                          estoque

                      7 Dashboard        P0               Visão diária
                        operacional                       confiável

                      8 Comandas e mesas P1               Atendimento misto

                      9 Encomendas       P1               Pedidos com
                                                          retirada e sinal

                     10 Compras e        P1               Reposição
                        fornecedores                      rastreável

                     11 Despesas e       P1               Visão financeira
                        relatórios                        operacional

                     12 Piloto e         P0 antes de      Segurança,
                        hardening        produção         backup, suporte e
                                                          correções

                     13 Fiscal           P2               Integração com
                                                          provedor após
                                                          validação
  -------------------------------------------------------------------------

## 3. Histórias priorizadas

### Épico 0 --- Fundação e qualidade

-   **FNO-001 \[P0, M\]** Criar repositório com backend Symfony,
    frontend Vue/TS e Docker Compose.
    -   Aceite: `docker compose up` inicia app, banco e frontend; README
        documenta setup.
-   **FNO-002 \[P0, M\]** Configurar CI.
    -   Aceite: pipeline executa testes backend/frontend, análise
        estática e lint.
-   **FNO-003 \[P0, P\]** Configurar padrão de código, formatter e hooks
    opcionais.
-   **FNO-004 \[P0, P\]** Configurar health checks, logs e tratamento
    global de erros.

### Épico 1 --- Identidade e tenants

-   **FNO-010 \[P0, M\]** Criar usuário e login.
    -   Aceite: senha hasheada; erros genéricos; rate limit no login.
-   **FNO-011 \[P0, M\]** Criar estabelecimento e vínculo do
    proprietário.
    -   Aceite: primeiro usuário cria tenant e recebe papel OWNER.
-   **FNO-012 \[P0, M\]** Resolver tenant ativo e proteger endpoints.
    -   Aceite: testes demonstram que tenant A não acessa IDs de tenant
        B.
-   **FNO-013 \[P0, M\]** Criar papéis e autorização por ação.
-   **FNO-014 \[P1, M\]** Convite e recuperação de senha.

### Épico 2 --- Catálogo e unidades

-   **FNO-020 \[P0, M\]** CRUD de categorias e unidades.
-   **FNO-021 \[P0, M\]** CRUD de produtos vendáveis.
-   **FNO-022 \[P0, M\]** CRUD de ingredientes.
-   **FNO-023 \[P1, P\]** Busca por nome, SKU e código de barras.
-   **FNO-024 \[P0, M\]** Conversão de unidade dentro da mesma dimensão.
    -   Aceite: kg↔g, L↔ml e unidade↔unidade; conversões incompatíveis
        são rejeitadas.

### Épico 3 --- Estoque e ledger

-   **FNO-030 \[P0, G\]** Criar movimentações de estoque imutáveis.
-   **FNO-031 \[P0, M\]** Registrar saldo inicial.
-   **FNO-032 \[P0, M\]** Consultar saldo por item e localização.
-   **FNO-033 \[P0, M\]** Registrar perda e ajuste com motivo.
-   **FNO-034 \[P1, G\]** Controlar lotes e validade.
-   **FNO-035 \[P1, M\]** Alertar mínimo e vencimento.
    -   Aceite: saldo exibido corresponde à soma dos movimentos;
        tentativas concorrentes não geram saldo negativo.

### Épico 4 --- Receitas e custos

-   **FNO-040 \[P0, M\]** Criar receita vinculada a produto.
-   **FNO-041 \[P0, M\]** Adicionar ingredientes, quantidades e
    unidades.
-   **FNO-042 \[P0, M\]** Calcular custo de lote e unidade.
-   **FNO-043 \[P0, M\]** Versionar receita.
-   **FNO-044 \[P1, M\]** Incluir custo adicional e histórico de custo.
    -   Aceite: receita de exemplo com 10 kg de farinha, 6 L de água,
        200 g de fermento e 200 g de sal calcula custo conforme custos
        de estoque e rendimento configurado.

### Épico 5 --- Produção

-   **FNO-050 \[P0, M\]** Criar ordem de produção.
-   **FNO-051 \[P0, M\]** Calcular consumo previsto.
-   **FNO-052 \[P0, G\]** Concluir ordem transacionalmente.
-   **FNO-053 \[P0, M\]** Registrar rendimento real e perdas.
-   **FNO-054 \[P0, M\]** Bloquear conclusão duplicada.
    -   Aceite: ingredientes são baixados uma vez; produto acabado entra
        uma vez; rollback integral em falha.

### Épico 6 --- Caixa e vendas

-   **FNO-060 \[P0, M\]** Abrir e fechar sessão de caixa.
-   **FNO-061 \[P0, M\]** Criar carrinho/rascunho de venda.
-   **FNO-062 \[P0, G\]** Concluir venda com itens, pagamentos, caixa e
    estoque.
-   **FNO-063 \[P0, M\]** Venda por unidade e peso.
-   **FNO-064 \[P0, M\]** Cancelar venda com movimentos reversos.
-   **FNO-065 \[P0, M\]** Idempotência e proteção contra duplo
    clique/retry.
-   **FNO-066 \[P0, P\]** Aviso visível: emissão fiscal não disponível
    no MVP.
    -   Aceite: venda concluída nunca duplica pagamento ou baixa; falha
        em qualquer passo não deixa estado parcial.

### Épico 7 --- Dashboard

-   **FNO-070 \[P0, M\]** Indicadores de venda do dia.
-   **FNO-071 \[P0, M\]** Alertas de estoque mínimo.
-   **FNO-072 \[P0, M\]** Produção pendente.
-   **FNO-073 \[P1, M\]** Produtos mais vendidos, perdas e custo
    estimado.

### Épico 8 --- Comandas e mesas

-   **FNO-080 \[P1, M\]** Criar mesa/comanda.
-   **FNO-081 \[P1, M\]** Adicionar/remover itens enquanto aberta, com
    trilha de auditoria.
-   **FNO-082 \[P1, M\]** Fechar comanda e gerar venda/pagamento sem
    baixa duplicada.
    -   Aceite: comanda aberta não representa receita recebida; fechar
        duas vezes é impossível.

### Épico 9 --- Encomendas

-   **FNO-090 \[P1, M\]** Criar encomenda e itens.
-   **FNO-091 \[P1, M\]** Gerenciar status e data de retirada.
-   **FNO-092 \[P1, M\]** Registrar sinal.
-   **FNO-093 \[P1, G\]** Converter retirada em venda conciliando sinal.
    -   Aceite: valor de sinal não é recebido duas vezes e estoque não é
        baixado em duplicidade.

### Épico 10 --- Compras

-   **FNO-100 \[P1, M\]** CRUD de fornecedores.
-   **FNO-101 \[P1, M\]** Criar pedido de compra.
-   **FNO-102 \[P1, G\]** Receber compra total/parcial e gerar ledger de
    estoque.
-   **FNO-103 \[P1, M\]** Atualizar custo conforme política aprovada.

### Épico 11 --- Despesas e relatórios

-   **FNO-110 \[P1, M\]** Registrar despesa e vencimento.
-   **FNO-111 \[P1, M\]** Registrar pagamento e saída financeira.
-   **FNO-112 \[P1, M\]** Relatório de vendas, recebimentos, despesas e
    perdas.
-   **FNO-113 \[P1, P\]** Exportação CSV.

### Épico 12 --- Piloto e hardening

-   **FNO-120 \[P0\]** Teste E2E do fluxo cadastrar → produzir → vender
    → fechar caixa.
-   **FNO-121 \[P0\]** Testes de isolamento multi-tenant.
-   **FNO-122 \[P0\]** Configurar backup e testar restauração.
-   **FNO-123 \[P0\]** Revisar permissões, rate limiting, logs e
    segredos.
-   **FNO-124 \[P0\]** Testar em computador e tablet reais.
-   **FNO-125 \[P0\]** Instrumentar erros e métricas de uso.
-   **FNO-126 \[P0\]** Rodar piloto acompanhado e corrigir bloqueadores.

### Épico 13 --- Fiscal futuro

-   **FNO-130 \[P2\]** Mapear requisitos fiscais por UF e perfil de
    cliente.
-   **FNO-131 \[P2\]** Selecionar provedor e validar homologação.
-   **FNO-132 \[P2\]** Implementar adapter fiscal, documentos,
    retentativas e contingência.
-   **FNO-133 \[P2\]** Testar emissão/cancelamento/inutilização conforme
    documentos aplicáveis.

## 4. Definition of Done

Uma história só está concluída quando: - regra de negócio implementada
no backend; - autorização e tenant verificados; - migration revisada; -
testes unitários e integração relevantes passam; - frontend tem estados
loading/empty/error; - logs não expõem dados sensíveis; - documentação e
OpenAPI atualizadas; - teste manual em desktop e tablet quando houver
UI; - nenhuma regra crítica depende apenas do frontend.

## 5. Primeiro incremento recomendado

Implementar somente: 1. FNO-001: ambiente e repositório; 2. FNO-002: CI;
3. FNO-010 a FNO-012: login, tenant e isolamento; 4. FNO-020 a FNO-022:
categorias, unidades e cadastros.

Não começar pelo PDV antes de provar tenant, unidades, catálogo e
estoque.
