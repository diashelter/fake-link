# Auth — Fechamento oficial do módulo backend

**Status:** Approved — 2026-08-11 (Specify + Design confirmados)  
**Fatia:** 8 de 8 — ver [índice](../README.md)  
**Requirement IDs (fatia):** ABMC-01 … ABMC-18  
**Depende de:** fatias 1–7 ([foundation](../foundation/spec.md) … [session-and-profile](../session-and-profile/spec.md)) — código e validações Verifier já entregues na `main`  
**Contexto:** análise de gap pós-implementação (2026-08-11)

## Problem Statement

As sete fatias funcionais do módulo Auth (`backend/modules/Auth/`) estão implementadas, testadas e validadas individualmente (Verifier PASS em cada slice). Porém o **critério de saída do módulo** em `.specs/features/auth/README.md` exige, além do código, **OpenAPI sincronizada com automação verificável** e **fechamento formal** de índices e estado do projeto.

Hoje o contrato `docs/openapi.yaml` documenta manualmente os 11 endpoints Auth, mas **não há lint OpenAPI, contract tests nem gate CI** que detectem drift entre spec e implementação. Artefatos de gestão (índice de fatias, goals das specs filhas, `.specs/STATE.md`, `README.md` raiz) permanecem desatualizados em relação ao código mergeado. Gaps menores de teste sinalizados nas validações Verifier das fatias 4–7 também permanecem abertos.

Sem esta fatia de fechamento, o módulo Auth Backend **não pode ser declarado oficialmente concluído** nem servir de base estável para o BFF Auth (`bff-auth/*`), que depende de contrato HTTP confiável.

## Goals

- [x] Gate automatizado de **lint OpenAPI** para `docs/openapi.yaml` (Auth + schemas compartilhados referenciados pelos endpoints Auth).
- [x] **Contract tests** Pest que validem respostas HTTP reais dos 11 endpoints Auth contra o contrato OpenAPI (status, envelope, campos, códigos de erro estáveis).
- [x] Integração dos gates de contrato em **`make lint`** (ou target dedicado invocado por `make lint`) e no workflow **`.github/workflows/backend-quality.yml`**.
- [x] **Fechamento documental**: índice Auth, goals das specs 4–7, `.specs/STATE.md`, `README.md` raiz e critérios de saída do módulo refletindo estado real.
- [x] **Verifier final** do módulo completo com diff range cobrindo esta fatia e confirmação cumulativa dos critérios de saída.
- [x] *(P2)* Fechar gaps menores de teste sinalizados nas validações das fatias login, email-verification, password e session-and-profile.

## Out of Scope

