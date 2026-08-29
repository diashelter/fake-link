# BFF Auth — Gate E2E de segurança · Tasks

## Execution Protocol (MANDATORY — do not skip)

Implemente estas tasks com a skill `tlc-spec-driven`: **ative-a pelo nome e siga o fluxo Execute + Critical Rules.** Não procure arquivos da skill por path.

**Se a skill não puder ser ativada, PARE e avise — não prossiga sem ela.**

Regras locais do repo que se aplicam a cada commit desta fatia:

- **Sem `Co-authored-by:` e sem trailers de agente de IA** (`AGENTS.md` → "Git e commits"). Author = GitHub do mantenedor apenas.
- **Nunca push para `main`**; trabalhar em branch de feature (`feature/bff-auth-e2e-security-gate`).
- Comandos sempre via Docker/containers, nunca no host.
- Novas dependências (`@playwright/test`, `@axe-core/playwright`) e nova imagem (`axllent/mailpit`) já foram acordadas no discuss — não re-perguntar, mas registrar no commit.

---

**Design:** `.specs/features/bff-auth/e2e-security-gate/design.md`
**Spec:** `.specs/features/bff-auth/e2e-security-gate/spec.md`
**Context:** `.specs/features/bff-auth/e2e-security-gate/context.md`
**Status:** Draft

---

## Test Coverage Matrix

> Gerada de codebase + guidelines + spec — confirmar antes do Execute. Guidelines encontradas: `AGENTS.md` ("inclua testes E2E para caminhos importantes"; "rotas/controllers exigem E2E"), `docs/testing.md` §3.2/§3.4/§4 (cobertura Auth+BFF 80/80; domínios frontend 75/75; `axe` sem violações de impacto relevante), `frontend/vitest.config.ts` (thresholds + globs `include`), `Makefile`, `.github/workflows/backend-quality.yml`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| --- | --- | --- | --- | --- |
| Suíte E2E Playwright (deliverable) | e2e | Toda AC da spec + todo edge case: happy + edge + error/negativo; qualquer vazamento de Bearer ou bypass CSRF falha o gate | `frontend/e2e/*.spec.ts` | `rtk make test-e2e-auth` |
| Config de sessão / helpers TTL (`modules/auth/lib/session/`) — lógica de domínio | unit (Vitest) | Todos os branches; 1:1 com E2E-20; defaults preservados + parse inválido → fail-fast | `frontend/modules/auth/lib/session/*.test.ts` | `rtk $(COMPOSE) run --rm --no-deps frontend pnpm test` |
| `services/bff-session.ts` (fio dos TTLs) — integração de módulo | unit (Vitest) | Suíte `session-core` existente permanece verde; nenhum teste Verified removido/enfraquecido | `frontend/modules/auth/services/bff-session.test.ts` | `rtk $(COMPOSE) run --rm --no-deps frontend pnpm test` |
| Helpers/fixtures E2E (`frontend/e2e/helpers/`) | none | Código de suporte fino (string match, 1 regex, wrappers HTTP/Redis); validado pelas specs que os consomem | `frontend/e2e/helpers/*.ts` | via specs (`rtk make test-e2e-auth`) |
| Compose / Dockerfile / Makefile / workflow CI / allowlist JSON — config | none | Build/static gate: `compose config` válido + `tests/compose/e2e-profile.sh` verde + suíte sobe a stack | `docker-compose*.yml`, `docker/node/Dockerfile`, `Makefile`, `.github/workflows/frontend-e2e.yml`, `backend/config/invite-allowlist.e2e.json` | `rtk make lint-frontend` + `rtk bash tests/compose/e2e-profile.sh` + `rtk docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.e2e.yml --profile e2e config` |
| Docs `.specs` / `README` / `docs/testing.md` | none | markdownlint verde; links válidos | `*.md` | `rtk pnpm --dir frontend exec markdownlint-cli2` (ou gate de docs do repo) |

## Gate Check Commands

> Gerada de codebase — confirmar antes do Execute. `rtk` prefixado em todos (CLAUDE.md).

| Gate Level | When to Use | Command |
| --- | --- | --- |
| **Quick** | Tasks que só tocam TS de `modules/auth/**` (refactor de TTL) | `rtk docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml run --rm --no-deps frontend pnpm test` |
| **Full** | Tasks que criam/alteram specs E2E | `rtk make test-e2e-auth` |
| **Build** | Tasks de config/compose/Dockerfile/CI/docs | `rtk make lint-frontend` + `rtk bash tests/compose/e2e-profile.sh` + `rtk docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.e2e.yml --profile e2e config -q` |

---

## Execution Plan

Fases ordenadas, sequenciais; tasks em ordem dentro da fase.

### Phase 1: Fio de TTL configurável (refactor `session-core`, backward-compatible)

```
T1 → T2 → T3
```

### Phase 2: Infra da composição `e2e`

```
T4 → T5 → T6 → T7 → T8 → T9
```

### Phase 3: Harness Playwright + helpers + smoke de conectividade

```
T10 → T11 → T12 → T13 → T14
```

### Phase 4: Specs de segurança e jornada

```
T15 → T16 → T17 → T18 → T19 → T20 → T21
```

### Phase 5: Wiring de CI + sincronização documental

```
T22 → T23 → T24
```

---

## Task Breakdown

### T1: TTLs de sessão como env em `loadBffSessionConfig()`

