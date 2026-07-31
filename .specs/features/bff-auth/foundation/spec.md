# BFF Auth — Fundação frontend

**Status:** Approved — 2026-07-30  
**Fatia:** 1 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-01 … BFFUI-05  
**Requirement IDs (fatia):** FND-01 … FND-18  
**Depende de:** API Auth MVP (`.specs/features/auth/`); Fase 0 (Docker, quality gates backend)

## Problem Statement

O frontend atual é um bootstrap mínimo (landing + health + helper de cookie). Sem scaffold modular, defaults de formulário/query, primitivos de UI, Tailwind e gates reais de lint/teste/hooks de commit, as fatias de BFF e UI Auth não têm onde ancorar código, convenções nem verificação.

## Goals

- [ ] Estrutura modular em `frontend/modules/{auth,shared}/` com App Router em `frontend/app/`.
- [ ] React Hook Form + Zod + `@hookform/resolvers` com defaults documentados e testados (erros client/server, foco, submit repetido).
- [ ] TanStack Query sem persistência; defaults: `staleTime` 30s, `gcTime` 5min, 1 retry só em GET transitório, zero retry em mutations; polling 60s somente com página visível.
- [ ] Tailwind CSS configurado; tema claro único; shell layout pt-BR com reflow a partir de 360px.
- [ ] Primitivos mínimos em `shared`: `Button`, `Input`, `Label`, `FormField`.
- [ ] Vitest + RTL + MSW + jsdom; cobertura de domínios frontend ≥75% linhas/branches no código desta fatia.
- [ ] ESLint (flat, Next/TS) + Prettier + TypeScript strict; `make lint-frontend` verde; `make lint` inclui o gate frontend.
- [ ] Husky + lint-staged no monorepo: pre-commit formata/linta staged files do frontend.
- [ ] Nenhuma Route Handler de produto Auth nesta fatia (exceto `health` já existente); sem expandir crypto de sessão.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Crypto de sessão, Redis, cookie de produto (`__Host-`) | Fatia `session-core` |
| CSRF, allowlist, returnUrl | Fatia `csrf-proxy` |
| Páginas login/register/verify/password/perfil | Fatias 4–8 |
| Playwright E2E Auth / axe gate completo | Fatia `e2e-security-gate` |
| Radix Primitives | Roadmap Fase 0/1 visual; adiado — primitivos próprios com Tailwind nesta fatia |
| Client TypeScript gerado da OpenAPI | Gate CI documentado; não bloqueia scaffold desta fatia |
| Dashboard Links / Analytics UI | Fase 2+ |
| Alterar comportamento de `lib/session-cookie.ts` além do necessário para lint | Já existe; crypto de produto em `session-core` |
| Husky em arquivos backend (Pint/Pest) | Opcional futuro; esta fatia cobre staged frontend |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Local dos módulos | `frontend/modules/{auth,shared}/` + App Router em `frontend/app/` | Decisão do mantenedor (1A); alinha `AGENTS.md` Fake Link | y |
| Dependências runtime | `react-hook-form`, `zod`, `@hookform/resolvers`, `@tanstack/react-query` | Lista confirmada; docs/testing + roadmap | y |
| Dependências estilo | `tailwindcss` (+ peer/postcss/autoprefixer conforme Next 16) | Mantenedor: Tailwind sim nesta fatia | y |
| Dependências teste | `@testing-library/react`, `@testing-library/jest-dom`, `@testing-library/user-event`, `msw`, `jsdom` | Lista confirmada; `docs/testing.md` §3.2 | y |
| Dependências qualidade | ESLint flat (ecosistema Next/TS), Prettier, Husky, lint-staged | Mantenedor: instalar e configurar nesta fatia (4A + pedido explícito) | y |
| Radix | **Não** instalar nesta fatia | Primitivos B sem Radix; roadmap Radix fica Deferred | y |
| Client Components | Só onde interação/browser API exigir; default server-first | `docs/testing.md` §3.2 | y |
| i18n | Sem biblioteca; cópias hardcoded pt-BR | Produto MVP sem i18n | y |
| Primitivos UI | `Button`, `Input`, `Label`, `FormField` em `modules/shared/` | Decisão 3B; base para forms Auth nas fatias seguintes | y |
| Alias `@/` | Continua apontando para raiz `frontend/` (`tsconfig` atual) | Evita migração de paths nesta fatia | y |
| Bootstrap Husky | Criar `package.json` na **raiz do monorepo** (hoje inexistente) só para Husky/lint-staged/scripts de prepare | Frontend já tem `package.json` próprio; hooks git vivem na raiz | y |
| lint-staged escopo | Arquivos staged sob `frontend/**/*.{ts,tsx,js,jsx,mjs,cjs,css,md,json}` → Prettier + ESLint `--fix` (quando aplicável) | Fatia é frontend; backend permanece no fluxo `make lint` Docker | y |
| `make lint` | Passa a executar `lint-backend` **e** `lint-frontend` (fail-fast) | Decisão 4A | y |
| `make lint-frontend` | No container frontend: `tsc --noEmit` + ESLint + Prettier `--check` | Paridade com gates CI documentados em `docs/testing.md` §8 | y |
| Vitest environment | `jsdom` para `*.test.tsx`; node permitido para módulos puros `*.test.ts` | RTL exige DOM; health/cookie podem permanecer node | y |
| Query provider | Provider client mínimo no layout (ou wrapper) sem persistência; sem Auth queries ainda | Defaults testáveis antes das fatias de fluxo | y |
| Cobertura | Domínios frontend introduzidos ≥75% linhas/branches; script/gate Makefile documentado | `docs/testing.md` §4 | y |
| Schemas Zod de exemplo | Schema de e-mail (e opcionalmente senha alinhada OpenAPI) em `shared` ou `auth` só como âncora de teste — **sem** UI de login | Prova FND de forms stack sem scope creep | y |
| Health route | Mantida; permanece fora do módulo Auth | Já existe; não é produto Auth | y |
| Versões de pacotes | Pin compatível com Next 16.2.11 / React 19 / pnpm 11.15.1 (`docker/versions.env`) | AD-005; Design escolhe ranges exatos | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Schemas Zod compartilhados; bounds de e-mail/senha alinhados à OpenAPI nos schemas de âncora; FormField propaga erros RHF |
| Failure / partial-failure states | Falha de lint/test/hook de commit = exit ≠ 0; sem estados de produto Auth |
| Idempotency / retry / duplicate | TanStack Query: 1 retry só GET transitório; 0 retry em mutations; sem persistência de cache |
| Auth boundaries & rate limits | N/A — sem handlers Auth; Bearer SHALL NOT ser introduzido no browser nesta fatia |
| Concurrency / ordering | N/A |
| Data lifecycle / expiry | N/A — TTL de sessão em `session-core` |
| Observability | Sem logar segredos; Prettier/ESLint sem telemetria de produto |
| External-dependency failure | N/A — sem chamadas Laravel nesta fatia |
| State-transition integrity | N/A — sem fluxos Auth |