| Item | Motivo |
| --- | --- |
| BFF Next.js, cookies, CSRF, UI Auth | Camada frontend — `.specs/features/bff-auth/` |
| AUTH-39 (interface operacional) e AUTH-40 (revogação por suspensão via Operations) | Fase 4 — módulo Operations |
| Tokens de integração, MFA, cadastro público | Pós-MVP |
| Client TypeScript gerado a partir do OpenAPI | Infra transversal Fase 0; escopo separado (não bloqueia fechamento do módulo Auth API) |
| Contract tests de Links, Redirects, Analytics | Módulos ainda inexistentes |
| Integração real com Resend em produção / DNS de e-mail | Bloqueador de deploy (`docs/roadmap.md`), não de fechamento do módulo |
| Redaction de access logs no Nginx | Infra/ops; sinalizado como spec-precision gap em email-verification — documentar como ops-verified ou adiar |
| Playwright / E2E browser | Escopo BFF Auth |
| Alteração de comportamento funcional dos endpoints Auth | Fatias 1–7 congeladas; esta fatia só adiciona gates, testes de contrato e docs |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Ferramenta de lint OpenAPI | **Spectral** (`@stoplight/spectral-cli`) via CLI em container Node (profile `docs` ou script dedicado), regras mínimas OAS 3.1 + regras do projeto | Padrão de mercado; `docs/openapi.yaml` já é OAS 3.1; alinha `docs/testing.md` §10 item 3 | y |
| Contract tests | Pest Feature em `backend/modules/Auth/Tests/Contract/` com asserts estruturais derivados do OpenAPI (sem pacote PHP novo) | Mantém stack Pest/Docker; evita nova dependência PHP | y |
| Nova dependência npm (Spectral) | **Aprovada** — `@stoplight/spectral-cli` no monorepo | Confirmado pelo usuário 2026-08-11 | y |
| Nova dependência PHP para validação OpenAPI | **Não usar** — abordagem (A) Pest + schemas em memória | Confirmado pelo usuário 2026-08-11 | y |
| Escopo do lint | Arquivo inteiro `docs/openapi.yaml` (inclui paths futuros Links/Analytics como documentação design-first) | Confirmado pelo usuário 2026-08-11 | y |
| Escopo dos contract tests | Somente os **11 endpoints Auth** entregues + schemas/responses referenciados | Critério de saída do módulo Auth | y |
| Gate CI | Adicionar step(s) ao workflow existente `backend-quality.yml` | Paridade local/CI (`docs/testing.md` §10) | y |
| Gaps menores de teste (P2) | **Incluir todos os 4 gaps** nesta fatia; não bloqueiam Verifier final se P1 estiver verde | Confirmado pelo usuário 2026-08-11 | y |
| Branch coverage Auth | Gate existente (`check-auth-coverage-gate.php`) permanece; esta fatia não altera threshold 80/80 | Já verificado nas fatias 1–7 | y |
| Declaração oficial | Módulo Auth Backend marcado **Concluído** no índice somente após Verifier PASS desta fatia | Processo tlc-spec-driven | y |

**Open questions:** none — all resolved 2026-08-11.

**Decisões confirmadas pelo usuário:**

| # | Pergunta | Decisão |
| --- | --- | --- |
| 1 | Lint OpenAPI | Spectral (`@stoplight/spectral-cli`) |
| 2 | Contract tests | (A) Pest Feature + asserts estruturais derivados do OpenAPI |
| 3 | P2 gaps menores | Incluir **todos os 4** itens |
| 4 | Escopo do lint | Arquivo inteiro `docs/openapi.yaml` |
| 5 | Aprovação da SPEC | **Aprovada** — seguir para Design |

---

## User Stories

### P1: Lint OpenAPI automatizado ⭐ MVP

**User Story**: Como mantenedor do contrato API, quero um gate de lint OpenAPI executável via Docker/Make, para detectar erros de sintaxe, referências quebradas e violações de convenção antes do merge.

**Why P1**: Critério explícito de `docs/testing.md` §10 e lacuna principal entre documentação manual e fechamento verificável.

**Acceptance Criteria**:

1. WHEN `make lint-openapi` (ou equivalente documentado) é executado THEN o comando SHALL validar `docs/openapi.yaml` e exit 0 se o arquivo estiver conforme.
2. WHEN `docs/openapi.yaml` contém referência `$ref` quebrada ou violação de regra configurada THEN o lint SHALL exit ≠ 0 com mensagem identificando arquivo e regra.
3. WHEN o workflow `.github/workflows/backend-quality.yml` roda em PR/push para `main` THEN o step de lint OpenAPI SHALL executar o mesmo comando que `make lint-openapi` (paridade local/CI).
4. WHEN `make lint` é invocado THEN SHALL incluir o lint OpenAPI (diretamente ou via dependência explícita documentada no Makefile).

**Independent Test**: Introduzir `$ref` inválido temporário em branch de teste → lint falha → restaurar → lint passa.

**Requirement IDs**: ABMC-01, ABMC-02, ABMC-03, ABMC-04

---

### P1: Contract tests Auth contra OpenAPI ⭐ MVP

**User Story**: Como consumidor da API (BFF ou cliente direto), quero garantia automatizada de que as respostas dos endpoints Auth correspondem ao contrato publicado, para evitar drift silencioso entre implementação e `docs/openapi.yaml`.