**What:** Adicionar 4 env vars de TTL (opcionais, inteiro > 0) e os campos `absoluteTtlSeconds`/`idleTtlSeconds` a `BffSessionConfig`; default = constantes atuais de `ttl.ts`; valor não numérico/≤0 → `Error` fail-fast (padrão dos demais campos).
**Where:** `frontend/modules/auth/lib/session/config.ts` (+ `frontend/modules/auth/lib/session/config.test.ts`)
**Depends on:** None
**Reuses:** `requireEnv`/`decodeBase64Key` patterns em `config.ts`; `SessionKind` de `./types`; `ABSOLUTE_TTL_SECONDS`/`IDLE_TTL_SECONDS` de `./ttl` como fonte dos defaults
**Requirement:** E2E-20

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `BffSessionConfig` expõe `absoluteTtlSeconds` e `idleTtlSeconds` (`Record<SessionKind, number>`)
- [ ] Sem env → valores 604800/86400 (`session`) e 86400/3600 (`verification`)
- [ ] `BFF_SESSION_ABSOLUTE_TTL_SESSION=20` etc. sobrescreve só o campo correspondente
- [ ] `BFF_SESSION_IDLE_TTL_SESSION=abc` (ou `0`, `-1`) → lança `Error` com nome da var
- [ ] `config.test.ts`: casos default / override / inválido para os 4 nomes (≥ 8 asserts novos)
- [ ] Quick gate passa
- [ ] Test count: suíte Vitest do frontend passa integralmente (nenhum teste removido)

**Tests:** unit
**Gate:** quick
**Commit:** `feat(bff-auth): make session TTLs env-configurable`

---

### T2: Helpers de `ttl.ts` aceitam tabelas de TTL

**What:** Dar aos helpers de `ttl.ts` parâmetros opcionais de tabela de TTL com default = constantes do módulo; comportamento inalterado quando o param é omitido.
**Where:** `frontend/modules/auth/lib/session/ttl.ts` (+ `frontend/modules/auth/lib/session/ttl.test.ts`)
**Depends on:** T1
**Reuses:** assinatura e testes existentes de `isAbsoluteExpired`/`isIdleExpired`/`remainingAbsoluteSeconds`
**Requirement:** E2E-20

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `isAbsoluteExpired(r, now, absolute = ABSOLUTE_TTL_SECONDS)`, `isIdleExpired(r, now, idle = IDLE_TTL_SECONDS)`, `remainingAbsoluteSeconds(r, now, absolute = ABSOLUTE_TTL_SECONDS)`
- [ ] Testes existentes de `ttl.test.ts` seguem verdes sem alteração
- [ ] Novos casos: tabela curta custom cruza o limite antes do default (≥ 4 asserts)
- [ ] `shouldTouch`/`TOUCH_THROTTLE_SECONDS` inalterados (fora do escopo)
- [ ] Quick gate passa
- [ ] Test count: Vitest frontend integral verde

**Tests:** unit
**Gate:** quick
**Commit:** `refactor(bff-auth): thread TTL tables through ttl helpers`

---

### T3: `bff-session.ts` usa TTLs do config

**What:** Passar `config.absoluteTtlSeconds`/`config.idleTtlSeconds` de `loadBffSessionConfig()` para os helpers de `ttl.ts` e para o `maxAge` fallback do cookie; nenhuma mudança de comportamento com defaults.
**Where:** `frontend/modules/auth/services/bff-session.ts` (+ `frontend/modules/auth/services/bff-session.test.ts`)
**Depends on:** T2
**Reuses:** `config` já resolvido em `bff-session.ts:54`; call sites linhas 87, 92, 104, 177, 226, 239
**Requirement:** E2E-20, E2E-13, E2E-14

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `isAbsoluteExpired`/`isIdleExpired`/`remainingAbsoluteSeconds` recebem as tabelas do `config`
- [ ] `ABSOLUTE_TTL_SECONDS[input.kind]` / `ABSOLUTE_TTL_SECONDS.session` (linhas 92, 104) trocados por `config.absoluteTtlSeconds[...]`
- [ ] Teste novo: `deps.config` com TTL curto → `getSession` trata registro backdated como idle/absoluto expirado
- [ ] **Toda a suíte `session-core` (`lib/session/**` + `services/bff-session.test.ts`) permanece verde — nenhum teste Verified enfraquecido/removido**
- [ ] Quick gate passa
- [ ] Test count: Vitest frontend integral verde (baseline atual + novos)

**Tests:** unit
**Gate:** quick
**Commit:** `refactor(bff-auth): source session TTLs from config`

---

### T4: Serviço `mailpit` no Compose (profile `e2e`)

**What:** Adicionar o serviço `mailpit` ao `docker-compose.yml` com `profiles: ["e2e"]`, healthcheck oficial e sem volume persistente; pin `MAILPIT_VERSION` em `docker/versions.env`.
**Where:** `docker-compose.yml`, `docker/versions.env`
**Depends on:** None
**Reuses:** padrão de serviço-com-profile de `swagger-ui`/`otel-collector`
**Requirement:** E2E-17

**Tools:**
- MCP: `context7` (`/axllent/mailpit` — flags/env/healthcheck atuais)
- Skill: NONE

**Done when:**
- [ ] `mailpit`: `image: axllent/mailpit:${MAILPIT_VERSION}`, `profiles: ["e2e"]`, env `MP_SMTP_BIND_ADDR=0.0.0.0:1025` / `MP_UI_BIND_ADDR=0.0.0.0:8025` / `MP_MAX_MESSAGES=500`
- [ ] Healthcheck `["CMD","/mailpit","readyz"]` com intervalos
- [ ] Sem `ports:` publicados (paridade profile `test`); sem volume
- [ ] `MAILPIT_VERSION=<tag>` em `docker/versions.env`
- [ ] `rtk docker compose --env-file docker/versions.env -f docker-compose.yml --profile e2e config -q` sai 0
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `build(e2e): add mailpit service under e2e profile`

---

### T5: Estágio `e2e` no `docker/node/Dockerfile`

