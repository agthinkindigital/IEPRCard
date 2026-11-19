# ADR-0001: Monólito PHP 8.2 sem frameworks

| Campo | Valor |
| --- | --- |
| Status | Aceita (baseline `docs/13`, `docs/14`) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + dono do produto |
| Relacionados | [SAD §3.1](../architecture/SAD.md), ADR-0007, ADR-0012, ADR-0013 |

## Contexto

O produto tem 17 issues, 8 suítes TDD e um único time de engenharia. As operações mais sensíveis (débito de cota + emissão de carteirinha + baixa de pagamento) exigem consistência ACID local. `docs/13` estabelece como regra de ouro: "proibição de dependências pesadas — PHP 8.1+ puro, PDO nativo, GD/Imagick nativos e bibliotecas single-file".

## Decisão

Construir um **monólito modular em PHP 8.2**, sem Laravel/Symfony, com:

- PDO nativo como única camada de acesso a dados (ADR-0002);
- fronteiras de módulo explícitas (`config/`, `includes/motor_*.php`, `api/`, entrypoints);
- injeção explícita de dependências em `config/bootstrap.php`, sem Service Locator;
- bibliotecas *single-file* embutidas em `includes/libs/` para XLS/XLSX, QR Code e PDF;
- extração futura de qualquer módulo apenas via Strangler Fig com ADR específico.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Laravel / Symfony | Auth, migrações, validação e ORM prontos; ecossistema maduro | Viola restrição explícita do produto; peso de deploy; curva de contexto para agentes; abstração esconde SQL dual | Rejeitada |
| Microserviços (render, pagamento, tenant) | Escala independente, fronteiras forçadas | Transações distribuídas para cota/emissão, malha de rede, observabilidade e deploy complexos para 17 issues | Rejeitada agora |
| Serverless (lambdas PHP) | Escala elástica, sem servidor | Extensões GD/Imagick e fontes TTF problemáticas, cold start, custo imprevisível em lotes | Rejeitada |
| Monólito modular (escolhida) | ACID local, deploy simples, zero I/O de rede interno, testável | Escala vertical; risco de acoplamento se fronteiras não forem respeitadas | Aceita |

## Consequências

**Positivas**

- Uma única transação cobre reserva de cota + gravação do lote.
- Agentes executores conseguem ler o sistema inteiro sem rastrear contratos remotos.
- O monólito deve funcionar em hospedagem compartilhada PHP/MySQL e permitir migração para VPS sem alteração de domínio. Custo e limites dependem do plano escolhido; ver ADR-0012.

**Negativas e mitigação**

- Segurança (CSRF, CSP, hashing, upload) precisa ser implementada explicitamente → NFR-20 e specs `*.spec.sec.php`.
- Acoplamento de deploy → ADR-0012 (release atômico e rollback).
- Crescimento sem frameworks tende a reinventar validação → revisar esta ADR se o número de rotas passar de ~30.

## Como validar

- `php -l` em todo o código; nenhum `require` que esconda dependência de negócio.
- Gates 1–5 verdes; nenhuma dependência Composer adicionada sem ADR.