**Why P1**: Critério de saída do módulo (`.specs/features/auth/README.md` §Critérios de saída) e gap principal identificado nas validações Verifier (spec-precision: "formal OpenAPI diff not automated").

**Acceptance Criteria**:

1. WHEN a suíte de contract tests Auth roda via `make test-backend` THEN SHALL existir cobertura de contract test para **cada um** dos 11 endpoints Auth implementados:

   | Método | Path |
   | --- | --- |
   | POST | `/api/v1/auth/register` |
   | POST | `/api/v1/auth/login` |
   | POST | `/api/v1/auth/email/verify` |
   | POST | `/api/v1/auth/email/verification-notification` |
   | POST | `/api/v1/auth/password/reset-request` |
   | POST | `/api/v1/auth/password/reset` |
   | POST | `/api/v1/auth/password/change` |
   | POST | `/api/v1/auth/logout` |
   | POST | `/api/v1/auth/logout-all` |
   | GET | `/api/v1/me` |
   | PATCH | `/api/v1/me` |

2. WHEN um contract test de caminho feliz executa THEN SHALL assertar **HTTP status** e **estrutura JSON** (campos obrigatórios, tipos, ausência de campos extras proibidos pelo schema OpenAPI) conforme a response documentada para aquele status.
3. WHEN um contract test de erro documentado executa (ex.: `401 UNAUTHENTICATED`, `403 TOKEN_RESTRICTED`, `422 VALIDATION_FAILED`, `429 RATE_LIMIT_EXCEEDED`) THEN SHALL assertar status HTTP, `code`, `message` e presença de `request_id` conforme exemplos/schemas do OpenAPI.
4. WHEN campos de erro estáveis são validados THEN SHALL incluir no mínimo: `INVALID_CREDENTIALS`, `TOKEN_RESTRICTED`, `REGISTRATION_NOT_ALLOWED`, `INVALID_VERIFICATION_TOKEN`, `EMAIL_ALREADY_VERIFIED`, `PASSWORD_REUSED`, `UNAUTHENTICATED`, `ACCOUNT_SUSPENDED`, `ACCOUNT_PENDING_DELETION`, `VALIDATION_FAILED`, `RATE_LIMIT_EXCEEDED`.
5. WHEN respostas de sucesso Auth incluem headers documentados THEN contract tests SHALL assertar `Cache-Control: private, no-store` e presença de `X-Request-ID` nos caminhos felizes representativos (login, register, GET /me).
6. WHEN `UserResponse` / `AuthResponse` são validados THEN SHALL assertar conjunto exato de chaves em `data` / `data.user` conforme schemas OpenAPI (sem campos sensíveis extras como `password`, `token_hash`).
7. WHEN contract tests rodam THEN SHALL usar PostgreSQL `fake_link_testing` e padrões existentes (factories, RefreshDatabase) — sem mockar a camada HTTP sob teste.

**Independent Test**: Alterar temporariamente um `code` de erro na factory de resposta → contract test falha → restaurar → passa.

**Requirement IDs**: ABMC-05, ABMC-06, ABMC-07, ABMC-08, ABMC-09, ABMC-10

---

### P1: Fechamento documental e índice do módulo ⭐ MVP

**User Story**: Como Tech Lead / próximo agente BFF Auth, quero artefatos de gestão sincronizados com o estado real do código, para saber que o módulo Auth Backend está oficialmente concluído e quais dependências o BFF pode assumir.

**Why P1**: Impede re-trabalho, confusão de status e violação do handoff tlc-spec-driven.

**Acceptance Criteria**:

