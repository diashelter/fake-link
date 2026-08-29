# BFF Auth — Gate E2E de segurança

**Status:** Approved — 2026-08-29 (deepened from Seed; gerar Design/Tasks)
**Fatia:** 9 de 9 — ver [índice](../README.md)
**Requirement IDs (catálogo):** BFFUI-80 … BFFUI-83
**Requirement IDs (fatia):** E2E-01 … E2E-21
**Depende de:** [login](../login/spec.md) (Verified), [register](../register/spec.md) (Verified), [email-verification](../email-verification/spec.md) (Verified), [password](../password/spec.md) (Verified), [session-shell](../session-shell/spec.md) (Verified)
**Upstream:** API Laravel Auth (fatias 1–8, Verified) — `/api/v1/auth/*`, `/api/v1/me`

## Problem Statement

As fatias 1–8 do pacote BFF + UI Auth entregaram comportamento provado apenas com Vitest/RTL/MSW — mocks de HTTP, Redis e relógio. Os critérios de saída da Fase 1 (`docs/roadmap.md`, `docs/security.md` §18) exigem prova em **composição real**: um browser oficial percorre a jornada de conta ponta a ponta contra Next + Nginx + Laravel + PostgreSQL + Redis efêmero, o token Bearer nunca alcança o browser, os controles de CSRF/`Origin`/cookie/`returnUrl` bloqueiam de verdade, a perda do Redis e as expirações encerram a sessão sem fallback, e os fluxos Auth críticos passam em acessibilidade WCAG 2.2 AA.

Esta fatia entrega essa suíte Playwright, a composição Docker que a suporta (profile `e2e` + Mailpit), o alvo `make test-e2e-auth` e o workflow de CI que torna o gate um critério de saída verificável. Não adiciona features de produto.

## Goals

- [ ] Suíte Playwright em `frontend/e2e/`, executada dentro do container `frontend`, cobrindo contra o profile Compose `e2e` a jornada convidado → verificado → autenticado → logout / logout-all e o fluxo forgot → reset.
- [ ] Varredura de não exposição do Bearer: um Bearer real do usuário de teste (capturado direto da API Laravel) SHALL estar ausente de HTML, payload RSC, amostras de bundle JS, cookies (exceto o cookie de sessão opaco), `localStorage`/`sessionStorage`/IndexedDB, barra de URL, corpos e headers de toda resposta do BFF, e logs/artefatos do container `frontend`.
- [ ] Enforcement negativo em stack real: `Origin` ausente/forjado, double-submit CSRF ausente/divergente e `returnUrl` externo/ambíguo SHALL bloquear a mutation ou cair para caminho interno seguro, sem mudança de estado.
- [ ] Ciclo de vida da sessão: flush do Redis efêmero mid-sessão, expiração idle e expiração absoluta (via TTLs curtos configuráveis no profile `e2e`) SHALL resultar em usuário deslogado, cookie removido e ausência de fallback de token.
- [ ] `axe` (via `@axe-core/playwright`) sem violações de impacto `serious`/`critical` em login, register, verify-email, forgot-password, reset-password e `/settings`; smoke de reflow a 360 px sem perda de conteúdo nem scroll horizontal.
- [ ] TTLs de sessão (`ABSOLUTE_TTL_SECONDS`, `IDLE_TTL_SECONDS`) tornam-se configuráveis por env com **defaults idênticos aos atuais**; apenas o profile `e2e` aplica valores curtos.
- [ ] `make test-e2e-auth` sobe o profile `e2e`, roda a suíte, derruba a composição e propaga o exit code; `.github/workflows/frontend-e2e.yml` executa esse alvo em PR e push para `main`.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Novas features de produto | Fatia é verificação agregada; consome as fatias 1–8 |
| Matriz BrowserStack (Chrome/Edge/Firefox/Safari, desktop + iOS) | Pré-release (`docs/testing.md` §3.3); esta fatia roda **só Chromium** como gate local/CI |
| Snapshots visuais extensos / regressão de pixels | `docs/testing.md` §3.3 restringe a estados críticos estáveis; smoke de reflow 360 px é o único gate visual aqui |
| E2E de Links, Redirect, Analytics, Operations | Fase 2+ |
| Rate limiting Auth em E2E | Já coberto por Feature tests Laravel (`docs/testing.md` §6.1); em browser seria não determinístico por timing |
| Logout com Laravel indisponível (best-effort remoto) | Já coberto por Vitest em `session-shell` (SH-03); reproduzir queda do upstream em Compose é ops-verified (ver L-026) |
| OpenTelemetry export / alertas Grafana da suíte | Fase 4; o gate só assevera ausência de segredos nos sinks locais (logs do container) |
| Real SMTP / Resend / DNS de e-mail | `e2e` usa Mailpit em-composição; envio real é bloqueador de deploy (`docs/roadmap.md`), não desta fatia |
| Proxy genérico / TTL dinâmico por request | Proibido (`docs/security.md` §5.3); TTL curto é config estática do profile |
| Reabertura da spec `session-core` | O refactor de TTL para env é mínimo e backward-compatible, executado **dentro** desta fatia |

