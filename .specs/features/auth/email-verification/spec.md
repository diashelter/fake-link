# Auth — Verificação de e-mail

**Status:** Approved — 2026-07-27 (Specify)  
**Fatia:** 5 de 7 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** AUTH-12, AUTH-20 … AUTH-25  
**Requirement IDs (fatia):** EV-01 … EV-14  
**Depende de:** [foundation](../foundation/spec.md), [bearer-tokens](../bearer-tokens/spec.md), [registration](../registration/spec.md), [login](../login/spec.md)

## Problem Statement

Usuários em `pending_verification` precisam confirmar posse do e-mail antes de obter sessão completa (`session`). O registro e o login já emitem Bearer `verification` e enfileiram um job stub, mas ainda não existem tokens de e-mail de uso único, envio real via Resend, endpoints de reenvio/consumo nem transição para `active`. Sem esta fatia, contas ficam permanentemente restritas e a jornada do produto (cadastro → verificação → login → dashboard) não se completa na API.

## Goals

- [x] Migration `email_action_tokens` com purpose `email_verification`, hash, TTL 60 min e consumo atômico.
- [x] Envio de verificação via Resend (job na fila `notifications`), substituindo o stub de registro.
- [x] `POST /api/v1/auth/email/verification-notification` → `202` (Bearer `verification`).
- [x] `POST /api/v1/auth/email/verify` → `204` (Bearer `verification` + token de e-mail no body).
- [x] Ativação pós-verify: `status=active`, `email_verified_at` preenchido, revogação do Bearer `verification` **atual**; **sem** emissão de `session` (novo login obrigatório — AUTH-12).
- [x] Rate limits: reenvio 3/h por conta; verify 5/h por conta.
- [x] Feature + integration tests cobrindo TTL, uso único, concorrência, rate limit, privacidade e transição de status.

## Out of Scope

| Item | Motivo |
| --- | --- |
| BFF Next.js, UI, cookies, CSRF | Camada frontend — Fase 1 posterior |
| Página que renderiza o link do e-mail | Frontend; API define URL alvo configurável |
| `GET` com efeito colateral (magic link direto na API) | `docs/security.md` §4.3 — somente POST explícito |
| `GET/PATCH /api/v1/me`, logout | Fatia `session-and-profile` |
| Recuperação/alteração de senha (`password_reset` purpose) | Fatia `password` |
| Revogação de **todos** os Bearers `verification` da conta no verify | AUTH-12 exige revogar o token restrito **apresentado**; demais tokens expiram por TTL/idle |
| Reenvio automático no login | Decisão explícita na fatia `login` — reenvio só via endpoint dedicado |
| Comandos `Operations` (suspend, delete) | Fase 4 |
| Alteração de e-mail | Fora do MVP |
| OpenAPI/client TS regeneration | Infra transversal; spec exige alinhar exemplos/códigos se novos erros estáveis forem introduzidos |

---

## Assumptions & Open Questions

