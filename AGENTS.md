# Instruções do repositório

- Trabalhe na branch `DEV`. `PROD` é estável: não faça push direto; promova apenas mudanças verificadas e revisadas de `DEV`.
- Use GitHub Issues como tracker. Não mantenha backlog paralelo em arquivos locais.
- Antes de planejar ou alterar arquitetura, consulte o [SAD](docs/architecture/SAD.md), as [regras de negócio](docs/01-regras-de-negocio.md) e os ADRs relevantes.
- O [roadmap existente](docs/14-roadmap.md), o [backlog](docs/11-issues.md) e os [quality gates](docs/12-quality-gates.md) são canônicos. Atualize-os no lugar; não crie cópias de roadmap ou backlog.
- No MVP, não implemente pagamentos, checkout, repasses ou webhooks Asaas/Mercado Pago. A ativação de contas é manual; ver [pagamentos e financeiro](docs/07-pagamentos-e-financeiro.md).
- Para documentação, atualize o documento existente que é dono do assunto e referencie-o nos demais. Crie documento separado apenas quando o conteúdo precisar de ciclo de vida próprio.
- Não implemente telas novas antes de haver mockup e guia funcional aprovados pelo usuário, conforme a skill `architect`.
