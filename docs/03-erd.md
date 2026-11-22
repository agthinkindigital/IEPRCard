# Modelo de dados

O DER conceitual e as regras de identidade ficam no [SAD, seção 4.6](architecture/SAD.md#46-der-alvo-do-produto-schema-do-mvp-definido-no-adr-0005). O catálogo físico versionado (tabelas, colunas, tipos, índices e chaves estrangeiras) é mantido em [`database/schema/`](../database/schema/), conforme o [ADR-0005](adr/0005-schema-canonico-unificado.md).

A suíte [TDD-01](10-tdd.md) verifica o catálogo MVP e a paridade SQLite/MySQL. Este índice substitui os ERDs históricos divergentes; não mantenha cópias do DER ou do dicionário físico aqui.