Decisões propostas com base em `docs/*`, fatias Auth concluídas e padrões existentes. Itens marcados **n** aguardam confirmação do mantenedor.

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Formato do token de e-mail | Base64url de 32 bytes CSPRNG (~43 chars), hash SHA-256 hex 64 chars no DB | Paridade com `BearerTokenGenerator` / `Sha256TokenHasher` | y |
| Purpose na tabela | `email_verification` (CHECK + enum); `password_reset` reservado para fatia `password` | `docs/data-model.md` §3; extensibilidade | y |
| TTL do token de e-mail | 60 minutos absolutos (`expires_at = now + 3600s`) | AUTH-21; `docs/api.md` §3.2, `docs/security.md` §4.3 | y |
| Uso único | `used_at` preenchido atomicamente no primeiro consumo válido; segunda tentativa falha | `docs/data-model.md` §3; `docs/testing.md` §6.1 | y |
| Reenvio invalida tokens anteriores | Ao emitir novo token `email_verification`, marcar como consumidos (ou deletar) todos os tokens **não usados** anteriores do mesmo `user_id` + purpose | `docs/security.md` §4.3 — confirmado em revisão 2026-07-27 | y |
| Registro dispara o mesmo pipeline | `QueueEmailVerification` passa a criar `email_action_token` + enfileirar job Resend (substituir stub) | AUTH-20; continuidade com `registration` | y |
| Payload do job | Plaintext do token de e-mail **cifrado** (Laravel `Crypt`/AES-256-GCM) no payload; job decripta somente para montar o e-mail | `docs/data-model.md` §3; `docs/security.md` §13 | y |
| Transporte Resend | Laravel Mail com transport `resend` já configurado em `config/mail.php`; **não** adicionar SDK paralelo | Confirmado em revisão 2026-07-27; sem nova dependência | y |
| Testes determinísticos | `Mail::fake()` / driver `log` ou `array` em `testing`; **sem** chamada HTTP real ao Resend na suite | `docs/testing.md` §6.1; `docs/roadmap.md` Fase 1 | y |
| URL no e-mail | `{APP_URL}/verify-email?token={plaintext}` via `config('auth.email_verification.frontend_base_url')` default `env('APP_URL')` e path `/verify-email` | Confirmado em revisão 2026-07-27; frontend consome query e chama POST verify | y |
| Idioma do e-mail | pt-BR (MVP) | `docs/product.md` §8; UI MVP pt-BR | y |
| Verify exige Bearer `verification` | Middleware `auth.bearer` + `token.kind:verification` | OpenAPI `x-allowed-token-kinds` | y |
| Verify — token de e-mail inválido/expirado/usado | `403` + `code=INVALID_VERIFICATION_TOKEN` + mensagem genérica única (`The verification token is invalid or has expired.`) | Confirmado em revisão 2026-07-27; mensagem uniforme | y |
| Verify — usuário já `active` | `403` + `code=EMAIL_ALREADY_VERIFIED` + mensagem estável | Confirmado em revisão 2026-07-27 | y |
| Resend — usuário já `active` | `403` + `code=EMAIL_ALREADY_VERIFIED` (mesmo código do verify) | Confirmado em revisão 2026-07-27 | y |
| Resend — conta `suspended` / `deletion_pending` | `403` `ACCOUNT_*` via `ValidateAuthToken` / status guard **antes** do use case | Paridade com login; bearer ainda válido até revogação | y |
| Verify — revoga Bearer apresentado | Somente o token cujo hash corresponde ao header `Authorization` é revogado via `RevokeAuthToken` | AUTH-12/24; `docs/api.md` §3.1 “revoga o token restrito” | y |
| Verify — **não** emite `session` | HTTP `204` sem body; cliente deve `POST /login` | AUTH-12; OpenAPI description | y |
| Transição de status | `pending_verification` → `active`; preenche `email_verified_at` UTC; demais campos inalterados | `docs/data-model.md` §3; única transição desta fatia | y |
| Rate limit — dimensão “conta” | Chave HMAC derivada de `user_id` do `AuthenticatedPrincipal` | `docs/security.md` §11; paridade com “escritas privadas Auth” | y |
| Rate limit — o que conta | Todas as tentativas POST na janela (`401`, `403`, `422`, `204`, `202`, etc.) | Consistente com registro/login | y |
| Rate limit — janela | 3600 segundos (1 hora) para reenvio e verify | “3/h” e “5/h” em `docs/api.md` §8 | y |
| Validação HTTP verify | Body `VerifyEmailRequest`: somente `token` (string, minLength 1); `additionalProperties: false` | OpenAPI | y |
| Token plaintext em logs/erros | Proibido em mensagens, logs, traces, failed_jobs | AUTH-25; `docs/security.md` §13 | y |
| URLs com token em logs | Proibido registrar path/query contendo token de e-mail | AUTH-25 | y |
| Falha Resend no job | Retry da fila Laravel; falha permanente não altera status do usuário nem invalida token já emitido | External-dependency failure; registro mantém `201` | y |
| Falha Resend no reenvio HTTP | HTTP `202` mantido se token persistido e job enfileirado; falha pós-enqueue segue retry da fila | Paridade com registro pós-commit | y |
| Concorrência no verify | Transação DB: primeiro consumo vence; demais recebem `403 INVALID_VERIFICATION_TOKEN` | `docs/testing.md` §6.1 consumo concorrente | y |
| Idempotência verify com token já usado | Segunda requisição com mesmo token → `403 INVALID_VERIFICATION_TOKEN` (não `204`) | Uso único | y |

**Open questions:** none — all resolved in review 2026-07-27.