**What:** Novo estágio `FROM deps AS e2e` que instala Chromium + libs de sistema e fixa `PLAYWRIGHT_BROWSERS_PATH=/ms-playwright`.
**Where:** `docker/node/Dockerfile`
**Depends on:** T6 *(precisa de `@playwright/test` no lockfile para `pnpm exec playwright`)*
**Reuses:** estágio `deps` (que já roda `pnpm install --frozen-lockfile`)
**Requirement:** E2E-17

**Tools:**
- MCP: `context7` (Playwright — `playwright install --with-deps` em Debian slim)
- Skill: NONE

**Done when:**
- [ ] `FROM deps AS e2e` com `ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright NODE_ENV=development`
- [ ] `RUN pnpm exec playwright install --with-deps chromium`
- [ ] `CMD ["pnpm","dev"]` (mesmo runtime do `dev`)
- [ ] Estágios `dev`/`deps`/`build`/`prod` inalterados
- [ ] `rtk docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.e2e.yml --profile e2e build frontend` conclui
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `build(e2e): add playwright e2e image stage`

---

### T6: Dependências e script `test:e2e` no frontend

**What:** Adicionar `@playwright/test` e `@axe-core/playwright` a `devDependencies` e o script `"test:e2e"`; atualizar `pnpm-lock.yaml` dentro do container.
**Where:** `frontend/package.json`, `frontend/pnpm-lock.yaml`
**Depends on:** None
**Reuses:** scripts existentes de `package.json`
**Requirement:** E2E-17, E2E-15

**Tools:**
- MCP: `context7` (versões atuais de `@playwright/test` / `@axe-core/playwright`)
- Skill: NONE

**Done when:**
- [ ] `devDependencies` inclui `@playwright/test` e `@axe-core/playwright` (versões pinadas)
- [ ] `"test:e2e": "playwright test --config e2e/playwright.config.ts"`
- [ ] `pnpm-lock.yaml` regenerado via `rtk $(COMPOSE) run --rm --no-deps frontend pnpm install --lockfile-only` (ou equivalente no container)
- [ ] `rtk make lint-frontend` (typecheck+eslint+prettier) verde
- [ ] Vitest frontend segue verde (sem `frontend/e2e/` ainda)
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `build(e2e): add playwright and axe-core dev dependencies`

---

### T7: Override `docker-compose.e2e.yml`

**What:** Criar o override que liga o profile `e2e`: SMTP/queue/allowlist no backend, target `e2e` + probe + TTLs curtos + env da suíte no frontend, aliases de rede no nginx.
**Where:** `docker-compose.e2e.yml` (raiz)
**Depends on:** T4, T5
**Reuses:** formato de `docker-compose.dev.yml` (AD-008)
**Requirement:** E2E-17, E2E-13, E2E-14

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `backend`: `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, `MAIL_FROM_ADDRESS=no-reply@fake-link.test`, `QUEUE_CONNECTION=sync`, `AUTH_INVITE_ALLOWLIST_PATH=/var/www/config/invite-allowlist.e2e.json`
- [ ] `frontend`: `build.target: e2e`, `BFF_SESSION_PROBE_ENABLED=true`, `BFF_SESSION_ABSOLUTE_TTL_SESSION=20`/`IDLE_TTL_SESSION=8`/`ABSOLUTE_TTL_VERIFICATION=20`/`IDLE_TTL_VERIFICATION=8`, `PLAYWRIGHT_BROWSERS_PATH=/ms-playwright`, `E2E_APP_ORIGIN=https://app.localhost`, `E2E_MAILPIT_URL=http://mailpit:8025`, `E2E_REDIS_URL=redis://redis-ephemeral:6379`
- [ ] `nginx`: `networks.default.aliases: [app.localhost, go.localhost]`
- [ ] `rtk docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.e2e.yml --profile e2e config -q` sai 0 e resolve `mailpit`
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `build(e2e): add docker-compose.e2e.yml override`

---

### T8: Allowlist de convite do `e2e`

**What:** Criar `backend/config/invite-allowlist.e2e.json` com o endereço fixo, no mesmo shape lido por `JsonFileInviteAllowlist`.
**Where:** `backend/config/invite-allowlist.e2e.json`
**Depends on:** None
**Reuses:** `backend/config/invite-allowlist.testing.json`; `JsonFileInviteAllowlist` (confirmar chave — `emails` array)
**Requirement:** E2E-01

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] Arquivo com `["e2e-auth@fake-link.test"]` no formato exato do `.testing.json`
- [ ] Shape confirmado contra `JsonFileInviteAllowlist.php` (parsing não lança)
- [ ] `rtk docker compose ... --profile e2e config` segue válido
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `test(e2e): add dedicated invite allowlist`

---

### T9: Alvo `make test-e2e-auth` + gate estático do profile

**What:** Adicionar a macro `COMPOSE_E2E`, o alvo `test-e2e-auth` (build → up --wait → `migrate:fresh` no `fake_link_testing` → `pnpm test:e2e` → cp artefatos → `down -v` → propaga exit) ao `Makefile` e `.PHONY`; criar `tests/compose/e2e-profile.sh` e incluí-lo em `make test`.
**Where:** `Makefile`, `tests/compose/e2e-profile.sh`
**Depends on:** T7, T8
**Reuses:** macros `COMPOSE`/`COMPOSE_TEST` (`Makefile:1-3`); molde `tests/compose/test-profile.sh`
**Requirement:** E2E-17, E2E-18

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `COMPOSE_E2E := ... -f docker-compose.yml -f docker-compose.e2e.yml --profile e2e` com `COMPOSE_PROJECT_NAME=fake_link_e2e`
- [ ] `test-e2e-auth` roda `migrate:fresh --force --env=testing` (banco `fake_link_testing`, AD-011), executa a suíte, faz `cp` de `frontend:/app/e2e/.artifacts`, `down -v` sempre, e sai com o código da suíte
- [ ] `test-e2e-auth` **não** aparece em `make test`; consta no `.PHONY` e no `help`
- [ ] `tests/compose/e2e-profile.sh`: valida que `--profile e2e config` inclui `mailpit`, não publica portas de `postgres`/`redis-*`, projeto isolado `fake_link_e2e`
- [ ] `e2e-profile.sh` adicionado à sequência de `make test`
- [ ] `rtk bash tests/compose/e2e-profile.sh` sai 0
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `build(e2e): add test-e2e-auth target and profile gate`