---

## Assumptions & Open Questions

Toda ambiguidade do Seed foi resolvida com o mantenedor em 2026-08-29 (linhas `Confirmed = y`). Itens `Confirmed = n` são defaults do agente a fechar no Design, sem bloquear a aprovação da spec.

| Assumption / decisão | Default escolhido | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Ambiente de execução | Novo profile Compose `e2e` = serviços base (`nginx`, `frontend`, `backend`, `postgres`, `redis-ephemeral`, workers) + `mailpit` | Paridade prod-like com HTTPS via Nginx; isolado dos profiles `test`/`dev` | y |
| Runner da suíte | Roda **dentro do container `frontend`**; `@playwright/test` + Chromium em `frontend/package.json` devDeps; libs de browser instaladas na imagem `docker/node` | Decisão do mantenedor; sem serviço Compose novo | y |
| Home da suíte | `frontend/e2e/` (specs, fixtures, `playwright.config.ts`) | Coeso com o módulo frontend; fora de `frontend/modules/**` (não conta na cobertura Vitest) | y |
| Browsers do gate | Somente Chromium | Firefox/WebKit + matriz completa são pré-release BrowserStack (out of scope) | y |
| Resolução do host | `frontend` alcança `https://app.localhost` / `https://go.localhost` via alias de rede no serviço `nginx` (fallback: `extra_hosts`) sob o profile `e2e` | Nome canônico `__Host-` exige HTTPS + host real; Design escolhe alias vs extra_hosts | n — Design |
| CA de desenvolvimento | Playwright com `ignoreHTTPSErrors: true` | Paridade com smokes CI (`curl -k`); a CA dev não é confiável dentro do container | y |
| Obtenção do token de verificação | Serviço `mailpit` no profile `e2e`; `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`; a suíte lê a mensagem pela HTTP API do Mailpit (`http://mailpit:8025/api/v1/*`) e extrai o link `?token=` | Fiel ao fluxo real (job → Mailable → SMTP); determinístico; sem parse de log nem rota test-only | y |
| Escopo do Mailpit | Apenas profile `e2e`; `dev` continua `MAIL_MAILER=log`; `testing` continua `array` | Não altera ambientes existentes | y |
| Limpeza de mailbox | `global setup` e cada teste que consome e-mail limpam o mailbox Mailpit antes de disparar o envio | Isola mensagens entre casos | y |
| Estratégia de relógio / TTL | Extrair `ABSOLUTE_TTL_SECONDS` e `IDLE_TTL_SECONDS` (`frontend/modules/auth/lib/session/ttl.ts`) para env, lidas via `loadBffSessionConfig()`; defaults preservam 604800/86400 (session) e 86400/3600 (verification); profile `e2e` aplica valores curtos | Check de expiração é server-side no Route Handler — clock de browser não afeta; env curto permite `await` real | y |
| Valores `e2e` dos TTLs | `session`: absoluto 20 s / idle 8 s; `verification`: absoluto 20 s / idle 8 s (Design ajusta se flake) | Longo o bastante para 1–2 requests, curto o bastante para o teste aguardar | n — Design |
| Flush do Redis | A suíte executa `FLUSHDB` no `redis-ephemeral` via cliente Redis já presente em `frontend` (`redis` npm) apontando para `redis-ephemeral:6379` | Reproduz "perda/flush do Redis" de `docs/security.md` §5.2 no stack Auth-only sem efeito colateral | y |
| Sentinel de Bearer | A suíte faz `POST https://app.localhost/api/v1/auth/login` **direto na API** para o usuário de teste e captura o Bearer plaintext da resposta; usa essa string exata como sentinel nas asserções de ausência | `/api/v1` é público (`docs/security.md` §6); Bearer tem shape base64url-43 idêntico ao session id, então varredura por formato não discrimina — precisa de string exata | y |
| Conta de teste | Allowlist dedicada `backend/config/invite-allowlist.e2e.json` (via `AUTH_INVITE_ALLOWLIST_PATH`) com um endereço fixo `e2e-auth@fake-link.test`; `make test-e2e-auth` roda `php artisan migrate:fresh` antes da suíte para determinismo | Allowlist exige match exato sem aliases (`docs/testing.md` §6.1) → endereço fixo + DB limpo por run | y |
| Banco do `e2e` | Reusa `fake_link_testing` (nunca `fake_link` nem produção), recriado com `migrate:fresh` no início do alvo | AD-011; evita mais um banco | n — Design |
| Escopo do `axe` | login, register, verify-email, forgot-password, reset-password, `/settings` | WCAG 2.2 AA crítico (Seed confirmou) | y |
| Severidade `axe` bloqueante | Falha o gate em `serious` + `critical`; `moderate`/`minor` são reportados sem falhar | "impacto relevante conhecido" (`docs/testing.md` §3.4) | y |
| Falha bloqueante do gate | Qualquer vazamento de Bearer **ou** bypass de CSRF/`Origin` falha o gate incondicionalmente | Critério de saída Fase 1 (Seed confirmou) | y |
| Wiring de CI | Novo `.github/workflows/frontend-e2e.yml` em `pull_request` + `push` para `main`, rodando `make test-e2e-auth` | Fecha o critério de saída Fase 1 no CI (hoje só existe `backend-quality.yml`) | y |
| Nome do alvo Makefile | `make test-e2e-auth` (adicionado ao `.PHONY`); **não** entra em `make test` (mantém os gates rápidos separados do E2E lento) | Seed sugeriu o nome; separação de custo | y |
| Artefatos Playwright | `frontend/e2e/.artifacts/` (trace/vídeo/screenshot on-failure), git-ignored; upload como artifact de CI | Depuração sem poluir o repo | n — Design |
| Redação de artefatos | Trace/HAR/vídeo do Playwright NÃO podem conter o cookie de sessão nem o Bearer sentinel em claro; a suíte configura `contextOptions`/masking e um assert de varredura sobre os artefatos gerados | `docs/security.md` §13; artefatos de CI são um sink de telemetria | y |