---

## Entregáveis técnicos

### Árvore (mínima)

```txt
frontend/
  app/                          # App Router (já existe)
    layout.tsx                  # lang=pt-BR; shell + providers necessários
    page.tsx                    # landing; tema claro + Tailwind
    health/                     # existente
  modules/
    auth/                       # scaffold (schemas/hooks/components vazios ou mínimos)
    shared/
      components/ui/            # Button, Input, Label, FormField
      lib/                      # query-client defaults, form defaults
      schemas/                  # ex.: emailSchema âncora
  lib/                          # session-cookie existente (não expandir crypto)
  eslint.config.*               # flat config Next/TS
  .prettierrc* / prettier config
  vitest.config.ts              # jsdom + coverage thresholds
  postcss.config.* / tailwind.*
package.json                    # raiz: husky, lint-staged, prepare
.husky/pre-commit               # lint-staged
```

Pastas vazias antecipadas **não** devem ser criadas além do necessário para imports/testes desta fatia.

### Defaults TanStack Query (testáveis)

| Setting | Valor |
| --- | --- |
| Persistência | Ausente (nenhum persister) |
| `staleTime` | 30_000 ms |
| `gcTime` | 300_000 ms |
| Retry queries | 1, somente falhas transitórias de GET |
| Retry mutations | 0 |
| Polling | 60_000 ms somente com `document.visibilityState === 'visible'` |

### Defaults formulário (testáveis)

- Resolver Zod via `@hookform/resolvers`.
- Erros client-side e server-side mapeáveis para campos.
- Foco no primeiro campo inválido após submit.
- Submit repetido enquanto `isSubmitting` → ignorado/desabilitado.
- Preservação segura de dados não sensíveis em re-render; senha nunca espelhada em URL/storage.

### Primitivos

| Componente | Responsabilidade mínima |
| --- | --- |
| `Button` | Variantes básicas (primary/secondary/destructive ou equivalente); disabled; type submit/button |
| `Input` | text/email/password; acessível (id/label association) |
| `Label` | Associa a controle; pt-BR ready |
| `FormField` | Compõe label + input + mensagem de erro RHF |

### Gates Makefile