---

### T10: `playwright.config.ts`

**What:** Config única do runner: `baseURL` da env, `ignoreHTTPSErrors`, `--host-resolver-rules` para `app.localhost`/`go.localhost` → `nginx`, `workers: 1`, reporters `list`+`html`, `outputDir` em `.artifacts/`, trace/vídeo/screenshot `retain-on-failure`; `.gitignore` para `frontend/e2e/.artifacts/`.
**Where:** `frontend/e2e/playwright.config.ts`, `frontend/.gitignore`
**Depends on:** T6
**Reuses:** `devices['Desktop Chrome']`
**Requirement:** E2E-15, E2E-16, E2E-17

**Tools:**
- MCP: `context7` (Playwright — `defineConfig`, `launchOptions.args`, host-resolver-rules)
- Skill: NONE

**Done when:**
- [ ] `testDir: '.'`, projeto único `chromium`
- [ ] `launchOptions.args` inclui `--host-resolver-rules=MAP app.localhost:443 nginx:443, MAP go.localhost:443 nginx:443`
- [ ] `use.baseURL = process.env.E2E_APP_ORIGIN`, `ignoreHTTPSErrors: true`
- [ ] `workers: 1`, `forbidOnly: !!process.env.CI`, `outputDir: '.artifacts/test-results'`, reporter html em `.artifacts/html`
- [ ] `frontend/.gitignore` ignora `e2e/.artifacts/`
- [ ] `rtk $(COMPOSE_E2E) run --rm --no-deps frontend pnpm exec playwright test --list` lista 0 testes sem erro de config
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `test(e2e): add playwright config`

---

### T11: Helper `mailpit.ts`

**What:** `clearMailbox()`, `waitForMessage(to, {timeoutMs=10000})`, `extractLinkToken(msg)` contra a HTTP API do Mailpit.
**Where:** `frontend/e2e/helpers/mailpit.ts`
**Depends on:** T10
**Reuses:** `fetch` nativo; `E2E_MAILPIT_URL`
**Requirement:** E2E-01, E2E-04

**Tools:**
- MCP: `context7` (`/axllent/mailpit` — `GET /api/v1/search`, `GET /api/v1/message/{id}`, `DELETE /api/v1/messages`)
- Skill: NONE

**Done when:**
- [ ] `clearMailbox()` → `DELETE /api/v1/messages` body `{}`
- [ ] `waitForMessage(to)` faz poll de `GET /api/v1/search?q=to:<to>` até 1 msg ou timeout; timeout lança com contagem atual
- [ ] `extractLinkToken(msg)` aplica `/[?&]token=([A-Za-z0-9._~-]+)/` em `Text || HTML`, retorna o grupo 1; sem match → lança
- [ ] Sem lógica além disso (código de suporte fino)
- [ ] `pnpm exec playwright test --list` segue OK
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `test(e2e): add mailpit helper`

---

### T12: Helper `redis.ts`

**What:** `flushEphemeralRedis()`, `readSessionRecord(sessionId)`, `backdateSessionRecord(sessionId, {createdAtDeltaS, lastActivityDeltaS})` no `redis-ephemeral`.
**Where:** `frontend/e2e/helpers/redis.ts`
**Depends on:** T11
**Reuses:** dep `redis@4.7.1`; `buildRedisSessionKey` de `modules/auth/lib/session/redis-key.ts`; `parseSessionId`; `E2E_REDIS_URL`; chaves BFF de sessão (`BFF_SESSION_HMAC_KEY`)
**Requirement:** E2E-12, E2E-13, E2E-14

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `flushEphemeralRedis()` conecta, `FLUSHDB`, desconecta
- [ ] `readSessionRecord(id)` deriva a chave via `buildRedisSessionKey(parseSessionId(id), hmacKey)` e retorna o JSON decodificado (ou `null`)
- [ ] `backdateSessionRecord(id, {...})` reescreve `createdAt`/`lastActivityAt` recuados N s e re-seta o valor mantendo o TTL restante
- [ ] Reusa a derivação de chave do produto (não reimplementa HMAC)
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `test(e2e): add ephemeral redis helper`

---

### T13: Helpers `sentinel.ts`, `account.ts`, `leak-scan.ts`

**What:** `captureBearerSentinel(request, creds)` (POST direto `/api/v1/auth/login`), `assertAbsent(sentinel, haystacks[])` (string match), `registerViaUi`/`verifyViaMailbox`/`loginViaUi`/`probeCreateSession`, `collectClientState(page)` (cookies/storage/indexedDB/html/rsc), `scanArtifacts(dir, needles[])`.
**Where:** `frontend/e2e/helpers/sentinel.ts`, `frontend/e2e/helpers/account.ts`, `frontend/e2e/helpers/leak-scan.ts`
**Depends on:** T12
**Reuses:** `APIRequestContext`; `page.context().cookies()`; `page.evaluate`; probe `app/api/_test/session`; `mailpit.ts`
**Requirement:** E2E-05, E2E-06, E2E-07, E2E-21

**Tools:**
- MCP: `context7` (Playwright — `APIRequestContext`, `page.evaluate` para IndexedDB dump)
- Skill: NONE