**Open questions:** none — tudo resolvido acima ou registrado como default a fechar no Design.

---

## Implicit-Requirement Dimensions (sweep completo — escopo Large)

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Coberto indiretamente: `returnUrl` malicioso (E2E-11) exercita o parser de caminho seguro em stack real. Demais validações de input são das fatias 4–8 — **N/A because** consumidas, não re-testadas campo a campo. |
| Failure / partial-failure | Flush do Redis mid-sessão (E2E-12) prova logout seguro sem fallback. Queda do Laravel no logout best-effort → **N/A because** já coberto por Vitest `session-shell` SH-03 e é ops-verified (L-026: comportamento de infra fora do app; sem seam novo aqui). |
| Idempotency / retry / duplicate | **N/A because** a fatia não expõe endpoint mutável novo; cada run é idempotente por `migrate:fresh` + endereço fixo; re-executar a suíte não acumula estado. |
| Auth boundaries & rate limits | Matriz `session` vs `verification`: guard smoke (E2E-21) — kind `verification` barrado de `/settings`, kind `session` redirecionado de `/verify-email`. Rate limits → **N/A because** Feature-tested no Laravel (`docs/testing.md` §6.1); em browser seriam flaky por timing. |
| Concurrency / ordering | `logout-all` invalida a sessão de um segundo contexto de browser paralelo (E2E-18, P2). Sem outras garantias de ordenação nesta camada. |
| Data lifecycle / expiry | Idle TTL (E2E-13), absoluto TTL (E2E-14) com TTLs curtos do profile `e2e`; flush do Redis (E2E-12). |
| Observability | Bearer/cookie ausentes de logs do container `frontend` e de artefatos Playwright (E2E-08); asserção de redação sobre trace/HAR/vídeo. |
| External-dependency failure | **N/A because** a única dependência "externa" é o Mailpit, em-composição e determinístico; Resend/SMTP real e rede externa estão fora de escopo. |
| State-transition integrity | Jornada `pending_verification` → `active` → sessão `session` (E2E-01); reset de senha revoga sessões vigentes (E2E-04). |