**Dimensões implícitas (Large):**

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | `VerifyEmailRequest` OpenAPI; token minLength 1; sem max explícito além de limite HTTP 64 KiB |
| Failure / partial-failure states | Token inválido → `403`; validação → `422`; bearer inválido → `401`; conta bloqueada → `403 ACCOUNT_*`; rate limit → `429`; Resend down → job retry / `503` só se enqueue impossível |
| Idempotency / retry / duplicate handling | Reenvio gera **novo** token (invalidando anteriores se assunção confirmada); verify não idempotente após sucesso |
| Auth boundaries & rate limits | Endpoints privados Bearer `verification`; throttle 3/h e 5/h por conta |
| Concurrency / ordering | Consumo atômico `used_at`; UNIQUE em `token_hash` |
| Data lifecycle / expiry | Token e-mail 60 min; linhas consumidas permanecem com `used_at` (auditoria mínima) ou deletadas — **preferência: manter row com `used_at`** | y |
| Observability | Sem e-mail, token plaintext ou URL com token em telemetria |
| External-dependency failure | Resend indisponível: job retry; HTTP reenvio `202` se enqueue OK |
| State-transition integrity | Somente `pending_verification` → `active` via verify bem-sucedido; login/registro não alteram para `active` |

---

## User Stories

### P1: Envio de e-mail de verificação (registro e job) ⭐ MVP

**User Story**: Como usuário recém-registrado, quero receber um e-mail com link/token de verificação para confirmar meu endereço.

**Why P1**: AUTH-20; desbloqueia o fluxo iniciado em `registration`.

**Acceptance Criteria**:

1. WHEN `RegisterUser` conclui com sucesso THEN `QueueEmailVerification::dispatch` SHALL criar um registro `email_action_tokens` com `purpose=email_verification`, `expires_at=now+3600s`, `used_at=null` e hash SHA-256 do plaintext gerado.
2. WHEN o job `SendEmailVerificationJob` executa THEN SHALL enviar e-mail via Resend (transport Laravel) contendo URL configurada com o token plaintext **somente** no corpo do e-mail (nunca no assunto de forma reproduzível em logs).
3. WHEN o job executa THEN o plaintext SHALL NOT aparecer em logs, `failed_jobs`, traces ou exceções; payload persistido na fila SHALL estar cifrado.
4. WHEN o envio falha transientemente THEN o job SHALL ser reenfileirado conforme configuração Laravel; falha permanente SHALL NOT alterar `users.status`.

**Independent Test**: Integration com `Mail::fake()` — registro allowlisted → assert token row + `Mail` sent once com destinatário correto (sem assert do token em atributos logados).

**Requirement IDs**: AUTH-20, AUTH-21, EV-01, EV-02, EV-03

---

### P1: Reenvio de verificação ⭐ MVP

**User Story**: Como usuário com Bearer `verification`, quero solicitar novo e-mail de verificação se não recebi o anterior.

**Why P1**: AUTH-23; jornada de conta pendente.

**Acceptance Criteria**:

1. WHEN `POST /api/v1/auth/email/verification-notification` com Bearer `verification` válido e usuário `pending_verification` THEN SHALL responder `202` com envelope `Accepted` (`docs/openapi.yaml`).
2. WHEN reenvio bem-sucedido THEN SHALL criar novo `email_action_token` e enfileirar job de envio exatamente uma vez.
3. WHEN reenvio bem-sucedido e assunção de invalidação confirmada THEN tokens `email_verification` anteriores não usados do mesmo usuário SHALL tornar-se inválidos antes da resposta `202`.
4. WHEN Bearer ausente, inválido, expirado ou kind `session` THEN SHALL responder `401 UNAUTHENTICATED` ou `403 TOKEN_RESTRICTED` conforme middleware existente.
5. WHEN usuário `suspended` ou `deletion_pending` com credencial/bearer válido THEN SHALL responder `403 ACCOUNT_SUSPENDED` ou `403 ACCOUNT_PENDING_DELETION`.
6. WHEN rate limit de reenvio excedido (4ª requisição na hora) THEN SHALL responder `429 RATE_LIMIT_EXCEEDED` com header `Retry-After`.
7. WHEN reenvio THEN contador de rate limit SHALL incrementar antes do use case (conta tentativas com qualquer status HTTP).

**Independent Test**: Feature test — usuário pending + bearer verification → `202`; 4º POST → `429`.

**Requirement IDs**: AUTH-23, EV-04, EV-05, EV-06

---

### P1: Verificação explícita por POST ⭐ MVP

**User Story**: Como usuário com Bearer `verification`, quero confirmar meu e-mail enviando o token recebido para ativar minha conta.