**Done when:**
- [ ] `captureBearerSentinel` retorna o Bearer plaintext do corpo de `POST /api/v1/auth/login`; falha se o corpo não trouxer token
- [ ] `assertAbsent` percorre `haystacks` e faz `expect(h).not.toContain(sentinel)` (sem regex/heurística)
- [ ] `collectClientState` devolve `{cookies, localStorage, sessionStorage, indexedDbNames+entries, html, rscPayload}` para uma page
- [ ] `account.*` cobrem registro→verificação→login pela UI e criação de sessão via probe (`kind: 'session'|'verification'`)
- [ ] `scanArtifacts(dir, needles)` lê arquivos texto de `dir` e retorna ocorrências (usado pelo alvo `make` e por `bearer-absence`)
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `test(e2e): add sentinel, account and leak-scan helpers`

---

### T14: Smoke de conectividade da suíte

**What:** Spec que prova o harness antes das specs de negócio: Chromium abre `https://app.localhost/health`, a API responde `GET /api/v1` (ou `/health` do backend via `go.localhost`), Mailpit e `redis-ephemeral` são alcançáveis dos helpers.
**Where:** `frontend/e2e/_smoke.spec.ts`
**Depends on:** T9, T10, T11, T12, T13
**Reuses:** `mailpit.ts`, `redis.ts`
**Requirement:** E2E-17 (habilita R2/R3 do design)

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `page.goto('/health')` via `app.localhost` retorna 200 (prova `--host-resolver-rules`)
- [ ] `request.get('/api/v1/...')` alcança o Laravel pela rede (prova alias Compose)
- [ ] `clearMailbox()` e `flushEphemeralRedis()` executam sem erro
- [ ] `@playwright/test` resolve dentro do container (prova R3)
- [ ] Full gate (`rtk make test-e2e-auth`) executa o smoke verde
- [ ] Test count: 1 spec, ≥ 4 asserts

**Tests:** e2e
**Gate:** full
**Commit:** `test(e2e): add harness connectivity smoke`

---

### T15: `journey.spec.ts` — jornada de conta

**What:** register → verificação por Mailpit → login (rotação de cookie, destino `/`) → logout → re-login → logout-all com senha; forgot → reset → sessão anterior rejeitada / nova senha autentica.
**Where:** `frontend/e2e/journey.spec.ts`
**Depends on:** T14
**Reuses:** `account.ts`, `mailpit.ts`
**Requirement:** E2E-01, E2E-02, E2E-03, E2E-04, BFFUI-80

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] Cobre E2E-01..04 (AC 1–7 da story P1 "Jornada"): estados `pending_verification`→`active`→`session`, cookie rotacionado, `/settings`→`/login` pós-logout, `204` upstream no logout-all, reset revoga sessão anterior
- [ ] `clearMailbox()` antes de cada envio consumido
- [ ] Esperas por estado (`toHaveURL`, cookie ausente), sem `waitForTimeout` para resultado
- [ ] Full gate passa
- [ ] Test count: ≥ 4 casos (`test()`), todos verdes

**Tests:** e2e
**Gate:** full
**Commit:** `test(e2e): add account journey spec`

---

### T16: `bearer-absence.spec.ts` — superfícies de cliente + respostas BFF + URL

**What:** captura o sentinel via API; assevera ausência em HTML/RSC/`__NEXT_DATA__`, amostras de bundle JS, cookies (só `__Host-fl_session`, `^[A-Za-z0-9_-]{43}$`, ≠ sentinel), `localStorage`/`sessionStorage`/IndexedDB, toda resposta `/api/bff/**` (corpo + headers + `Set-Cookie`) com `Cache-Control: private, no-store`, e barra de URL a cada navegação.
**Where:** `frontend/e2e/bearer-absence.spec.ts`
**Depends on:** T15
**Reuses:** `sentinel.ts`, `leak-scan.ts`, `account.ts`
**Requirement:** E2E-05, E2E-06, E2E-07, BFFUI-80

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `page.on('response')` acumula respostas `/api/bff/` da jornada; assert corpo/headers sem sentinel + `private, no-store`
- [ ] `collectClientState` em ≥ 2 pontos autenticados (`/` e `/settings`); todos os haystacks passam por `assertAbsent`
- [ ] Cookie único, formato validado, ≠ sentinel
- [ ] Nenhuma URL navegada contém `token=`/`bearer=`/sentinel
- [ ] Full gate passa
- [ ] Test count: ≥ 3 casos verdes

**Tests:** e2e
**Gate:** full
**Commit:** `test(e2e): assert bearer absent from browser surfaces`

---

### T17: Varredura de logs e artefatos (E2E-08)

**What:** Estender `make test-e2e-auth` para despejar `docker compose logs frontend` em `frontend/e2e/.artifacts/frontend.log` antes do `down`, e um passo que roda `scanArtifacts(.artifacts, [sentinelFile, cookieValueFile])` falhando o alvo se houver ocorrência; a suíte grava o sentinel/cookie corrente em arquivos efêmeros de `.artifacts/` para o scan.
**Where:** `Makefile` (alvo `test-e2e-auth`), `frontend/e2e/global-teardown.ts` (ou passo no alvo), `frontend/e2e/playwright.config.ts` (registra teardown)
**Depends on:** T16
**Reuses:** `leak-scan.ts::scanArtifacts`; `cp`/`logs` do Compose
**Requirement:** E2E-08, BFFUI-80

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] Alvo despeja logs do `frontend` em `.artifacts/frontend.log` mesmo em falha da suíte
- [ ] Passo de scan roda após a suíte; ocorrência de sentinel ou valor de cookie em qualquer arquivo de `.artifacts/` → exit ≠ 0
- [ ] Trace/vídeo/HAR gerados por uma falha proposital não contêm sentinel/cookie (asserção do scan cobre)
- [ ] `global-teardown` fecha clientes Redis/HTTP abertos
- [ ] Full gate passa (incl. o novo passo)
- [ ] Test count: suíte anterior segue verde; scan reporta 0 ocorrências