---

## User Stories

### P1: Jornada Auth ponta a ponta em composição real ⭐ MVP

**User Story:** Como mantenedor do produto, quero uma suíte Playwright que percorra a jornada de conta contra a stack Docker real, para provar que Next + Nginx + Laravel + PostgreSQL + Redis efêmero + e-mail operam juntos como especificado.

**Why P1:** BFFUI-80; critério de saída Fase 1 (`docs/roadmap.md`, `docs/security.md` §18). Sem stack real, as fatias 1–8 provam só unidades mockadas.

**Acceptance Criteria:**

1. WHEN o profile `e2e` sobe THEN `nginx`, `frontend`, `backend`, `postgres`, `redis-ephemeral` e `mailpit` SHALL reportar `healthy` antes de a suíte iniciar.
2. WHEN a suíte registra `e2e-auth@fake-link.test` em `/register`, aceita os Termos e submete THEN a resposta SHALL criar sessão `verification` (cookie `__Host-fl_session` presente) e o Mailpit SHALL receber exatamente uma mensagem de verificação para esse endereço em até 10 s.
3. WHEN a suíte extrai o link `?token=` da mensagem Mailpit, abre `/verify-email` e aciona a verificação explícita (`POST`) THEN a conta SHALL passar a `active` e a UI SHALL exigir novo login.
4. WHEN a suíte faz login em `/login` com as credenciais do usuário verificado THEN SHALL obter sessão `kind: session`, o ID do cookie SHALL diferir do ID pré-login (rotação) e a navegação SHALL chegar ao destino pós-login (`/`).
5. WHEN a suíte aciona "Sair" (logout) THEN o cookie SHALL ser removido e uma navegação subsequente a `/settings` SHALL redirecionar para `/login`.
6. WHEN a suíte re-loga, abre `/settings`, informa a senha correta e submete "encerrar todas as sessões" (`logout-all`) THEN a resposta upstream SHALL ser `204` e a sessão BFF local SHALL ser encerrada (cookie removido).
7. WHEN a suíte executa forgot-password (`/forgot-password`), extrai o token de reset do Mailpit, conclui em `/reset-password` com nova senha válida e tenta usar uma sessão anterior à troca THEN a sessão anterior SHALL ser rejeitada e a nova senha SHALL autenticar.

**Independent Test:** `make test-e2e-auth` — o arquivo `frontend/e2e/journey.spec.ts` passa contra o profile `e2e`; falha se qualquer passo da jornada quebrar.

**Requirement IDs:** BFFUI-80, E2E-01, E2E-02, E2E-03, E2E-04

---

### P1: Bearer nunca alcança o browser ⭐ MVP

**User Story:** Como responsável de segurança, quero prova em browser real de que nenhum Bearer emitido pelo Laravel aparece em qualquer superfície acessível ao cliente ou nos logs/artefatos.

**Why P1:** BFFUI-80; `docs/security.md` §5.1, §18 ("teste de CSRF, cookie, expiração e perda do Redis"); Seed marca vazamento de Bearer como falha incondicional do gate.