**Why P1**: Núcleo da fatia; AUTH-22, AUTH-24, AUTH-12.

**Acceptance Criteria**:

1. WHEN `POST /api/v1/auth/email/verify` com Bearer `verification` válido e body `{ "token": "<plaintext>" }` correspondente a token não expirado/não usado do mesmo `user_id` THEN SHALL responder `204 No Content`.
2. WHEN verify bem-sucedido THEN `users.status` SHALL tornar-se `active` e `email_verified_at` SHALL ser instante UTC da transação.
3. WHEN verify bem-sucedido THEN o registro `email_action_tokens` SHALL ter `used_at` preenchido atomicamente na mesma transação.
4. WHEN verify bem-sucedido THEN o Bearer `verification` apresentado no header SHALL ser revogado (removido de `auth_tokens`).
5. WHEN verify bem-sucedido THEN o sistema SHALL NOT emitir token `session` nem retornar corpo JSON com token.
6. WHEN token de e-mail expirado, inexistente, já usado, purpose incorreto ou pertencente a outro usuário THEN SHALL responder `403` com `code=INVALID_VERIFICATION_TOKEN` e mensagem `The verification token is invalid or has expired.`
7. WHEN verify é tentado via `GET` ou qualquer método ≠ `POST` THEN SHALL NOT ter efeito colateral (rota inexistente ou `405` global).
8. WHEN rate limit de verify excedido (6ª requisição na hora) THEN SHALL responder `429 RATE_LIMIT_EXCEEDED` + `Retry-After`.
9. WHEN validação falha (body extra, token ausente) THEN SHALL responder `422 VALIDATION_FAILED` sem consumir token de e-mail.

**Independent Test**: Feature — fluxo feliz pending → verify → `204`; assert DB status active; bearer revogado; login subsequente emite `session`.

**Requirement IDs**: AUTH-12, AUTH-22, AUTH-24, EV-07, EV-08, EV-09, EV-10

---

### P1: Privacidade de tokens de e-mail ⭐ MVP

**User Story**: Como plataforma, quero que tokens de verificação não vazem em telemetria ou URLs registradas.

**Why P1**: AUTH-25; requisito de segurança explícito.

**Acceptance Criteria**:

1. WHEN qualquer camada processa token de e-mail THEN plaintext SHALL NOT aparecer em logs, exceptions, traces, métricas ou `failed_jobs`.
2. WHEN URLs de verificação são construídas THEN servidores SHALL NOT registrar query string contendo o token (access logs redigidos ou path-only).
3. WHEN testes usam token sentinela THEN asserts SHALL varrer mensagens de exceção/factory de erro.

**Independent Test**: Unit sentinel — token marcador ausente de `getMessage()` em factories/exceções.

**Requirement IDs**: AUTH-25, EV-11

---

### P2: Contrato HTTP e erros de estado ⭐ MVP hardening

**User Story**: Como cliente da API, quero respostas previsíveis quando a conta já está verificada ou o token de e-mail é inválido.

**Why P2**: Fecha ambiguidades do OpenAPI; evita comportamento divergente entre clientes.

**Acceptance Criteria**:

1. WHEN verify com usuário já `active` THEN SHALL responder `403` com `code=EMAIL_ALREADY_VERIFIED`.
2. WHEN reenvio com usuário já `active` THEN SHALL responder `403` com `code=EMAIL_ALREADY_VERIFIED`.
3. WHEN respostas de erro THEN SHALL incluir `Cache-Control: private, no-store` e `request_id` conforme envelope OpenAPI.

**Independent Test**: Feature — usuário active (factory) + bearer verification forçado → verify/resend → `403 EMAIL_ALREADY_VERIFIED`.

**Requirement IDs**: EV-12

---

### P2: Test discovery e gates

**User Story**: Como mantenedor, quero que testes E2E desta fatia rodem no gate padrão.

**Why P2**: Paridade com `registration`/`login` (BT-12, REG-10, LOG-11).

**Acceptance Criteria**:

1. WHEN `make test-backend` roda THEN testes Feature/Integration desta fatia SHALL ser descobertos via `phpunit.xml`.
2. WHEN gate final da fatia THEN `make lint && make test-backend` SHALL passar sem regressão das fatias anteriores.

**Requirement IDs**: EV-13, EV-14

---

## Edge Cases

