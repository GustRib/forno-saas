# REQUIREMENTS.md --- Requisitos e regras de negócio

## 1. Convenções

Prioridade: - **P0:** obrigatório para o MVP funcionar. - **P1:**
importante para o piloto ou primeira evolução. - **P2:** posterior.

Cada requisito deve ser implementado com testes automatizados e
critérios de aceite verificáveis.

## 2. Atores e permissões

### Proprietário/Administrador

-   Gerencia estabelecimento, usuários, configurações e permissões.
-   Acessa todos os módulos do seu tenant.
-   Autoriza cancelamentos, ajustes e descontos acima do limite.

### Gerente

-   Gerencia operação, estoque, produção, vendas e caixa conforme
    permissão.
-   Consulta relatórios.
-   Não altera dados de assinatura ou propriedade da conta.

### Operador/Caixa

-   Registra vendas e pagamentos.
-   Abre/fecha caixa se autorizado.
-   Consulta catálogo e comandas.
-   Não altera receitas, custos ou configurações sensíveis sem
    permissão.

### Produção/Estoque

-   Consulta receitas e ordens de produção.
-   Registra produção, perdas, recebimentos e inventário conforme
    permissão.

O sistema deve permitir permissões por módulo/ação. Não confiar apenas
em ocultar botões no frontend.

## 3. Requisitos funcionais

### Identidade e multi-tenant

-   **FR-001 \[P0\]** O usuário deve autenticar-se com e-mail e senha.
-   **FR-002 \[P0\]** Um usuário deve pertencer a um ou mais
    estabelecimentos por meio de associação explícita.
-   **FR-003 \[P0\]** Toda consulta e mutação de dados de negócio deve
    ser limitada ao tenant ativo.
-   **FR-004 \[P0\]** O backend deve validar associação e permissão em
    cada endpoint.
-   **FR-005 \[P0\]** O administrador deve poder convidar/desativar
    usuários.
-   **FR-006 \[P1\]** Suportar recuperação de senha e verificação de
    e-mail.

### Cadastros

-   **FR-010 \[P0\]** Cadastrar produtos de venda, ingredientes e
    produtos acabados.
-   **FR-011 \[P0\]** Cadastrar categorias e unidades de medida.
-   **FR-012 \[P0\]** Cadastrar fornecedores e clientes.
-   **FR-013 \[P0\]** Produtos devem possuir SKU/código opcional, nome,
    categoria, unidade de venda, preço e status ativo.
-   **FR-014 \[P0\]** Ingredientes devem possuir unidade-base de estoque
    e custo de aquisição.
-   **FR-015 \[P1\]** Permitir código de barras e aliases de busca.

### Receitas e custos

-   **FR-020 \[P0\]** Criar ficha técnica vinculada a produto acabado.
-   **FR-021 \[P0\]** Uma receita deve conter ingredientes, quantidades,
    unidade, rendimento e instruções opcionais.
-   **FR-022 \[P0\]** Calcular custo estimado dos ingredientes por lote
    e por unidade produzida.
-   **FR-023 \[P0\]** Registrar versão/histórico da receita quando
    houver alteração relevante.
-   **FR-024 \[P1\]** Permitir custo adicional estimado (embalagem,
    energia ou mão de obra) separado do custo de ingredientes.
-   **FR-025 \[P1\]** Exibir data e origem do custo usado no cálculo.

### Estoque e compras

-   **FR-030 \[P0\]** Registrar saldo inicial por item.
-   **FR-031 \[P0\]** Toda mudança de estoque deve gerar movimentação
    imutável com tipo, quantidade, origem, usuário e data.
-   **FR-032 \[P0\]** Registrar entrada por compra/recebimento.
-   **FR-033 \[P0\]** Registrar consumo por produção.
-   **FR-034 \[P0\]** Registrar saída por venda de produto acabado.
-   **FR-035 \[P0\]** Registrar perda, descarte, devolução e ajuste com
    motivo.
-   **FR-036 \[P0\]** Bloquear estoque negativo por padrão; exceção só
    poderá ser configurada explicitamente e auditada.
-   **FR-037 \[P1\]** Controlar lote e validade de ingredientes e
    produtos quando informados.
-   **FR-038 \[P1\]** Alertar estoque abaixo do mínimo e lotes próximos
    do vencimento.
-   **FR-039 \[P1\]** Registrar pedido de compra e recebimento
    parcial/total.

### Produção

-   **FR-040 \[P0\]** Criar ordem de produção baseada em receita e
    número de lotes.