**Tests:** e2e
**Gate:** full
**Commit:** `test(e2e): scan logs and artifacts for leaked secrets`

---

### T18: `csrf-origin-returnurl.spec.ts`

**What:** `Origin` ausente e `Origin` divergente em `POST /api/bff/auth/logout-all` → rejeitado, sessão intacta; double-submit CSRF ausente/divergente → rejeitado; mesma mutation pelo formulário oficial → sucesso; `returnUrl` externo (`https://evil`, `//evil`, `/%2f%2fevil`, `/\evil`) → pós-login em caminho interno; `returnUrl=/settings` → `/settings`.
**Where:** `frontend/e2e/csrf-origin-returnurl.spec.ts`
**Depends on:** T17
**Reuses:** `account.ts`; `APIRequestContext` para requests forjados; probe de sessão para checar "intacta"
**Requirement:** E2E-09, E2E-10, E2E-11, BFFUI-81

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] Request forjado sem `Origin` e com `Origin` divergente → status ≥ 400 e `GET /api/bff/auth/me` ainda autentica
- [ ] Cookie CSRF removido / token de corpo divergente → rejeitado, sem efeito
- [ ] Fluxo oficial de logout-all → `204` (controle negativo do falso-positivo)
- [ ] 4 variações de `returnUrl` malicioso → `toHaveURL` interno (default `/`); `returnUrl=/settings` → `/settings`
- [ ] Full gate passa
- [ ] Test count: ≥ 5 casos verdes

**Tests:** e2e
**Gate:** full
**Commit:** `test(e2e): assert csrf, origin and returnUrl enforcement`

---

### T19: `session-lifecycle.spec.ts`

**What:** flush do Redis mid-sessão → deslogado, cookie removido, sem Bearer; idle TTL curto (8 s) sem atividade → expira por inatividade; TTL absoluto curto (20 s) com atividade dentro do throttle → expira por limite absoluto. Usa `backdateSessionRecord` quando o objetivo é só cruzar o limite.
**Where:** `frontend/e2e/session-lifecycle.spec.ts`
**Depends on:** T18
**Reuses:** `redis.ts`, `account.ts`
**Requirement:** E2E-12, E2E-13, E2E-14, BFFUI-82

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] Após `flushEphemeralRedis()` + reload de `/settings` → `toHaveURL(/login/)`, cookie ausente, nenhuma resposta com Bearer
- [ ] Idle: `backdateSessionRecord(lastActivityDeltaS = idleTtl+2)` → próximo request autenticado redireciona a `/login`, cookie limpo
- [ ] Absoluto: `backdateSessionRecord(createdAtDeltaS = absTtl+2)` mantendo `lastActivityAt` recente → tratado como absoluto-expirado
- [ ] Nenhum `waitForTimeout` fixo para o resultado (backdate + espera por estado)
- [ ] Full gate passa
- [ ] Test count: ≥ 3 casos verdes

**Tests:** e2e
**Gate:** full
**Commit:** `test(e2e): assert redis-loss and ttl session expiry`

---

### T20: `a11y.spec.ts`

**What:** `@axe-core/playwright` em `/login`, `/register`, `/verify-email`, `/forgot-password`, `/reset-password`, `/settings` (autenticado) — falha em `serious`/`critical`, reporta `moderate`/`minor`; reflow 360 px sem scroll horizontal do `body`; erro de formulário visível e associado ao campo a 360 px.
**Where:** `frontend/e2e/a11y.spec.ts`
**Depends on:** T19
**Reuses:** `account.ts` (estados autenticados / com token de verificação / com token de reset via Mailpit)
**Requirement:** E2E-15, E2E-16, BFFUI-83

**Tools:**
- MCP: `context7` (`@axe-core/playwright` — `AxeBuilder().analyze()`, filtro por `impact`)
- Skill: NONE

**Done when:**
- [ ] Helper `expectNoSeriousA11y(page)` filtra `violations` por `impact ∈ {serious, critical}` → assert vazio; loga `moderate`/`minor`
- [ ] Roda nas 6 páginas nos estados corretos (verify-email/reset-password precisam de token válido)
- [ ] `page.setViewportSize({width:360,height:800})` → `body.scrollWidth <= clientWidth` nas 6 páginas
- [ ] 1 caso de erro de submit a 360 px com mensagem visível + `aria-describedby`/associação
- [ ] Se surgir violação `serious`/`critical` real → registrar como fix task (não suprimir regra) — ver Risco R7 do design
- [ ] Full gate passa
- [ ] Test count: ≥ 7 casos verdes (6 páginas + reflow/erro)

**Tests:** e2e
**Gate:** full
**Commit:** `test(e2e): axe and 360px reflow on critical auth flows`

---

### T21: `guards.spec.ts`

**What:** sessão `verification` → `/settings` redireciona a `/verify-email`, `GET /api/bff/auth/me` → 200; sessão `session` → `/verify-email` redireciona a `/`; dois browser contexts do mesmo usuário, um faz `logout-all` → o outro aparece deslogado no próximo request.
**Where:** `frontend/e2e/guards.spec.ts`
**Depends on:** T20
**Reuses:** `account.ts::probeCreateSession`; `browser.newContext()`
**Requirement:** E2E-21

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] Matriz `verification`×`session` conforme ACs da story P2
- [ ] Caso concorrente: 2 contexts, `logout-all` em um invalida o outro
- [ ] Full gate passa
- [ ] Test count: ≥ 3 casos verdes