**Acceptance Criteria:**

1. WHEN a suíte captura um Bearer plaintext real via `POST /api/v1/auth/login` para o usuário de teste THEN esse valor exato (`sentinel`) SHALL ser usado em todas as asserções de ausência a seguir.
2. WHEN qualquer página autenticada é carregada THEN o `sentinel` SHALL estar ausente do HTML servido, do payload RSC/`__NEXT_DATA__` e de uma amostra de cada resposta de bundle JS de mesma origem.
3. WHEN o estado do browser é inspecionado em cada etapa autenticada THEN os cookies de `app.localhost` SHALL conter somente `__Host-fl_session`, seu valor SHALL casar `^[A-Za-z0-9_-]{43}$` e SHALL diferir do `sentinel`; `localStorage`, `sessionStorage` e todos os bancos IndexedDB SHALL estar vazios de qualquer valor contendo o `sentinel`.
4. WHEN todas as respostas de `/api/bff/**` observadas durante a jornada são inspecionadas THEN nenhum corpo nem header (incluindo `Set-Cookie`) SHALL conter o `sentinel`, e todas SHALL trazer `Cache-Control: private, no-store`.
5. WHEN a barra de URL é lida em cada navegação THEN nenhuma SHALL conter o `sentinel` nem um parâmetro `token`/`bearer`.
6. WHEN o stdout/stderr do container `frontend` do run é varrido THEN o `sentinel` e o valor do cookie de sessão SHALL estar ausentes.
7. WHEN a suíte termina THEN os artefatos gerados (trace, HAR se houver, vídeo, screenshots) SHALL estar livres do `sentinel` e do valor do cookie de sessão em claro.

**Independent Test:** `frontend/e2e/bearer-absence.spec.ts` — coleta via listeners de rede + `context.cookies()` + `page.evaluate` de storage + leitura de logs do container; qualquer ocorrência do `sentinel` falha.

**Requirement IDs:** BFFUI-80, E2E-05, E2E-06, E2E-07, E2E-08

---

### P1: CSRF, Origin e returnUrl bloqueiam em stack real ⭐ MVP

**User Story:** Como responsável de segurança, quero que os controles anti-CSRF e o `returnUrl` seguro sejam provados contra o Route Handler real, não contra mocks.

**Why P1:** BFFUI-81; `docs/security.md` §5.3; Seed marca bypass de CSRF como falha incondicional do gate.

**Acceptance Criteria:**

1. WHEN a suíte envia `POST /api/bff/auth/logout-all` com cookie de sessão válido mas **sem** header `Origin` THEN a resposta SHALL ser rejeitada (status ≥ 400, não `204`) e a sessão SHALL permanecer válida.
2. WHEN o mesmo request é enviado com `Origin` divergente do App host HTTPS THEN SHALL ser rejeitado e a sessão SHALL permanecer válida.
3. WHEN uma mutation é enviada sem o cookie CSRF **ou** com token de corpo divergente do cookie (double-submit quebrado) THEN SHALL ser rejeitada sem efeito de estado.
4. WHEN o browser executa a mesma mutation pelo formulário oficial (Origin e double-submit corretos) THEN SHALL ter sucesso — provando que a rejeição acima não é falso-positivo.
5. WHEN a suíte acessa `/login?returnUrl=https://evil.example` (e variações: `//evil.example`, `/%2f%2fevil.example`, `/\evil.example`) e completa o login THEN a navegação pós-login SHALL chegar a um caminho interno seguro (default `/`) e NUNCA a origem externa.
6. WHEN a suíte acessa `/login?returnUrl=/settings` e completa o login THEN a navegação pós-login SHALL chegar a `/settings`.

**Independent Test:** `frontend/e2e/csrf-origin-returnurl.spec.ts` — usa `request` context do Playwright para os requests forjados e `page` para os fluxos oficiais; compara estado de sessão antes/depois via `/settings` acessível ou não.

**Requirement IDs:** BFFUI-81, E2E-09, E2E-10, E2E-11