1. WHEN `.specs/features/auth/README.md` é consultado THEN fatias 1–7 SHALL estar marcadas como **Concluída** e fatia 8 (module-closure) SHALL refletir status atual (Implementing → Verified).
2. WHEN specs filhas 4–7 (`login`, `email-verification`, `password`, `session-and-profile`) são consultadas THEN seções **Goals** SHALL ter checkboxes `[x]` alinhados ao código entregue.
3. WHEN `.specs/STATE.md` Handoff é lido THEN SHALL registrar **Auth Backend concluído** e apontar **próximo passo = BFF Auth** (`bff-auth/session-core` ou conforme índice).
4. WHEN `README.md` raiz descreve estado do projeto THEN SHALL refletir Fase 1 Auth API entregue (não "fase de definição concluída").
5. WHEN esta fatia conclui THEN SHALL existir `.specs/features/auth/module-closure/validation.md` com Verifier PASS, diff range e evidência dos critérios de saída cumulativos.

**Independent Test**: Revisão manual dos quatro artefatos — nenhum aponta fatia Auth funcional como pendente.

**Requirement IDs**: ABMC-11, ABMC-12, ABMC-13, ABMC-14, ABMC-15

---

### P1: Verifier final do módulo Auth Backend ⭐ MVP

**User Story**: Como responsável por qualidade, quero uma validação Verifier independente desta fatia de fechamento, confirmando cumulativamente os critérios de saída do módulo inteiro.

**Why P1**: Processo tlc-spec-driven exige Verifier após Execute; autor ≠ verifier.

**Acceptance Criteria**:

1. WHEN Verifier executa THEN SHALL confirmar cumulativamente:
   - Jornada API completa: register → verify → login → me → logout (evidência Feature/Contract).
   - Enumeração, tokens, TTL, revogação conforme `docs/testing.md` §6.1 (referência às suítes existentes + novos contract tests).
   - OpenAPI lint + contract tests verdes.
   - Cobertura Auth ≥ 80% linhas e métodos (`check-auth-coverage-gate.php`).
2. WHEN discrimination sensor roda THEN SHALL incluir mutações em contract tests ou resposta factory (mínimo 3 mutações comportamentais) e todas SHALL ser killed.
3. WHEN Verifier conclui THEN SHALL escrever `validation.md` com status **Ready** ou lista ranqueada de gaps.

**Independent Test**: Verifier report presente com PASS e sensor 3/3 killed.

**Requirement IDs**: ABMC-16, ABMC-17, ABMC-18

---

### P2: Gaps menores de teste das fatias 4–7

**User Story**: Como mantenedor de qualidade, quero fechar gaps sinalizados nas validações Verifier das fatias Auth, para reduzir risco de regressão silenciosa.

**Why P2**: Nenhum foi bloqueador nas validações originais; melhoria de robustez.

**Acceptance Criteria**:

1. WHEN login falha após credenciais válidas porque `IssueAuthToken` lança exceção THEN SHALL existir teste assertando `500 INTERNAL_ERROR` (ou mapeamento documentado) **sem** token parcial persistido. *(login validation, edge case)*
2. WHEN token de verificação de e-mail é `" "` (whitespace only) THEN SHALL responder `422 VALIDATION_FAILED` sem consumir token. *(email-verification validation, minor edge)*
3. WHEN reset/change falha validação de política ou confirmação THEN testes existentes SHALL permanecer verdes; **adicionar** teste de falha de enqueue após persist de token reset **somente se** houver seam testável in-repo (senão registrar como ops-verified em validation.md). *(password validation, residual note)*
4. WHEN logout-all concorrente é simulado THEN SHALL existir teste assertando zero tokens ao final **ou** documentar explicitamente como out-of-scope com rationale em validation.md. *(session-and-profile validation, optional edge)*

**Independent Test**: Cada AC P2 verificável isoladamente via Pest.

**Requirement IDs**: *(sem IDs ABMC dedicados — rastreados como melhorias vinculadas às fatias originais)*

---

## Edge Cases