| Target | Comportamento |
| --- | --- |
| `make lint-frontend` | Container frontend: `tsc --noEmit` + ESLint + Prettier `--check` |
| `make lint` | `lint-backend` então `lint-frontend` (fail-fast) |
| `make test-frontend` | Vitest no container (já existe); inclui RTL/MSW novos |
| Cobertura frontend | Target ou flag documentada; falha se <75% no escopo de domínios introduzidos |

### Git hooks

| Hook | Ação |
| --- | --- |
| `pre-commit` (Husky) | `lint-staged` nos arquivos staged do frontend |
| lint-staged | Prettier write + ESLint `--fix` nos globs frontend |

---

## User Stories

### P1: Scaffold modular ⭐ MVP

**User Story**: Como desenvolvedor, quero módulos `auth` e `shared` no frontend para ancorar as fatias BFF/UI sem retrabalho estrutural.

**Why P1**: BFFUI-01; todas as fatias seguintes importam dessa árvore.

**Acceptance Criteria**:

1. WHEN o repositório frontend é inspecionado THEN SHALL existir `frontend/modules/auth/` e `frontend/modules/shared/` com App Router em `frontend/app/` (não sob `src/`).
2. WHEN TypeScript resolve imports `@/modules/...` THEN SHALL compilar com `strict: true`.
3. WHEN esta fatia conclui THEN SHALL NOT existir Route Handler de produto Auth novo (login/register/etc.); `app/health` MAY permanecer.
4. WHEN a landing é renderizada THEN `html[lang]` SHALL ser `pt-BR` e o tema SHALL ser claro único (sem dark mode).

**Independent Test**: Smoke de build/tsc + assert de árvore; health e landing sem regressão.

**Requirement IDs**: BFFUI-01, FND-01, FND-02

---

### P1: Forms stack (RHF + Zod) ⭐ MVP

**User Story**: Como desenvolvedor, quero defaults de formulário documentados e testados para as UIs Auth não reinventarem validação.

**Why P1**: BFFUI-02; `docs/testing.md` §3.2 exige cobertura de erros client/server, foco e submit repetido.

**Acceptance Criteria**:

1. WHEN um schema Zod de e-mail de âncora valida entrada inválida THEN Vitest SHALL observar erro de campo testável.
2. WHEN um formulário de demonstração (ou harness de teste) usa RHF + resolver Zod e submete inválido THEN o foco SHALL mover para o primeiro campo inválido.
3. WHEN submit é acionado enquanto `isSubmitting` é true THEN um segundo submit SHALL NOT disparar nova submissão.
4. WHEN erro server-side é injetado no harness THEN SHALL ser exibido no campo/form sem vazar stack trace ao usuário.
5. WHEN dependências são listadas em `frontend/package.json` THEN SHALL incluir `react-hook-form`, `zod` e `@hookform/resolvers`.

**Independent Test**: Vitest + RTL no harness/FormField; sem página de login.

**Requirement IDs**: BFFUI-02, FND-03, FND-04

---

### P1: TanStack Query defaults ⭐ MVP

**User Story**: Como desenvolvedor, quero QueryClient com defaults fixos e sem persistência para evitar cache sensível no browser.

**Why P1**: BFFUI-03; defaults são contrato de teste do projeto.

**Acceptance Criteria**:

1. WHEN o QueryClient default é inspecionado/testado THEN `staleTime` SHALL ser 30s e `gcTime` SHALL ser 5min.
2. WHEN uma query GET falha com erro transitório THEN SHALL haver no máximo 1 retry; mutations SHALL ter 0 retries.
3. WHEN o código de setup é inspecionado THEN SHALL NOT registrar persister (localStorage/sessionStorage/IndexedDB).
4. WHEN polling é configurado no helper default THEN SHALL respeitar intervalo de 60s somente com página visível (relógio/visibilidade controlados no teste).
5. WHEN `frontend/package.json` é inspecionado THEN SHALL incluir `@tanstack/react-query`.

**Independent Test**: Vitest unitário do factory/defaults com fake timers + visibility.

**Requirement IDs**: BFFUI-03, FND-05, FND-06

---

### P1: Tailwind, shell e primitivos ⭐ MVP

**User Story**: Como usuário, quero a landing legível em pt-BR a partir de 360px com componentes base reutilizáveis.

**Why P1**: BFFUI-05; product.md exige pt-BR, tema claro, 360px; decisão 3B.

**Acceptance Criteria**:

1. WHEN Tailwind está configurado THEN classes utilitárias SHALL aplicar na landing/shell sem regressão visual funcional (tema claro).
2. WHEN o viewport é 360px THEN o shell/landing SHALL refluir sem overflow horizontal causado pelo layout desta fatia.
3. WHEN `Button`, `Input`, `Label` e `FormField` são renderizados em teste THEN SHALL expor associação label/controle e estado de erro acessível.
4. WHEN Radix é buscado em `package.json` THEN SHALL NOT estar listado como dependência desta fatia.

**Independent Test**: RTL dos primitivos + smoke render landing; assert ausência de Radix.

**Requirement IDs**: BFFUI-05, FND-07, FND-08

---

### P1: Gates Vitest / ESLint / Prettier / TypeScript ⭐ MVP

**User Story**: Como mantenedor, quero gates frontend reais no Makefile para impedir regressão de qualidade.

**Why P1**: BFFUI-04; decisão 4A; `pnpm lint` hoje é placeholder.

**Acceptance Criteria**:

1. WHEN `make lint-frontend` roda no container THEN SHALL executar `tsc --noEmit`, ESLint e Prettier `--check` e exit 0 no código desta fatia.
2. WHEN `make lint` roda THEN SHALL incluir o gate frontend além do backend (fail-fast).
3. WHEN `make test-frontend` roda THEN Vitest SHALL executar testes unitários/RTL/MSW introduzidos e exit 0.
4. WHEN cobertura dos domínios frontend desta fatia é medida THEN linhas e branches SHALL ser ≥75% no escopo introduzido (`modules/auth`, `modules/shared` e defaults relacionados).
5. WHEN `frontend/package.json` script `lint` é invocado THEN SHALL NOT ser um no-op (`echo` placeholder).

**Independent Test**: Rodar `make lint-frontend && make test-frontend` via Docker; CI local verde.

**Requirement IDs**: BFFUI-04, FND-09, FND-10, FND-11

---

### P1: Husky + lint-staged ⭐ MVP

**User Story**: Como desenvolvedor, quero pre-commit que formate e linte arquivos frontend staged para não commitar código fora do padrão.

**Why P1**: Pedido explícito do mantenedor nesta fatia; complementa gates Makefile.

**Acceptance Criteria**:

1. WHEN o monorepo é inspecionado THEN SHALL existir bootstrap Husky na raiz (ex.: `package.json` raiz com `prepare` + `.husky/pre-commit`).
2. WHEN um arquivo frontend staged viola ESLint/Prettier de forma auto-corrigível THEN lint-staged SHALL aplicar fix antes do commit completar o hook.
3. WHEN um arquivo frontend staged contém erro ESLint não auto-corrigível THEN o pre-commit SHALL falhar (exit ≠ 0).
4. WHEN somente arquivos fora de `frontend/` são staged THEN o hook SHALL NOT exigir gate frontend completo do Makefile (lint-staged no-op ou skip desses paths).
5. WHEN documentação da fatia/README frontend menciona hooks THEN SHALL indicar `pnpm install` na raiz (ou comando documentado) para ativar Husky.

**Independent Test**: Simular staged file com erro de lint (teste de integração do config ou script documentado); assert fail; caso clean passa.

**Requirement IDs**: FND-12, FND-13, FND-14

---

### P2: Harness MSW pronto para fatias Auth

**User Story**: Como desenvolvedor, quero MSW configurado para os handlers Auth mockarem a API sem rede real.

**Why P2**: Necessário cedo, mas handlers de produto Auth entram nas fatias 4–8; foundation só deixa o wiring.

**Acceptance Criteria**:

1. WHEN um teste Vitest usa MSW THEN SHALL poder interceptar um request HTTP de exemplo sem rede externa.
2. WHEN a suíte termina THEN servers MSW SHALL ser resetados/parados (sem vazamento entre testes).

**Independent Test**: Um teste smoke MSW (GET exemplo) verde.

**Requirement IDs**: FND-15, FND-16

---

## Edge Cases

- WHEN `make lint-frontend` roda sem `node_modules` no container THEN a imagem/compose SHALL instalar deps via fluxo Docker documentado (não depender de install no host).
- WHEN Prettier e ESLint discordam de formatting THEN SHALL existir integração (ex.: `eslint-config-prettier`) para ESLint não conflitar com Prettier.
- WHEN teste RTL importa Client Component THEN Vitest/jsdom SHALL renderizar sem exigir browser real.
- WHEN alguém adiciona `persistQueryClient` ou similar THEN testes de FND-05/06 SHALL falhar (ausência de persister é requisito).
- WHEN landing existente é migrada para Tailwind THEN conteúdo pt-BR e health `/health` SHALL permanecer funcionais.
- WHEN husky não está instalado (clone fresco sem `pnpm install` na raiz) THEN Makefile gates SHALL continuar válidos independentemente dos hooks.
- WHEN arquivo sensível (`.env`) é staged THEN lint-staged SHALL NOT imprimir segredos; `.env` permanece gitignored.

