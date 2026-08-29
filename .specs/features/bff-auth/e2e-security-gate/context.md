# BFF Auth — Gate E2E de segurança · Context

**Gathered:** 2026-08-29
**Spec:** `.specs/features/bff-auth/e2e-security-gate/spec.md`
**Status:** Ready for design

---

## Feature Boundary

Suíte Playwright + composição Docker de suporte que prova, contra a stack real (Next + Nginx + Laravel + PostgreSQL + Redis efêmero + e-mail), os critérios de saída de segurança/acessibilidade da Fase 1 Auth+BFF: jornada de conta ponta a ponta, Bearer nunca no browser, CSRF/`Origin`/`returnUrl` bloqueiam, flush do Redis e expirações encerram a sessão, `axe` limpo nos fluxos críticos. Empacotada em `make test-e2e-auth` + workflow de CI. **Sem** features de produto novas.

---

## Implementation Decisions

### Obtenção do token de verificação de e-mail (e reset)

- Serviço **Mailpit** em-composição, ativado só pelo profile `e2e`.
- `backend` (e workers) sob o profile `e2e`: `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`.
- A suíte lê a mensagem pela **HTTP API do Mailpit** (`http://mailpit:8025/api/v1/...`), extrai o link `?token=` do corpo e navega/POSTa por ele.
- `dev` continua `MAIL_MAILER=log`; `testing` (Pest) continua `array`. Nenhum ambiente existente muda.
- Isolamento entre casos: a suíte apaga o mailbox (`DELETE /api/v1/messages`) antes de cada envio que vai consumir.
- **Rejeitados:** parse de `storage/logs/laravel.log` (frágil), rota backend test-only (superfície nova em produção a guardar), query direta no Postgres (acopla ao schema/hashing).

### Estratégia de relógio / TTL para expiração idle e absoluta

- O check de expiração é **server-side** no Route Handler (`bff-session.ts` → `ttl.ts`), então mockar o relógio do browser não afeta. `page.clock` do Playwright é inaplicável.
- Decisão: tornar os TTLs **configuráveis por env**, lidos em `loadBffSessionConfig()`, com **defaults idênticos aos atuais** (`session` 604800/86400, `verification` 86400/3600). Só o profile `e2e` aplica valores curtos e a suíte aguarda tempo real.
- Valores `e2e` propostos: absoluto 20 s / idle 8 s para ambos os kinds (Design/execução ajusta se houver flake).
- O refactor vive no domínio `session-core` (`lib/session/ttl.ts` + `config.ts` + `services/bff-session.ts`), é backward-compatible e executado **dentro desta fatia** — não reabre a spec `session-core`; os testes unitários já Verified daquela fatia devem permanecer verdes.
- **Rejeitados:** só ops-verified sem cobertura idle/absoluto em stack real (perde sinal do gate); mock de relógio no browser (não alcança o check server-side).

### Empacotamento e disparo do gate

- Novo profile Compose **`e2e`** (serviços base + `mailpit`), com override `docker-compose.e2e.yml` (espelha o padrão de `docker-compose.dev.yml`).
- Novo alvo **`make test-e2e-auth`**: `migrate:fresh` no `fake_link_testing` → `up --wait` do profile `e2e` → `pnpm test:e2e` dentro do container `frontend` → teardown → propaga exit code. **Não** entra em `make test`.
- Novo workflow **`.github/workflows/frontend-e2e.yml`** em `pull_request` + `push` para `main`, rodando `make test-e2e-auth` e publicando artefatos Playwright em falha.
- **Rejeitados:** reusar o profile `test` (mistura E2E lento com gates rápidos); adiar o wiring de CI (a Fase 1 exige o gate verde e verificável).

### Home e execução da suíte

- Specs, fixtures e `playwright.config.ts` em **`frontend/e2e/`** (fora de `frontend/modules/**`, logo fora da cobertura Vitest).
- A suíte roda **dentro do container `frontend`** (mesmo container do Next dev server). `@playwright/test` + `@axe-core/playwright` nas devDependencies do `frontend`; Chromium + libs de sistema via um estágio de build dedicado.
- **Só Chromium** como gate; matriz Firefox/WebKit/BrowserStack é pré-release (fora de escopo).
- **Rejeitados:** serviço Playwright separado (o mantenedor preferiu reusar o container); `tests/e2e/` na raiz.

### Regras de severidade e falha do gate

- `axe`: falha em violações de impacto `serious` **ou** `critical` nas 6 páginas (login, register, verify-email, forgot-password, reset-password, `/settings`); `moderate`/`minor` só reportados.
- Qualquer vazamento de Bearer **ou** bypass de CSRF/`Origin` falha o gate incondicionalmente.
- Artefatos Playwright (trace/HAR/vídeo) não podem conter o cookie de sessão nem o Bearer sentinel em claro — a suíte mascara e assevera.

---

## Agent's Discretion

Fechar no Design (marcados `Confirmed = n` na spec):

- Nomes exatos das env vars de TTL e valores `e2e` finais.
- Alias de rede vs `extra_hosts` para resolver `app.localhost`/`go.localhost` dentro da rede Compose; flag Chromium `--host-resolver-rules` para o browser.
- Banco do `e2e` (reusar `fake_link_testing` vs dedicado) — default: reusar, recriado com `migrate:fresh`.
- Layout de `frontend/e2e/` (arquivos de spec, `fixtures/`, `.artifacts/` git-ignored) e opções de retenção de trace/vídeo.
- Tag exata da imagem `axllent/mailpit` (pin em `docker/versions.env`).
- `QUEUE_CONNECTION` sob `e2e` (default proposto: `sync`, para tirar timing de worker da jornada).

---

## Declined / Undiscussed Gray Areas → Assumptions

Nenhuma área foi declinada. Todas as 4 gray areas do Seed foram discutidas e decididas (acima). Defaults derivados estão registrados na tabela **Assumptions & Open Questions** da spec com `Confirmed = y/n`.

---

## Specific References

- Padrão de profile/override: `docker-compose.yml` + `docker-compose.dev.yml` (AD-008); serviços com `profiles:` no arquivo base (`swagger-ui`, `otel-collector`).
- Padrão de probe/DI test-only já no repo: `frontend/app/api/_test/session/route.ts` (`BFF_SESSION_PROBE_ENABLED`).
- Sentinel de Bearer: `/api/v1` é público (`docs/security.md` §6) — a suíte captura o Bearer plaintext direto da API para usar como string exata nas asserções de ausência.
- Lição **L-026**: comportamento de infra fora do app → ops-verified ou seam testável in-repo (aplicada ao logout com Laravel indisponível, que fica só Vitest/ops).

---

## Deferred Ideas

- Matriz BrowserStack (Chrome/Edge/Firefox/Safari desktop + iOS) — pré-release Fase 4.
- Regressão visual de pixels ampla — `docs/testing.md` §3.3 restringe; só smoke de reflow 360 px aqui.
- E2E de Links/Redirect/Analytics/Operations — Fase 2+.
- Export OpenTelemetry / alertas Grafana a partir da suíte — Fase 4.
- Tornar `page.clock` útil movendo o check de expiração para o cliente — anti-objetivo (o check é server-side por design).