---

### P1: Ciclo de vida da sessão — flush do Redis, idle e absoluto ⭐ MVP

**User Story:** Como responsável de segurança, quero prova de que a sessão termina de forma segura quando o Redis efêmero é perdido e quando os limites de inatividade e absoluto são atingidos, sem qualquer fallback de token para o browser.

**Why P1:** BFFUI-82; `docs/security.md` §5.2, §18.

**Acceptance Criteria:**

1. WHEN a suíte autentica, confirma acesso a `/settings`, executa `FLUSHDB` no `redis-ephemeral` e recarrega `/settings` THEN o usuário SHALL aparecer deslogado, o cookie de sessão SHALL ser removido e nenhuma resposta SHALL entregar um Bearer ao browser.
2. WHEN a sessão está sob o profile `e2e` (idle TTL curto) e a suíte aguarda além do idle TTL sem atividade e então faz um request autenticado THEN a sessão SHALL ser tratada como expirada por inatividade (redirect para `/login`, cookie removido).
3. WHEN a suíte mantém atividade dentro do throttle e aguarda além do TTL absoluto curto THEN a sessão SHALL ser tratada como expirada por limite absoluto, independentemente de atividade recente.
4. WHEN os TTLs rodam com os **defaults** (sem override do profile `e2e`) THEN `loadBffSessionConfig()` SHALL retornar 604800/86400 para `session` e 86400/3600 para `verification` — sem regressão para dev/prod.

**Independent Test:** `frontend/e2e/session-lifecycle.spec.ts` (flush + idle + absoluto) e um teste de unidade em `frontend/modules/auth/lib/session/` para o parsing/defaults dos TTLs por env.

**Requirement IDs:** BFFUI-82, E2E-12, E2E-13, E2E-14, E2E-20

---

### P1: Acessibilidade e reflow dos fluxos Auth críticos ⭐ MVP

**User Story:** Como usuário com tecnologia assistiva, quero que os fluxos de conta não tenham barreiras de acessibilidade de impacto relevante e funcionem a 360 px.

**Why P1:** BFFUI-83; `docs/testing.md` §3.4 ("falha de acessibilidade em fluxo crítico bloqueia release").

**Acceptance Criteria:**

1. WHEN `axe` roda em `/login`, `/register`, `/verify-email`, `/forgot-password`, `/reset-password` e `/settings` (estado autenticado) THEN SHALL NOT haver violações de impacto `serious` ou `critical`.
2. WHEN violações `moderate`/`minor` existirem THEN SHALL ser reportadas no output da suíte sem falhar o gate.
3. WHEN cada página crítica é renderizada em viewport de 360 px THEN SHALL NOT haver scroll horizontal do `body` nem conteúdo/controle recortado.
4. WHEN um formulário Auth é submetido com erro em 360 px THEN a mensagem de erro SHALL ficar visível e associada ao campo.

**Independent Test:** `frontend/e2e/a11y.spec.ts` — `@axe-core/playwright` por página; assert de `scrollWidth <= clientWidth` no `body` a 360 px.

**Requirement IDs:** BFFUI-83, E2E-15, E2E-16

---

### P1: Composição `e2e`, alvo e gate de CI ⭐ MVP

**User Story:** Como mantenedor, quero um único comando e um check de CI que executem toda a suíte contra a stack real e falhem o pipeline em qualquer regressão.

**Why P1:** Critério de saída Fase 1 exige o gate verde e verificável (`docs/roadmap.md`).

**Acceptance Criteria:**

