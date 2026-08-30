# Links — Fechamento do módulo

**Status:** Seed — 2026-08-29  
**Fatia:** 13 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-120 … LNK-124  
**Depende de:** fatias 1–12

---

## Problem Statement

As fatias anteriores entregam comportamento; esta prova que o módulo está fechado. Reúne a sincronia da OpenAPI, os contract tests, os gates de cobertura, as suítes de concorrência e resiliência que atravessam mais de uma fatia, e a atualização de documentação e estado do projeto.

## Escopo desta fatia

- `docs/openapi.yaml` sincronizada com todos os endpoints entregues, passando no lint Spectral (`make lint-openapi`).
- Contract tests Pest em `modules/{Module}/Tests/Contract/` cobrindo Links e a superfície do host curto.
- Cobertura do escopo Links + Redirects em 90% de linhas e 85% de branches.
- Suítes cruzadas de concorrência (colisão de slug, reserva concorrente, idempotência concorrente, `ETag` concorrente) e de resiliência (Redis e PostgreSQL degradados).
- Varredura de privacidade: nenhum destino, query string, IP ou user-agent bruto em logs, métricas ou traces.
- Atualização de `docs/roadmap.md`, do índice do módulo e do handoff em `.specs/STATE.md`.
- Verifier final independente do autor.

## Fora de escopo

| Item | Motivo |
| --- | --- |
| Benchmark nominal de 120 RPS | Fase 4 (`docs/testing.md` §8) |
| E2E Playwright de links | Depende da UI — pacote `bff-links/` |
| Dashboards e alertas de produção | Fase 4 |
| Client TypeScript gerado | Infra transversal da Fase 0 |

## Entregas previstas

- Spec OpenAPI completa com exemplos executáveis para Links e redirect.
- Suítes de contrato, concorrência, resiliência e privacidade verdes em CI.
- `validation.md` desta fatia com o resultado do Verifier final do módulo.

## Regras-chave a especificar

- Lint OpenAPI via Spectral no monorepo e contract tests por módulo — decisão `AD-016` em `.specs/STATE.md`.
- Cobertura de Links e Redirects: 90% linhas / 85% branches — `docs/roadmap.md` (critérios de saída da Fase 2).
- Casos mínimos das suítes de concorrência e modos de falha — `docs/testing.md` §7.
- Regras de contrato e versionamento — `docs/testing.md` §5, `docs/api.md` §9.
- Redaction e ausência de dado proibido em telemetria — `docs/security.md` §13, `docs/testing.md` §6.7.
- Critérios de saída completos da Fase 2 — `docs/roadmap.md`.

## Open Questions

| Questão | Impacto |
| --- | --- |
| O gate de cobertura de 90/85 é aplicado por módulo ou ao conjunto Links + Redirects? | Configuração do gate e risco de falso verde |
| A OpenAPI é escrita incrementalmente em cada fatia ou consolidada aqui? | Design-first real vs. documentação retroativa |
| As suítes de resiliência rodam em um profile Docker próprio, como o `e2e`? | Necessidade de nova composição e comando `make` |
| A varredura de privacidade reusa o scanner de sentinela da fatia `bff-auth/e2e-security-gate`? | Reuso vs. novo utilitário |

## Referências

| Documento | Uso |
| --- | --- |
| `docs/testing.md` §4, §5, §7, §6.7 | Gates, contrato, concorrência e privacidade |
| `docs/api.md` §7, §9 | Códigos estáveis e versionamento |
| `docs/roadmap.md` | Critérios de saída da Fase 2 |
| `docs/security.md` §13 | Telemetria e logs |
| `.specs/features/auth/module-closure/spec.md` | Precedente de fatia de fechamento |
| `.specs/STATE.md` | Decisões `AD-016` e handoff |
