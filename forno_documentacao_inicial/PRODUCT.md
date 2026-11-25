# PRODUCT.md --- Visão e definição do produto

## 1. Resumo

**Forno** é um ERP SaaS simples e integrado para pequenas padarias que
fabricam e vendem seus próprios produtos. O sistema atende operações
mistas: venda no balcão, consumo em mesas, encomendas, produção,
estoque, compras e controle financeiro.

O produto deve ajudar o proprietário a responder, sem planilhas
paralelas:

-   O que vendi hoje e quanto recebi?
-   O que preciso produzir amanhã?
-   Quanto custa produzir cada item?
-   Quais ingredientes estão acabando ou vencendo?
-   Quanto perdi em quebras, sobras ou descarte?
-   Quais produtos geram margem e quais precisam de revisão?
-   Quais encomendas precisam ser preparadas e entregues?

## 2. Público inicial

Pequenas padarias independentes, inicialmente com:

-   1 a 5 funcionários;
-   uma unidade/estabelecimento;
-   produção própria de parte relevante do catálogo;
-   vendas no balcão e, em alguns casos, mesas ou encomendas;
-   proprietário envolvido na operação diária;
-   controles feitos em caderno, planilha ou software pouco integrado.

O produto não deve começar tentando atender redes, franquias, indústrias
alimentícias ou operações com múltiplas filiais complexas.

## 3. Problema

A operação de uma padaria combina processos que se afetam mutuamente.
Uma venda consome produto acabado; a produção consome ingredientes;
compras repõem estoque; perdas alteram o custo real; encomendas reservam
capacidade e itens; o caixa precisa refletir recebimentos e pagamentos.

Quando esses controles ficam separados, o proprietário perde
visibilidade sobre estoque, custo, desperdício, caixa e rentabilidade.

## 4. Proposta de valor

**Controlar a rotina da padaria em um só lugar, da receita à venda e do
estoque ao caixa, com uma interface simples para computador e tablet.**

Pilares:

1.  **Operação conectada:** vendas, encomendas, produção e estoque
    compartilham os mesmos dados.
2.  **Custo compreensível:** fichas técnicas calculam custo estimado por
    lote e por unidade.
3.  **Menos desperdício:** perdas e ajustes são registrados e aparecem
    nos relatórios.
4.  **Uso simples:** fluxos curtos, linguagem de padaria e telas
    adequadas a toque.
5.  **Visibilidade diária:** painel com vendas, caixa, produção pendente
    e alertas de estoque.

## 5. Princípios de produto

-   A operação diária deve ser mais rápida que uma planilha ou caderno.
-   Ações importantes devem ter confirmação e histórico.
-   O usuário deve entender o efeito de uma ação no estoque e no caixa.
-   Não ocultar erros de sincronização ou operações pendentes.
-   Não apresentar margem como lucro líquido contábil.
-   Não permitir estoque negativo silenciosamente.
-   O produto deve funcionar bem em telas de desktop e tablet.
-   Recursos fiscais não podem ser simulados como se fossem emissão
    legal de documentos.

## 6. Escopo do MVP comercial inicial

O MVP é um **núcleo operacional de gestão**, não um substituto fiscal
completo. Durante o piloto, o estabelecimento continuará usando seu
emissor fiscal atual, quando aplicável.

### Incluído no MVP

1.  Cadastro e acesso de usuários.
2.  Cadastro de empresa/estabelecimento e configurações.
3.  Cadastro de produtos vendidos, ingredientes e unidades.
4.  Categorias, fornecedores e clientes.
5.  Fichas técnicas/receitas com rendimento e custo estimado.
6.  Estoque de ingredientes e produtos, com histórico de movimentações.
7.  Lotes de estoque e datas de validade em nível básico.
8.  Registro de produção por lote, consumo de ingredientes e entrada de
    produto acabado.
9.  Registro de perdas e ajustes de estoque com motivo.
10. Vendas simples no balcão, com itens por unidade ou peso, descontos
    autorizados e pagamentos.
11. Abertura e fechamento de caixa.
12. Comandas/mesas simplificadas, com lançamento de itens e fechamento
    posterior.
13. Encomendas com cliente, itens, data de retirada e situação.
14. Despesas e contas a pagar simples.
15. Dashboard e relatórios operacionais básicos.
16. Interface responsiva para desktop e tablet.

### Explicitamente fora do MVP

-   Emissão própria de NF-e, NFC-e, SAT ou MFE.
-   Integração direta com SEFAZ.
-   Integração com balanças específicas, impressoras fiscais ou gavetas
    de dinheiro.