-   **FR-041 \[P0\]** Calcular consumo previsto de ingredientes.
-   **FR-042 \[P0\]** Na conclusão, registrar consumo real e quantidade
    produzida.
-   **FR-043 \[P0\]** Dar entrada no estoque do produto acabado
    produzido.
-   **FR-044 \[P0\]** Impedir concluir duas vezes a mesma ordem.
-   **FR-045 \[P1\]** Registrar perdas de processo e diferença entre
    rendimento esperado e real.
-   **FR-046 \[P1\]** Permitir status rascunho, planejada, em andamento,
    concluída e cancelada.

### Vendas, caixa e pagamentos

-   **FR-050 \[P0\]** Abrir sessão de caixa com saldo inicial.
-   **FR-051 \[P0\]** Registrar venda com um ou mais itens.
-   **FR-052 \[P0\]** Permitir item vendido por unidade ou peso, com
    precisão decimal adequada.
-   **FR-053 \[P0\]** Registrar um ou mais pagamentos por venda.
-   **FR-054 \[P0\]** Registrar método de pagamento: dinheiro, PIX,
    débito, crédito, transferência ou outro configurável.
-   **FR-055 \[P0\]** Ao concluir venda, registrar baixa de estoque e
    recebimento/caixa dentro de uma transação consistente.
-   **FR-056 \[P0\]** Fechar caixa com total esperado, valor contado e
    diferença.
-   **FR-057 \[P0\]** Cancelar venda somente com permissão e gerando
    movimentos reversos; não apagar registros.
-   **FR-058 \[P0\]** Registrar desconto e usuário responsável.
-   **FR-059 \[P1\]** Suportar comandas/mesas com itens adicionados
    antes do pagamento.
-   **FR-060 \[P1\]** Permitir fechamento de comanda com pagamentos
    múltiplos.
-   **FR-061 \[P1\]** Relatório de vendas por período, produto e método
    de pagamento.

### Encomendas

-   **FR-070 \[P1\]** Criar encomenda com cliente, itens, observações e
    data/hora de retirada.
-   **FR-071 \[P1\]** Controlar status da encomenda.
-   **FR-072 \[P1\]** Registrar sinal/adiantamento como pagamento
    vinculado à encomenda.
-   **FR-073 \[P1\]** Ao retirar, converter em venda sem duplicar
    estoque ou recebimentos.
-   **FR-074 \[P1\]** Cancelamento deve tratar sinal e reserva de
    estoque de forma explícita.

### Financeiro

-   **FR-080 \[P0\]** Registrar despesas/contas a pagar com vencimento,
    categoria, valor e status.
-   **FR-081 \[P0\]** Registrar pagamento de despesa e refletir no
    caixa/financeiro.
-   **FR-082 \[P1\]** Registrar contas a receber não originadas de
    venda.
-   **FR-083 \[P1\]** Exibir fluxo de caixa realizado e previsto.
-   **FR-084 \[P1\]** Não classificar faturamento ou margem estimada
    como lucro líquido contábil.

### Painel e relatórios

-   **FR-090 \[P0\]** Exibir vendas do dia, número de vendas e total
    recebido.
-   **FR-091 \[P0\]** Exibir produção planejada/pendente e alertas
    básicos de estoque.
-   **FR-092 \[P1\]** Exibir custo estimado, perdas e produtos mais
    vendidos.
-   **FR-093 \[P1\]** Permitir filtro por período.
-   **FR-094 \[P1\]** Exportar CSV de relatórios básicos.

### Fiscal e assinatura

-   **FR-100 \[P0\]** O sistema deve identificar claramente que a
    emissão fiscal não está disponível no MVP.
-   **FR-101 \[P1\]** Definir interface de domínio para futura
    integração fiscal, sem acoplar vendas a um fornecedor.
-   **FR-102 \[P2\]** Integrar provedor fiscal externo após validação de
    fornecedor, UF, regime tributário e operação real.
-   **FR-103 \[P1\]** Persistir plano, status e limites da assinatura.
-   **FR-104 \[P2\]** Cobrança recorrente automatizada e gestão
    self-service de assinatura.

## 4. Requisitos não funcionais

-   **NFR-001 Segurança:** senhas armazenadas com algoritmo seguro
    suportado pelo Symfony; nunca texto puro.
-   **NFR-002 Isolamento:** testes automatizados devem provar que
    usuário de tenant A não lê nem altera tenant B.
