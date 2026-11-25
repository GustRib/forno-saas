# Forno --- documentação inicial

Este pacote reúne a especificação inicial do Forno, um ERP SaaS para
pequenas padarias de operação mista.

## Documentos

-   `PRODUCT.md`: visão, público, proposta de valor, escopo e estratégia
    de produto.
-   `REQUIREMENTS.md`: requisitos funcionais e não funcionais, regras de
    negócio e critérios de aceite.
-   `ARCHITECTURE.md`: arquitetura, módulos, decisões técnicas,
    segurança e implantação.
-   `DATABASE.md`: modelo relacional detalhado, entidades,
    relacionamentos, índices e regras de integridade.
-   `BACKLOG.md`: backlog priorizado, épicos, histórias e sequência
    sugerida de implementação.
-   `schema-initial.sql`: DDL inicial de referência para PostgreSQL.
    Deve ser convertido em migrations versionadas antes de uso real.

## Decisões já tomadas

  Tema           Decisão
  -------------- ------------------------------------------------------------
  Segmento       Padarias pequenas de operação mista
  Operação       Balcão, mesas/comandas, encomendas e produção própria
  Fiscal         Integração posterior; arquitetura preparada desde o início
  Dispositivos   Computador e tablet
  Nome           Forno

## Estado do documento

Esta é uma especificação inicial de produto e engenharia, não um
contrato fiscal ou contábil. Regras fiscais, tributárias, contábeis e de
proteção de dados devem ser validadas com profissionais e fornecedores
especializados antes do uso comercial.