- WHEN `docs/openapi.yaml` documenta paths Links/Analytics ainda não implementados THEN lint OpenAPI SHALL passar; contract tests Auth SHALL ignorar paths não entregues.
- WHEN contract test encontra divergência entre OpenAPI e implementação THEN falha SHALL indicar endpoint, status esperado vs. obtido e campo divergente.
- WHEN nova dependência (Spectral ou validador PHP) é necessária THEN agente SHALL perguntar ao usuário antes de instalar (`AGENTS.md`).
- WHEN P2 não for implementado nesta fatia THEN Verifier final SHALL registrar como "deferred" sem bloquear PASS de P1, desde que P1 esteja 100% verde.

---

## Dimensões implícitas (Large)

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Contract tests reutilizam payloads válidos/inválidos das Feature tests existentes; não duplicar matriz completa |
| Failure / partial-failure states | P2 cobre falha pós-validação (IssueAuthToken, enqueue); demais já cobertos nas fatias 1–7 |
| Idempotency / retry / duplicate | N/A — fechamento não altera semântica |
| Auth boundaries & rate limits | Contract tests incluem amostra de 401/403/429 documentados |
| Concurrency / ordering | P2 opcional logout-all concorrente |
| Data lifecycle / expiry | N/A |
| Observability | Contract tests validam `X-Request-ID`; access-log redaction = ops-verified (fora de escopo) |
| External-dependency failure | P2 enqueue Resend — testável somente se seam existir |
| State-transition integrity | N/A — comportamento já testado nas fatias originais |

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| ABMC-01 | P1: Lint OpenAPI | Design | Pending |
| ABMC-02 | P1: Lint OpenAPI | Design | Pending |
| ABMC-03 | P1: Lint OpenAPI | Design | Pending |
| ABMC-04 | P1: Lint OpenAPI | Design | Pending |
| ABMC-05 | P1: Contract tests | Design | Pending |
| ABMC-06 | P1: Contract tests | Design | Pending |
| ABMC-07 | P1: Contract tests | Design | Pending |
| ABMC-08 | P1: Contract tests | Design | Pending |
| ABMC-09 | P1: Contract tests | Design | Pending |
| ABMC-10 | P1: Contract tests | Design | Pending |
| ABMC-11 | P1: Fechamento documental | Execute | Pending |
| ABMC-12 | P1: Fechamento documental | Execute | Pending |
| ABMC-13 | P1: Fechamento documental | Execute | Pending |
| ABMC-14 | P1: Fechamento documental | Execute | Pending |
| ABMC-15 | P1: Fechamento documental | Execute | Pending |
| ABMC-16 | P1: Verifier final | Validate | Pending |
| ABMC-17 | P1: Verifier final | Validate | Pending |
| ABMC-18 | P1: Verifier final | Validate | Pending |

**Coverage:** 18 total, 19 tasks mapped in `tasks.md` ✅

---

## Success Criteria

Módulo Auth Backend **oficialmente concluído** quando:

- [x] `make lint && make test-backend && make test-backend-coverage` verdes incluindo lint OpenAPI e contract tests Auth.
- [x] Workflow CI `backend-quality.yml` inclui lint OpenAPI com paridade local.
- [x] `.specs/features/auth/README.md` marca fatias 1–8 como Concluída/Verified.
- [x] `.specs/features/auth/module-closure/validation.md` com Verifier **Ready** e sensor PASS.
- [x] `.specs/STATE.md` Handoff aponta BFF Auth como próximo passo.
- [x] Nenhum endpoint Auth entregue diverge do contrato OpenAPI sem atualização simultânea de `docs/openapi.yaml` (detectável pelos contract tests).

---

## Referências

| Documento | Uso |
| --- | --- |
| `.specs/features/auth/README.md` §Critérios de saída | Definição de "módulo completo" |
| `docs/openapi.yaml` | Contrato design-first |
| `docs/api.md` §3 | Convenções HTTP Auth |
| `docs/testing.md` §4, §6.1, §10 | Cobertura, casos Auth, CI |
| Validações fatias 1–7 | Evidência baseline + gaps menores |
| `AGENTS.md` | Regra de novas dependências |
| `.specs/features/bff-auth/README.md` | Consumidor downstream |