- WHEN reenvio ocorre antes da expiração do token anterior THEN apenas o token mais recente SHALL ser válido (se invalidação confirmada).
- WHEN verify concorrente com mesmo token THEN exatamente uma requisição SHALL retornar `204`; demais `403 INVALID_VERIFICATION_TOKEN`.
- WHEN bearer `verification` expira entre reenvio e verify THEN verify SHALL falhar `401` sem consumir token de e-mail.
- WHEN job de registro falha após commit THEN usuário permanece `pending_verification` com bearer válido; reenvio manual recupera fluxo.
- WHEN token de e-mail válido mas bearer de outro usuário THEN SHALL `403 INVALID_VERIFICATION_TOKEN` (não revelar existência cruzada além da mensagem genérica).
- WHEN plaintext token com whitespace THEN validação HTTP SHALL rejeitar ou trim — **default: rejeitar via validação estrita sem trim** (token opaco).
- WHEN Resend retorna 429/5xx THEN job retry; usuário não duplicado.

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| AUTH-12 | P1: Verify POST | Tasks | Approved |
| AUTH-20 | P1: Envio job | Tasks | Approved |
| AUTH-21 | P1: Envio + Verify | Tasks | Approved |
| AUTH-22 | P1: Verify POST | Tasks | Approved |
| AUTH-23 | P1: Reenvio | Tasks | Approved |
| AUTH-24 | P1: Verify POST | Tasks | Approved |
| AUTH-25 | P1: Privacidade | Tasks | Approved |
| EV-01 | P1: Envio job | Tasks | Approved |
| EV-02 | P1: Envio job | Tasks | Approved |
| EV-03 | P1: Envio job | Tasks | Approved |
| EV-04 | P1: Reenvio | Tasks | Approved |
| EV-05 | P1: Reenvio | Tasks | Approved |
| EV-06 | P1: Reenvio | Tasks | Approved |
| EV-07 | P1: Verify POST | Tasks | Approved |
| EV-08 | P1: Verify POST | Tasks | Approved |
| EV-09 | P1: Verify POST | Tasks | Approved |
| EV-10 | P1: Verify POST | Tasks | Approved |
| EV-11 | P1: Privacidade | Tasks | Approved |
| EV-12 | P2: Contrato HTTP | Tasks | Approved |
| EV-13 | P2: Gates | Execute | Pending |
| EV-14 | P2: Gates | Execute | Pending |

**Coverage:** 21 total, 21 mapped ✅

---

## Success Criteria

- [ ] Usuário `pending_verification` recebe e-mail (fake/log em teste), verifica via POST e torna-se `active`.
- [ ] Após verify, Bearer apresentado é inválido e novo login emite `session`.
- [ ] Reenvio respeita 3/h; verify respeita 5/h.
- [ ] Tokens expirados, usados e concorrentes falham conforme ACs.
- [ ] `make test-backend` verde com Feature E2E dos dois endpoints.
- [ ] OpenAPI permanece fonte de verdade; novos códigos de erro documentados se introduzidos.

---

## Decisões confirmadas (revisão 2026-07-27)

| # | Decisão |
| --- | --- |
| 1 | Reenvio invalida tokens `email_verification` anteriores não usados |
| 2 | Token inválido/expirado/usado → `403 INVALID_VERIFICATION_TOKEN` |
| 3 | Conta já `active` → `403 EMAIL_ALREADY_VERIFIED` (verify e reenvio) |
| 4 | URL do e-mail → `{APP_URL}/verify-email?token=…` |
| 5 | Resend via Laravel Mail transport `resend` (sem SDK adicional) |

---

## Referências

| Documento | Uso |
| --- | --- |
| `docs/product.md` §3, §8 | Verificação obrigatória; fluxos de conta |
| `docs/api.md` §3.1–3.2, §8 | TTL, endpoints, rate limits |
| `docs/openapi.yaml` | `verifyEmail`, `resendEmailVerification`, `VerifyEmailRequest` |
| `docs/security.md` §4.3, §11, §13 | POST explícito, TTL, rate limit, telemetria |
| `docs/data-model.md` §3 | `email_action_tokens`, transição de status |
| `docs/testing.md` §6.1, §7 | Casos obrigatórios, Resend failure |
| `.specs/features/auth/README.md` | Catálogo AUTH-12, AUTH-20…25 |
| `.specs/features/auth/registration/spec.md` | Stub `QueueEmailVerification` a substituir |