1. WHEN `docker compose --profile e2e up -d --wait` roda THEN a stack SHALL incluir `mailpit` (SMTP `1025`, HTTP API `8025`) e os serviços base, todos `healthy`, com `MAIL_MAILER=smtp` apenas no `frontend`/`backend` desse profile.
2. WHEN `make test-e2e-auth` roda THEN SHALL: recriar `fake_link_testing` com `migrate:fresh`, subir o profile `e2e`, executar `pnpm test:e2e` dentro do container `frontend`, derrubar a composição e sair com o código da suíte.
3. WHEN qualquer spec da suíte falha THEN `make test-e2e-auth` SHALL sair com código ≠ 0.
4. WHEN `make test-e2e-auth` termina THEN nenhum container ou volume do projeto `e2e` SHALL permanecer em execução (teardown mesmo em falha).
5. WHEN `.github/workflows/frontend-e2e.yml` dispara em `pull_request` ou `push` para `main` THEN SHALL executar `make test-e2e-auth` e publicar os artefatos Playwright em caso de falha.
6. WHEN os defaults de dev/prod são exercitados THEN `make up`, `make test` e `make test-frontend` SHALL permanecer inalterados (o E2E não entra em `make test`).

**Independent Test:** rodar `make test-e2e-auth` localmente (exit 0) e um push de branch acionando `frontend-e2e.yml` verde; introduzir uma falha proposital e ver o exit ≠ 0 e o artifact.

**Requirement IDs:** E2E-17, E2E-18, E2E-19

---

### P2: Guards de kind e concorrência de logout-all

**User Story:** Como responsável de segurança, quero um smoke em browser da matriz `session` vs `verification` e da revogação concorrente.

**Why P2:** Reforço em stack real de comportamento já Vitest-coberto em `session-shell` (SH-19) e `email-verification`.

**Acceptance Criteria:**

1. WHEN uma sessão `kind: verification` acessa `/settings` THEN SHALL ser redirecionada para `/verify-email`; WHEN acessa `GET /api/bff/auth/me` THEN SHALL obter `200`.
2. WHEN uma sessão `kind: session` acessa `/verify-email` THEN SHALL ser redirecionada para `/`.
3. WHEN dois contextos de browser compartilham o mesmo usuário e um executa `logout-all` com senha THEN o segundo contexto SHALL aparecer deslogado no próximo request.

**Independent Test:** `frontend/e2e/guards.spec.ts` — cria a sessão `verification` via jornada de registro; usa dois `browser.newContext()` para o caso concorrente.

**Requirement IDs:** E2E-21

---

## Edge Cases

- WHEN o Mailpit não recebeu mensagem dentro do timeout THEN a suíte SHALL falhar com erro explícito (nunca prosseguir com token vazio).
- WHEN o link de verificação é aberto por `GET` (prefetch/scanner) em vez de ação explícita THEN a conta SHALL permanecer `pending_verification` (paridade `docs/testing.md` §6.1).
- WHEN o cookie de sessão é adulterado (byte trocado, comprimento alterado) THEN o request SHALL ser tratado como não autenticado sem consultar sessão arbitrária.
- WHEN `redis-ephemeral` está indisponível no meio de um request autenticado THEN a resposta SHALL ser logout seguro, não `500` com vazamento de detalhe.
- WHEN a suíte roda duas vezes seguidas sem `migrate:fresh` THEN o segundo run SHALL falhar de forma determinística no registro (endereço já usado) — documentado; o alvo sempre roda `migrate:fresh`.
- WHEN o TTL curto do profile `e2e` expira entre o `page.goto` e o assert THEN o teste SHALL usar esperas explícitas por estado (redirect/cookie), não `sleep` fixo frágil.
- WHEN a imagem `docker/node` não traz as libs de sistema do Chromium THEN o build SHALL falhar cedo (não em runtime da suíte).

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| BFFUI-80 | P1: Jornada + Bearer ausente | Design | Pending |
| BFFUI-81 | P1: CSRF / Origin / returnUrl | Design | Pending |
| BFFUI-82 | P1: Ciclo de vida da sessão | Design | Pending |
| BFFUI-83 | P1: Acessibilidade + reflow | Design | Pending |
| E2E-01 | P1: Jornada — register → verify → active | Design | Pending |
| E2E-02 | P1: Jornada — logout encerra sessão | Design | Pending |
| E2E-03 | P1: Jornada — logout-all com senha | Design | Pending |
| E2E-04 | P1: Jornada — forgot/reset revoga sessões | Design | Pending |
| E2E-05 | P1: Sentinel ausente de HTML / RSC / JS | Design | Pending |
| E2E-06 | P1: Sentinel ausente de cookies / storage / IndexedDB | Design | Pending |
| E2E-07 | P1: Sentinel ausente de respostas BFF + URL; `private, no-store` | Design | Pending |
| E2E-08 | P1: Sentinel ausente de logs do container + artefatos | Design | Pending |
| E2E-09 | P1: `Origin` ausente/divergente → rejeitado | Design | Pending |
| E2E-10 | P1: Double-submit CSRF quebrado → rejeitado; fluxo oficial ok | Design | Pending |
| E2E-11 | P1: `returnUrl` externo/ambíguo → caminho interno seguro | Design | Pending |
| E2E-12 | P1: Flush do Redis mid-sessão → logout sem fallback | Design | Pending |
| E2E-13 | P1: Idle TTL curto → sessão expira por inatividade | Design | Pending |
| E2E-14 | P1: TTL absoluto curto → sessão expira por limite absoluto | Design | Pending |
| E2E-15 | P1: `axe` sem violações `serious`/`critical` nos 6 fluxos | Design | Pending |
| E2E-16 | P1: Reflow 360 px sem scroll horizontal / recorte | Design | Pending |
| E2E-17 | P1: Profile `e2e` + Mailpit sobem `healthy` | Design | Pending |
| E2E-18 | P1: `make test-e2e-auth` roda a suíte e faz teardown; exit propagado | Design | Pending |
| E2E-19 | P1: `.github/workflows/frontend-e2e.yml` em PR + push `main` | Design | Pending |
| E2E-20 | P1: TTLs de sessão configuráveis por env; defaults preservados | Design | Pending |
| E2E-21 | P2: Guards de kind + concorrência de logout-all | Design | Pending |