**Tests:** e2e
**Gate:** full
**Commit:** `test(e2e): assert session-kind guards and concurrent logout-all`

---

### T22: Workflow `frontend-e2e.yml`

**What:** Workflow de CI em `pull_request`/`push` para `main` que roda `make test-e2e-auth` e publica os artefatos Playwright em falha.
**Where:** `.github/workflows/frontend-e2e.yml`
**Depends on:** T9, T21
**Reuses:** estrutura de `.github/workflows/backend-quality.yml`
**Requirement:** E2E-19

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `on: pull_request: [main]` + `push: [main]`
- [ ] Steps: checkout → `cp .env.example .env` → `make trust-ca` → `make test-e2e-auth`
- [ ] `actions/upload-artifact` de `frontend/e2e/.artifacts/` com `if: failure()`
- [ ] `docker compose` disponível no runner (`ubuntu-latest`, paridade `backend-quality.yml`)
- [ ] YAML lint verde (`actionlint`/gate de docs se houver)
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `ci(e2e): add frontend-e2e workflow`

---

### T23: Sincronização documental

**What:** Atualizar o índice do pacote e o README/roadmap para refletir a fatia 9 madura e o gate como critério de saída da Fase 1.
**Where:** `.specs/features/bff-auth/README.md`, `README.md`, `docs/testing.md` (§3.3), `docs/roadmap.md` (Fase 1 progresso)
**Depends on:** T22
**Reuses:** frases/tabelas existentes desses arquivos
**Requirement:** E2E-18, E2E-19

**Tools:**
- MCP: NONE
- Skill: NONE

**Done when:**
- [ ] `.specs/features/bff-auth/README.md`: fatia 9 sai de "Seed" para o estado corrente (Spec ✅ · Design ✅ · Tasks ✅ · Execute conforme); Deepen checklist do spec marcado
- [ ] `README.md` "Estado atual" + "Checklist BFF Auth" citam `make test-e2e-auth` e a suíte Playwright entregue
- [ ] `docs/testing.md` §3.3 nota que `make test-e2e-auth` é o gate de saída da Fase 1 Auth+BFF
- [ ] `docs/roadmap.md` Fase 1 "Progresso" atualizado (9/9 fatias)
- [ ] markdownlint verde
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `docs(e2e): mark e2e-security-gate slice delivered`

---

### T24: `AD-018` + Handoff em `STATE.md`

**What:** Registrar a decisão de projeto do gate E2E e atualizar o Handoff.
**Where:** `.specs/STATE.md`
**Depends on:** T23
**Reuses:** formato da tabela `## Decisions` (AD-001…AD-017) e do bloco `## Handoff`
**Requirement:** —  (fecho de processo; rastreado por E2E-17..19)

**Tools:**
- MCP: NONE
- Skill: `tlc-spec-driven` (memory)

**Done when:**
- [ ] Nova linha `AD-018` (2026-08-29): profile `e2e` + `docker-compose.e2e.yml` + `make test-e2e-auth` + `frontend-e2e.yml`; Playwright no container `frontend` (estágio `e2e`); Mailpit para captura de e-mail; TTLs de sessão BFF por env com defaults inalterados
- [ ] `## Handoff` aponta a fatia `bff-auth/e2e-security-gate` como Execute concluído / aguardando Verifier
- [ ] markdownlint verde
- [ ] Build gate passa

**Tests:** none
**Gate:** build
**Commit:** `docs(e2e): record AD-018 and handoff`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5