-   Conciliação bancária automática.
-   Integração com delivery/marketplaces.
-   Aplicativo nativo Android/iOS.
-   Múltiplas filiais com transferência entre unidades.
-   Folha de pagamento, contabilidade e apuração tributária.
-   Previsão de demanda baseada em IA.
-   Funcionamento offline completo do PDV.

A arquitetura deve permitir evolução para esses recursos sem
implementá-los antecipadamente.

## 7. Fluxos principais

### 7.1 Preparar a padaria

1.  Criar estabelecimento.
2.  Cadastrar categorias e unidades.
3.  Cadastrar ingredientes e produtos.
4.  Cadastrar fornecedores.
5.  Cadastrar receitas e rendimentos.
6.  Informar estoque inicial.
7.  Configurar usuários e permissões.

### 7.2 Produzir

1.  Selecionar receita e quantidade de lotes.
2.  Visualizar consumo previsto e disponibilidade.
3.  Confirmar produção.
4.  Registrar consumo dos ingredientes.
5.  Registrar quantidade efetivamente produzida.
6.  Dar entrada no estoque de produtos acabados.
7.  Registrar perdas, se houver.

### 7.3 Vender no balcão

1.  Abrir sessão de caixa.
2.  Adicionar itens ao pedido.
3.  Informar quantidade ou peso.
4.  Aplicar desconto se permitido.
5.  Selecionar um ou mais meios de pagamento.
6.  Confirmar venda.
7.  Baixar estoque conforme política de produto.
8.  Registrar recebimentos e movimento de caixa.

### 7.4 Atender mesa/comanda

1.  Abrir comanda identificada por mesa ou código.
2.  Adicionar itens ao longo do atendimento.
3.  Consultar subtotal.
4.  Fechar comanda com um ou mais pagamentos.
5.  Converter comanda em venda concluída.

No MVP, não haverá divisão avançada de conta por pessoa, impressão de
produção ou roteamento para cozinha.

### 7.5 Gerenciar encomenda

1.  Selecionar ou cadastrar cliente.
2.  Definir data/hora de retirada.
3.  Adicionar itens e observações.
4.  Registrar sinal/adiantamento, se houver.
5.  Acompanhar status: orçamento, confirmada, em produção, pronta,
    retirada ou cancelada.
6.  Ao concluir retirada, registrar venda/baixa sem duplicar receita ou
    estoque.

## 8. Métricas de sucesso do piloto

Metas internas para validar o produto, não benchmarks de mercado:

-   3 padarias-piloto utilizando o sistema;
-   pelo menos 2 estabelecimentos ativos após 60 dias;
-   proprietário registra vendas e produção semanalmente;
-   pelo menos 80% dos dias de operação do piloto têm fechamento de
    caixa registrado;
-   fichas técnicas usadas para os principais produtos fabricados;
-   ao menos 2 clientes aceitam pagar pelo produto após o piloto.

A métrica principal é **uso recorrente na operação real**, não
quantidade de telas implementadas.

## 9. Modelo de receita --- hipótese

Assinatura mensal por estabelecimento, com limites e recursos por plano.
Hipótese inicial para teste:

-   Essencial: R\$ 99/mês.
-   Gestão: R\$ 149/mês.
-   Completo: R\$ 199/mês.

Não fixar preços em código. Guardar plano, limites e status de
assinatura em configuração/banco. Validar preço por entrevistas, pilotos
e disposição real de pagamento.

## 10. Diferenciação a validar

A diferenciação não deve ser apenas "ter muitos módulos". Hipóteses:

-   implantação guiada com importação assistida de produtos e receitas;
-   foco na rotina de padaria pequena, em vez de ERP genérico;
-   fluxo integrado de receita → produção → estoque → venda;
-   relatórios com explicação em linguagem simples;
-   atendimento próximo no início.

Validar essas hipóteses com proprietários e comparar com os sistemas que
eles já utilizam.

## 11. Limitações e transparência

-   Sem integração fiscal, o Forno não substitui o emissor fiscal
    exigido para o estabelecimento.
-   O custo calculado é estimado a partir dos custos cadastrados e do
    rendimento informado; não é automaticamente o custo contábil
    oficial.
-   Margem estimada não equivale a lucro líquido.
-   Estoque depende de cadastros, inventários e lançamentos corretos.
-   O MVP depende de conexão com a internet; não prometer operação
    offline.