**Coverage:** 25 IDs (4 catálogo + 21 fatia); 0 mapeados a tasks ainda; próximo passo Design.

---

## Success Criteria

- [ ] `make test-e2e-auth` sai com código 0 localmente contra o profile `e2e`, e ≠ 0 quando qualquer spec falha.
- [ ] `.github/workflows/frontend-e2e.yml` roda verde em PR e em push para `main`, com upload de artefatos Playwright em falha.
- [ ] A jornada convidado → verificado → autenticado → logout / logout-all e o fluxo forgot → reset passam contra Next + Nginx + Laravel + PostgreSQL + Redis efêmero + Mailpit reais.
- [ ] Nenhuma superfície de browser, resposta do BFF, log do container `frontend` ou artefato Playwright contém o Bearer sentinel ou o valor do cookie de sessão em claro.
- [ ] `Origin` ausente/divergente, double-submit CSRF quebrado e `returnUrl` externo são rejeitados ou neutralizados em stack real; o fluxo oficial equivalente tem sucesso.
- [ ] Flush do Redis, expiração idle e expiração absoluta encerram a sessão com cookie removido e sem fallback de token.
- [ ] `axe` não reporta violações `serious`/`critical` nos seis fluxos Auth críticos; as páginas fazem reflow a 360 px sem scroll horizontal.
- [ ] Defaults de TTL, `make up`, `make test`, `make test-frontend` e os ambientes `dev`/`testing` permanecem inalterados.

---

## Referências

- `docs/roadmap.md` — Fase 1 "Critérios de saída"
- `docs/security.md` §5 (sessão BFF), §12 (headers), §13 (telemetria/logs), §18 (gate de lançamento)
- `docs/testing.md` §3.2–3.4 (E2E, acessibilidade), §6.2 (casos BFF)
- `.specs/features/bff-auth/session-shell/spec.md` — SH-03, SH-19, SH-20 (comportamento consumido)
- `.specs/features/bff-auth/session-core/spec.md` — SC-14/SC-16 (config e probe), TTLs
- `.specs/STATE.md` — AD-011 (banco de teste), AD-013/014/017 (frontend, CI, prefixo BFF)
- Lição L-026 — comportamento de infra fora do app: marcar ops-verified ou adicionar seam testável in-repo