Phase 1:  T1 → T2 → T3
Phase 2:  T4 → T5 → T6 → T7 → T8 → T9
Phase 3:  T10 → T11 → T12 → T13 → T14
Phase 4:  T15 → T16 → T17 → T18 → T19 → T20 → T21
Phase 5:  T22 → T23 → T24
```

Execução estritamente sequencial. 24 tasks → packing ~7/batch ≈ **4 batches** (P1+parte? não — batches só quebram em fronteira de fase): B1 = P1+P2 (9), B2 = P3 (5), B3 = P4 (7), B4 = P5 (3). B1 excede o budget por 2 — aceitável (fronteira de fase), ou dividir P2 em T4–T6 / T7–T9. Verifier automático após T24.

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1 | 1 arquivo (config.ts) + testes | ✅ |
| T2 | 1 arquivo (ttl.ts) + testes | ✅ |
| T3 | 1 arquivo (bff-session.ts) + testes | ✅ |
| T4 | 1 serviço Compose + 1 pin | ✅ |
| T5 | 1 estágio Dockerfile | ✅ |
| T6 | 1 package.json + lockfile | ✅ |
| T7 | 1 arquivo override | ✅ |
| T8 | 1 arquivo JSON | ✅ |
| T9 | 1 alvo Makefile + 1 script (coeso: "empacotar o gate") | ⚠️ OK (coeso) |
| T10 | 1 config + .gitignore | ✅ |
| T11 | 1 helper | ✅ |
| T12 | 1 helper | ✅ |
| T13 | 3 helpers coesos (sentinel/account/leak-scan) no mesmo diretório | ⚠️ OK (coeso; alternativa: dividir em T13a/b/c) |
| T14 | 1 spec (smoke) | ✅ |
| T15–T21 | 1 spec cada | ✅ |
| T22 | 1 workflow | ✅ |
| T23 | docs (4 arquivos, mesma mudança lógica) | ⚠️ OK (coeso) |
| T24 | 1 arquivo (STATE.md) | ✅ |

---

## Diagram-Definition Cross-Check

| Task | Depends On (body) | Diagram | Status |
| --- | --- | --- | --- |
| T1 | None | (início P1) | ✅ |
| T2 | T1 | T1→T2 | ✅ |
| T3 | T2 | T2→T3 | ✅ |
| T4 | None | (início P2) | ✅ |
| T5 | T6 | T5 após T4 no diagrama, **mas body depende de T6** | ⚠️ ver nota |
| T6 | None | T5→T6 | ⚠️ ver nota |
| T7 | T4, T5 | T6→T7 | ✅ (T4,T5 anteriores na fase) |
| T8 | None | T7→T8 | ✅ |
| T9 | T7, T8 | T8→T9 | ✅ |
| T10 | T6 | (início P3; T6 em P2 anterior) | ✅ |
| T11 | T10 | T10→T11 | ✅ |
| T12 | T11 | T11→T12 | ✅ |
| T13 | T12 | T12→T13 | ✅ |
| T14 | T9, T10, T11, T12, T13 | T13→T14 (+ T9 fase anterior) | ✅ |
| T15 | T14 | T14→T15 | ✅ |
| T16 | T15 | T15→T16 | ✅ |
| T17 | T16 | T16→T17 | ✅ |
| T18 | T17 | T17→T18 | ✅ |
| T19 | T18 | T18→T19 | ✅ |
| T20 | T19 | T19→T20 | ✅ |
| T21 | T20 | T20→T21 | ✅ |
| T22 | T9, T21 | T21→T22 | ✅ |
| T23 | T22 | T22→T23 | ✅ |
| T24 | T23 | T23→T24 | ✅ |

**Nota (T5/T6):** ordem lógica correta é **T6 → T5** (o estágio `e2e` roda `pnpm exec playwright`, que exige `@playwright/test` no lockfile). Ajuste aplicado: na Fase 2 a ordem executa **T4 → T6 → T5 → T7 → T8 → T9**. O diagrama da Fase 2 deve ler `T4 → T6 → T5 → T7 → T8 → T9`. Corrigir o mapa antes do Execute (feito abaixo).

**Fase 2 corrigida:** `T4 → T6 → T5 → T7 → T8 → T9`

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | `modules/auth/lib/session/config.ts` (domínio) | unit | unit | ✅ |
| T2 | `modules/auth/lib/session/ttl.ts` (domínio) | unit | unit | ✅ |
| T3 | `modules/auth/services/bff-session.ts` (integração módulo) | unit | unit | ✅ |
| T4 | `docker-compose.yml` (config) | none | none | ✅ |
| T5 | `docker/node/Dockerfile` (config) | none | none | ✅ |
| T6 | `frontend/package.json` (config) | none | none | ✅ |
| T7 | `docker-compose.e2e.yml` (config) | none | none | ✅ |
| T8 | `backend/config/*.json` (config) | none | none | ✅ |
| T9 | `Makefile` + `tests/compose/*.sh` (config/gate) | none | none | ✅ |
| T10 | `frontend/e2e/playwright.config.ts` (config) | none | none | ✅ |
| T11 | `frontend/e2e/helpers/mailpit.ts` (helper E2E) | none | none | ✅ |
| T12 | `frontend/e2e/helpers/redis.ts` (helper E2E) | none | none | ✅ |
| T13 | `frontend/e2e/helpers/*.ts` (helpers E2E) | none | none | ✅ |
| T14 | `frontend/e2e/_smoke.spec.ts` (suíte E2E) | e2e | e2e | ✅ |
| T15 | `frontend/e2e/journey.spec.ts` | e2e | e2e | ✅ |
| T16 | `frontend/e2e/bearer-absence.spec.ts` | e2e | e2e | ✅ |
| T17 | `frontend/e2e/global-teardown.ts` + `Makefile` (suíte E2E + gate) | e2e | e2e | ✅ |
| T18 | `frontend/e2e/csrf-origin-returnurl.spec.ts` | e2e | e2e | ✅ |
| T19 | `frontend/e2e/session-lifecycle.spec.ts` | e2e | e2e | ✅ |
| T20 | `frontend/e2e/a11y.spec.ts` | e2e | e2e | ✅ |
| T21 | `frontend/e2e/guards.spec.ts` | e2e | e2e | ✅ |
| T22 | `.github/workflows/frontend-e2e.yml` (config) | none | none | ✅ |
| T23 | `*.md` (docs) | none | none | ✅ |
| T24 | `.specs/STATE.md` (docs) | none | none | ✅ |

**Nota sobre `Tests: none` em T11–T13:** os helpers são código de suporte fino (string match, uma regex de extração, wrappers HTTP/Redis que reusam `buildRedisSessionKey` do produto). A matriz classifica "Helpers/fixtures E2E → none", validados pelas specs que os consomem (T14–T21). Não é deferral de teste de produto — nenhuma lógica de domínio vive nesses arquivos. Se, durante o Execute, `extractLinkToken`/`scanArtifacts` acumularem lógica não trivial, promover para um `frontend/e2e/helpers/*.test.ts` executado por Vitest (adicionar o glob ao `vitest.config.ts`).

---

## Perguntas ao mantenedor antes do Execute

1. **Confirmar as novas dependências/imagem** (`AGENTS.md` exige perguntar): `@playwright/test`, `@axe-core/playwright` (devDeps do frontend) e a imagem `axllent/mailpit` (pin em `docker/versions.env`). OK?
2. **MCPs/Skills por task:** proponho `context7` para T4, T5, T6, T10, T11, T13, T20 (APIs Playwright/Mailpit/axe atuais) e nenhuma MCP/skill nas demais; `tlc-spec-driven` (memory) em T24. Concorda?
3. **Abordagem A** (estágio `e2e` no Dockerfile) confirmada para o runtime Playwright?
4. **Sub-agentes:** 24 tasks → ~4 batches. Rodar com batch sub-agents (offer-then-confirm) ou inline?