-   **NFR-003 Integridade:** venda, pagamentos, caixa e estoque devem
    ser persistidos em transação.
-   **NFR-004 Auditoria:** operações críticas devem guardar autor,
    instante, entidade e motivo quando aplicável.
-   **NFR-005 Dinheiro:** usar `NUMERIC/DECIMAL`, nunca `float` para
    valores monetários.
-   **NFR-006 Quantidades:** usar decimal com escala suficiente; evitar
    conversões binárias de ponto flutuante.
-   **NFR-007 Responsividade:** suportar desktop e tablet em orientação
    retrato e paisagem.
-   **NFR-008 Usabilidade:** ações de PDV comuns devem exigir poucos
    passos e alvos de toque confortáveis.
-   **NFR-009 Desempenho:** páginas operacionais comuns devem responder
    em até 500 ms no percentil 95 em ambiente de referência, excluindo
    rede externa; validar com carga real.
-   **NFR-010 Disponibilidade:** MVP depende de internet; comunicar
    indisponibilidade claramente.
-   **NFR-011 Backup:** backups automáticos do banco, criptografados e
    com teste periódico de restauração.
-   **NFR-012 Observabilidade:** logs estruturados, correlação de
    requisição e monitoramento de erros.
-   **NFR-013 Privacidade:** coletar apenas dados necessários, controlar
    acesso e permitir atendimento a solicitações LGPD.
-   **NFR-014 Evolução:** arquitetura modular; evitar microserviços no
    MVP.
-   **NFR-015 Compatibilidade:** versões suportadas de navegadores
    modernos Chromium, Firefox e Safari/iPadOS devem ser definidas e
    testadas.

## 5. Regras de negócio fundamentais

1.  Valores monetários são armazenados em BRL com precisão decimal; a
    apresentação pode arredondar para centavos.
2.  Quantidade de ingrediente e peso vendido não devem ser arredondados
    prematuramente.
3.  Cada item de estoque tem uma unidade-base; conversões devem ser
    explícitas e compatíveis por dimensão.
4.  Exemplo: 1 kg = 1.000 g; conversão entre massa e volume exige
    densidade cadastrada, não conversão implícita.
5.  Movimentação de estoque é append-only. Correções são novas
    movimentações reversas/ajustes.
6.  Uma venda concluída não pode ser editada diretamente; deve ser
    cancelada/revertida com permissão.
7.  Uma ordem de produção concluída não pode ser concluída novamente.
8.  Estoque disponível deve considerar reservas apenas quando o recurso
    de reserva estiver ativo.
9.  O custo da receita usa custo de aquisição vigente ou custo médio
    definido; a política precisa ser explícita e versionada.
10. Alterar receita não deve reescrever o custo histórico de produção já
    concluída.
11. Fechamento de caixa registra esperado, contado e diferença; não
    altera vendas anteriores.
12. Pagamento de encomenda não é uma segunda venda. O sinal deve ser
    conciliado no fechamento final.
13. Comanda aberta não gera recebimento até ser paga; itens em aberto
    não podem baixar estoque duas vezes.
14. Datas são armazenadas em UTC e apresentadas no fuso do
    estabelecimento (`America/Sao_Paulo` por padrão).
15. Cancelamento deve reverter estoque, recebimentos e caixa de forma
    rastreável.

## 6. Critérios de aceite transversais

-   Todo endpoint de negócio valida tenant e permissão.
-   Toda operação crítica tem teste de sucesso, falha e isolamento de
    tenant.
-   Toda migration tem estratégia de rollback quando possível.
-   Não há exclusão física de vendas, pagamentos ou movimentações de
    estoque.
-   Erros são apresentados em linguagem compreensível e registrados
    tecnicamente.
-   Frontend não é fonte de verdade para preço, total, permissão ou
    saldo.
-   Cálculos de preço, desconto, custo e estoque são
    recalculados/validados no backend.

## 7. Decisões em aberto que precisam de validação

-   Política de custo de estoque: custo médio ponderado ou último custo
    de compra.
-   Política de baixa de estoque: ao concluir venda ou ao encerrar
    comanda; recomendação inicial: ao concluir venda.
-   Venda por peso: casas decimais, unidade e integração futura com
    balança.
-   Se produção será sempre registrada manualmente ou poderá ser
    sugerida por previsão.
-   Se o caixa será por dispositivo/operador ou por
    estabelecimento/turno.
-   Como tratar taxa de cartão e recebimento futuro.
-   Regras fiscais por UF, regime tributário e tipo de operação antes da
    integração fiscal.