---

## Requirement Traceability

| Requirement ID | Story | Descrição | Phase | Status |
| --- | --- | --- | --- | --- |
| BFFUI-01 | P1: Scaffold | Módulos `auth` / `shared` | Tasks | Pending |
| FND-01 | P1: Scaffold | Árvore `modules/` + `app/` | Tasks | Pending |
| FND-02 | P1: Scaffold | Sem Route Handlers Auth de produto; lang pt-BR | Tasks | Pending |
| BFFUI-02 | P1: Forms | Defaults RHF + Zod documentados/testados | Tasks | Pending |
| FND-03 | P1: Forms | Schema âncora + erros testáveis | Tasks | Pending |
| FND-04 | P1: Forms | Foco, submit repetido, erro server-side | Tasks | Pending |
| BFFUI-03 | P1: Query | Defaults TanStack sem persistência | Tasks | Pending |
| FND-05 | P1: Query | staleTime/gcTime/retry | Tasks | Pending |
| FND-06 | P1: Query | Polling visível 60s; sem persister | Tasks | Pending |
| BFFUI-05 | P1: Shell | Layout pt-BR / claro / 360px | Tasks | Pending |
| FND-07 | P1: Shell | Tailwind + reflow 360px | Tasks | Pending |
| FND-08 | P1: Shell | Primitivos Button/Input/Label/FormField; sem Radix | Tasks | Pending |
| BFFUI-04 | P1: Gates | Vitest / ESLint / TS strict | Tasks | Pending |
| FND-09 | P1: Gates | `make lint-frontend` (tsc+ESLint+Prettier) | Tasks | Pending |
| FND-10 | P1: Gates | `make lint` inclui frontend | Tasks | Pending |
| FND-11 | P1: Gates | Cobertura ≥75% domínios introduzidos | Tasks | Pending |
| FND-12 | P1: Husky | Bootstrap Husky na raiz | Tasks | Pending |
| FND-13 | P1: Husky | lint-staged fix em staged frontend | Tasks | Pending |
| FND-14 | P1: Husky | Falha em erro não corrigível; skip non-frontend | Tasks | Pending |
| FND-15 | P2: MSW | Wiring MSW smoke | Tasks | Pending |
| FND-16 | P2: MSW | Reset entre testes | Tasks | Pending |
| FND-17 | P1: Forms | Pacotes RHF/Zod/resolvers presentes | Tasks | Pending |
| FND-18 | P1: Query | Pacote `@tanstack/react-query` presente | Tasks | Pending |

**ID format:** `FND-NN` (fatia) + `BFFUI-NN` (catálogo índice)

**Coverage:** 22 total, 22 mapped to tasks (T1–T16), 0 unmapped

---

## Success Criteria

- [ ] `make lint` e `make test-frontend` passam com o scaffold desta fatia.
- [ ] `make lint-frontend` falha se TypeScript, ESLint ou Prettier check quebrarem.
- [ ] Defaults RHF/Zod e TanStack Query têm testes que falhariam se os valores documentados mudassem sem atualizar o teste.
- [ ] Primitivos `Button`/`Input`/`Label`/`FormField` existem e são cobertos por RTL.
- [ ] Husky pre-commit + lint-staged ativos após install documentado na raiz.
- [ ] Nenhum Bearer, cookie `__Host-` de produto, CSRF ou página Auth introduzidos.
- [ ] Fatia `session-core` pode iniciar sem mover a árvore `modules/` nem trocar defaults de Query/forms.

---

## Verificação (gates da fatia)

| Gate | Comando / artefato |
| --- | --- |
| Lint frontend | `make lint-frontend` |
| Lint monorepo | `make lint` (backend + frontend) |
| Testes frontend | `make test-frontend` |
| Cobertura | gate ≥75% no escopo `modules/{auth,shared}` + defaults |
| Hooks | `.husky/pre-commit` + lint-staged config |
| Docker | Comandos somente via containers / Makefile |

---

## Referências

- [Índice BFF Auth](../README.md)
- `docs/testing.md` §3.2, §4, §8
- `docs/product.md` §8 (pt-BR, tema claro, 360px)
- `docs/roadmap.md` Fase 0/1 (Next, Tailwind, RHF, Query, ESLint, Prettier)
- `docs/architecture.md` §8 (BFF — não implementar sessão aqui)
- `frontend/lib/session-cookie.ts` (existente; não expandir crypto)
- `context.md` (decisões desta Specify)
