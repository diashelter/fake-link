# Backend Quality Tooling Specification

**Status:** Fechada — confirmada 2026-07-22. Design e Tasks aprovados para Execute.

## Problem Statement

O backend Fake Link já possui Pest e Pint no `composer.json`, mas não tem Larastan, PHPMD, Pest Arch, cobertura via PCOV, arquivos de configuração nem targets reais de `make lint`. A documentação (`docs/testing.md`, `docs/roadmap.md`) exige gates estáticos e de cobertura desde a Fase 0, porém o pipeline local ainda é um placeholder.

Sem instalar e configurar essa stack de qualidade, o projeto não consegue validar estilo, análise estática, smells, limites arquiteturais modulares nem preparar cobertura antes dos módulos de domínio (Auth, Links, Redirects, Analytics).

## Goals

- [ ] Desenvolvedor executa análise estática, formatação, smells e testes backend exclusivamente via containers Docker, com um comando único (`make lint` / `make test-backend`).
- [ ] Pacotes e configs versionados no repositório refletem as decisões fechadas: **Pint** (sem PHP-CS-Fixer), **Larastan + strict rules** (sem PHPCS), **PHPMD**, **Pest + Pest Arch**, **PCOV** para cobertura.
- [ ] O código-base atual (skeleton Laravel + testes de health/stub) passa em todos os gates introduzidos sem warnings tolerados.
- [ ] Infraestrutura de cobertura está pronta; thresholds por módulo de `docs/testing.md` §4 ficam aplicáveis quando cada módulo for introduzido.
- [ ] Pull requests executam os mesmos gates backend via GitHub Actions, usando Docker Compose (sem PHP no runner host).

## Out of Scope

Explicitamente excluído. Documentado para evitar scope creep.

| Feature | Reason |
| --- | --- |
| CI completo de `docs/testing.md` §10 (frontend, OpenAPI, Playwright, Trivy, Gitleaks, smoke compose, builds multiarch) | Escopo desta feature limita-se ao **slice backend** de qualidade; demais jobs são features separadas |
| PHP-CS-Fixer | Substituído por Laravel Pint (decisão confirmada) |
| PHP_CodeSniffer (PHPCS) | Redundante com Pint + Larastan + Pest Arch (decisão confirmada) |
| PHP Insights | Substituído por PHPMD para smells/complexidade (decisão confirmada) |
| Frontend lint (ESLint, Prettier, TypeScript strict) | Escopo backend; frontend mantém placeholder até feature própria |
| OpenAPI lint e contract tests | Escopo de contrato/API; citado em `docs/testing.md` mas não parte desta feature |
| Scaffold completo de `modules/*` | Feature de domínio; Pest Arch aqui define regras e baseline testável no skeleton |
| Enforcement imediato de cobertura 90/85% e 80/80% por pasta de módulo | Código de domínio ainda não existe; infraestrutura de cobertura sim, thresholds por módulo quando módulos forem criados |
| Instalação local de PHP/Composer fora de container | Proibido pelo projeto |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Formatador único | Laravel Pint (`./vendor/bin/pint --test`) | Oficial Laravel; já instalado; AD implícito alinhado a `docs/testing.md` | y |
| Análise estática | `larastan/larastan` + `phpstan/phpstan-strict-rules` | Gates documentados; Larastan = PHPStan + regras Laravel | y |
| Nível PHPStan inicial | **6** (`level: 6` em `phpstan.neon`) | Decisão confirmada pelo usuário; subir gradualmente até máximo sustentável | y |
| Smells / complexidade | `phpmd/phpmd` com `phpmd.xml` | Decisão confirmada pelo usuário | y |
| Testes de arquitetura | `pestphp/pest-plugin-arch` | Gates modulares de `docs/testing.md` §3.1 | y |
| Cobertura | `ext-pcov` na imagem PHP **dev/test**; relatório via Pest/PHPUnit | PCOV mais rápido que Xdebug; não entra na imagem prod/runtime | y |
| Declaração de `phpunit/php-code-coverage` | Não declarar no `composer.json` | Pacote transitivo de PHPUnit/Pest | y |
| Ordem dos gates em `make lint` | Pint → PHPStan → PHPMD → Pest (unit/feature) | Falhas de estilo primeiro; testes por último | y |
| Warnings novos | Qualquer warning/error de Pint, PHPStan ou PHPMD SHALL falhar (exit ≠ 0) | `docs/testing.md` §4 | y |
| Composer scripts | `lint`, `analyse`, `md`, `quality` (ou equivalente documentado) | Reutilização por Makefile e CI | y |
| CI GitHub Actions | Workflow em `.github/workflows/` dispara em `pull_request` e `push` para `main`; executa gates backend via `docker compose run` | Alinha AD-003 e proibição de tooling no host; escopo backend desta feature | y |
| Paridade local/CI | CI invoca os mesmos comandos/scripts que `make lint` / `make test-backend` (sem drift) | Evita green local + red CI por configs divergentes | y |
| PCOV no Dockerfile | Instalar via `pecl install pcov` no stage `dev` (e usado em `compose run`) | Prod/runtime não precisa de cobertura | y |
| Pest Arch no skeleton | Regras mínimas + regras modulares para namespace futuro `Modules\{Module}\*` | Permite gate verde hoje e endurecimento quando módulos existirem | y |
| Threshold global de cobertura inicial | Relatório gerado sem `--min` bloqueante no P1; documentar como ativar por pasta depois | Evita gate falso-negativo antes dos módulos | y |

**Open questions:** none — all resolved or logged above.

---

## User Stories

### P1: Pacotes Composer de qualidade instalados e lockfile reproduzível ⭐ MVP

**User Story**: Como desenvolvedor, quero que todas as ferramentas de qualidade backend estejam declaradas em `require-dev` para que `composer install` no container reproduza o ambiente de análise.

**Why P1**: Sem pacotes pinados, configs e Makefile não têm base executável.

**Acceptance Criteria**:

1. WHEN `composer install` roda no container backend THEN SHALL instalar, além dos pacotes já presentes, `larastan/larastan`, `phpstan/phpstan-strict-rules`, `phpmd/phpmd` e `pestphp/pest-plugin-arch` com versões compatíveis com PHP ^8.3 e Laravel ^13.8.
2. WHEN `composer.json` é inspecionado THEN SHALL manter `laravel/pint`, `pestphp/pest`, `pestphp/pest-plugin-laravel` e `phpunit/phpunit` sem duplicar formatadores ou sniffers excluídos (PHP-CS-Fixer, PHPCS, PHP Insights ausentes).
3. WHEN `composer.lock` é commitado THEN SHALL refletir o grafo resolvido após instalação no container.
4. WHEN `composer.json` scripts são executados THEN SHALL existir scripts documentados para lint (`pint --test`), análise (`phpstan analyse`) e PHPMD, invocáveis via `composer run`.

**Independent Test**: `docker compose run --rm backend composer install` seguido de `composer show larastan/larastan phpmd/phpmd pestphp/pest-plugin-arch` listando pacotes instalados.

**Requirement IDs**: QTOOL-01, QTOOL-02, QTOOL-03, QTOOL-04

---

### P1: Arquivos de configuração versionados ⭐ MVP

**User Story**: Como desenvolvedor, quero configs explícitas no repositório para que Pint, Larastan e PHPMD tenham regras previsíveis e revisáveis em PR.

**Why P1**: Gates documentados exigem nível Larastan fixado e regras de complexidade; configs implícitas geram drift entre dev e CI.

**Acceptance Criteria**:

1. WHEN `backend/phpstan.neon` (ou `phpstan.neon.dist`) existe THEN SHALL definir `level: 6`, paths em `app/` e `tests/`, incluir `vendor/larastan/larastan/extension.neon` e `vendor/phpstan/phpstan-strict-rules/rules.neon`, e excluir `vendor/`, `bootstrap/cache/`, `storage/`.
2. WHEN `./vendor/bin/phpstan analyse` roda no container THEN SHALL terminar com exit code 0 no código-base atual sem warnings ignorados.
3. WHEN `backend/phpmd.xml` existe THEN SHALL analisar `app/` com regras mínimas de complexidade ciclomática (limite ≤ 12 por método), tamanho de método/classe e acoplamento (`CouplingBetweenObjects`), documentadas no arquivo.
4. WHEN `./vendor/bin/phpmd app text phpmd.xml` roda no container THEN SHALL terminar com exit code 0 no código-base atual.
5. WHEN `./vendor/bin/pint --test` roda no container THEN SHALL terminar com exit code 0 no código-base atual (preset Laravel default ou `pint.json` versionado se customização for necessária).

**Independent Test**: Executar os três comandos acima sequencialmente no container; todos exit 0.

**Requirement IDs**: QTOOL-05, QTOOL-06, QTOOL-07, QTOOL-08, QTOOL-09

---

### P1: PCOV e infraestrutura de cobertura no Docker ⭐ MVP

**User Story**: Como desenvolvedor, quero gerar relatório de cobertura backend no container para preparar os gates numéricos de `docs/testing.md` sem depender de Xdebug.

**Why P1**: Roadmap Fase 0 exige base com cobertura; PCOV precisa estar na imagem antes do CI.

**Acceptance Criteria**:

1. WHEN a imagem PHP `dev` é construída THEN SHALL incluir extensão `pcov` habilitada.
2. WHEN `php -m` roda no container backend dev THEN SHALL listar `pcov`.
3. WHEN `backend/phpunit.xml` é atualizado THEN SHALL declarar `<source>` incluindo `app/` e `<coverage>` com reporter `html` e/ou `text` (formato PHPUnit 12 compatível).
4. WHEN Pest é executado com flag de cobertura documentada (ex.: `php artisan test --coverage` ou equivalente Pest 4) no container dev THEN SHALL produzir relatório de cobertura sem erro de driver ausente.
5. WHEN a imagem `prod`/`runtime` é construída THEN SHALL **não** depender de PCOV para operação (PCOV permanece apenas no stage dev/test).

**Independent Test**: Rebuild imagem backend, rodar testes com cobertura no container, confirmar output de cobertura e ausência de erro "No code coverage driver available".

**Requirement IDs**: QTOOL-10, QTOOL-11, QTOOL-12, QTOOL-13, QTOOL-14

---

### P1: Targets Makefile e execução exclusiva via Docker ⭐ MVP

**User Story**: Como desenvolvedor, quero comandos únicos na raiz do monorepo (AD-003) para lint e testes backend, sem executar ferramentas no host.

**Why P1**: Interface operacional única já decidida; placeholder atual impede gates locais.

**Acceptance Criteria**:

1. WHEN o desenvolvedor executa `make lint` THEN SHALL executar, via container backend, Pint → PHPStan → PHPMD → Pest (suites unit/feature existentes) nesta ordem, falhando no primeiro exit ≠ 0.
2. WHEN o desenvolvedor executa `make help` THEN SHALL listar targets de lint backend (ex.: `lint`, `lint-backend`, `analyse-backend`, `test-backend-coverage` ou nomes equivalentes documentados).
3. WHEN qualquer target de lint/analyse roda THEN SHALL usar `docker compose run` ou `exec` conforme padrão existente em `make test-backend`, nunca binários do host.
4. WHEN `make lint` completa com sucesso no código-base atual THEN SHALL imprimir confirmação ou permanecer silencioso com exit 0 — nunca mascarar falha.

**Independent Test**: `make lint` green no repositório após implementação; `make help` mostra novos targets.

**Requirement IDs**: QTOOL-15, QTOOL-16, QTOOL-17, QTOOL-18

---

### P1: GitHub Actions — gates backend em pull request ⭐ MVP

**User Story**: Como mantenedor, quero que todo pull request execute automaticamente Pint, Larastan, PHPMD e Pest backend para bloquear regressões antes do merge.

**Why P1**: `docs/testing.md` §10 exige Pint e Larastan em PR; `docs/roadmap.md` Fase 0 inclui CI de pull request; paridade com `make lint` reduz surpresas no merge.

**Acceptance Criteria**:

1. WHEN um pull request é aberto ou atualizado contra `main` THEN workflow versionado em `.github/workflows/` SHALL executar os gates backend desta feature.
2. WHEN o workflow roda THEN SHALL usar Docker Compose para invocar comandos no container `backend` — **não** SHALL instalar PHP/Composer diretamente no runner Ubuntu.
3. WHEN o workflow executa análise estática THEN SHALL rodar, nesta ordem, Pint (`--test`), PHPStan/Larastan (nível 6) e PHPMD, falhando no primeiro exit ≠ 0.
4. WHEN o workflow executa testes THEN SHALL rodar Pest (suites unit/feature existentes) com cobertura via PCOV, produzindo relatório text ou artifact uploadável.
5. WHEN qualquer gate falha THEN o job SHALL falhar (exit ≠ 0) e SHALL expor logs identificáveis por ferramenta (step separado ou prefixo claro).
6. WHEN `push` ocorre em `main` THEN o mesmo workflow SHALL executar (proteção contínua pós-merge).
7. WHEN `make lint` passa localmente no skeleton THEN o workflow SHALL passar com a mesma revisão commitada (paridade local/CI).

**Independent Test**: Abrir PR de teste (ou `act` se documentado); workflow verde com skeleton atual; step artificial de falha Pint prova bloqueio.

**Requirement IDs**: QTOOL-27, QTOOL-28, QTOOL-29, QTOOL-30, QTOOL-31, QTOOL-32, QTOOL-33

---

### P2: Pest Architecture — baseline modular ⭐ Should have

**User Story**: Como mantenedor, quero testes de arquitetura automatizados para que violações de monólito modular falhem antes de merge.

**Why P2**: Documentado como gate obrigatório, mas depende de convenções de namespace; entrega após P1 garante plugin instalado e Makefile estável.

**Acceptance Criteria**:

1. WHEN `tests/Architecture/` contém testes Pest Arch THEN SHALL usar `pestphp/pest-plugin-arch`.
2. WHEN um Controller em `app/Http/Controllers` (fora de módulos futuros) contém chamada Eloquent direta adicionada em teste de mutação THEN o teste de arquitetura SHALL falhar (prova de discriminação mínima).
3. WHEN código referencia `Modules\{Other}\Infrastructure\Persistence\Eloquent\Models\*` a partir de outro módulo (fixture de teste ou regra preset) THEN SHALL falhar conforme `docs/testing.md` §3.1.
4. WHEN `make lint` ou target dedicado `test-architecture` roda THEN SHALL incluir suite Architecture com exit 0 no baseline atual.
5. WHEN Pest Arch está implementado THEN workflow GitHub Actions SHALL incluir suite Architecture no job backend (mesmo job ou step adicional documentado).

**Independent Test**: `vendor/bin/pest tests/Architecture` exit 0; teste sentinela com violação intencional falha; CI falha se regra sentinela for commitada.

**Requirement IDs**: QTOOL-19, QTOOL-20, QTOOL-21, QTOOL-22, QTOOL-34

---

### P2: Documentação e registro de decisão ⭐ Should have

**User Story**: Como contribuidor futuro, quero a stack de qualidade backend documentada e registrada em decisões do projeto.

**Why P2**: Evita reabrir debate PHP-CS-Fixer vs Pint; alinha README/Makefile com comportamento real.

**Acceptance Criteria**:

1. WHEN `docs/decisions.md` ou `.specs/STATE.md` é atualizado THEN SHALL registrar decisão de tooling (Pint, Larastan nível 6, PHPMD, CI backend via Docker, exclusões PHPCS/CS-Fixer/Insights) com ID sequencial (AD-009 ou próximo disponível).
2. WHEN `README.md` descreve comandos de qualidade THEN SHALL referenciar `make lint`, cobertura, workflow CI backend e execução via Docker, substituindo menções genéricas a placeholder quando aplicável.
3. WHEN `docs/testing.md` §4 e §10 listam gates THEN SHALL permanecer consistentes com a implementação backend (Pint, Larastan nível 6, PHPMD, Pest com cobertura; sem PHPCS).

**Independent Test**: Revisão manual dos três artefatos; links e comandos copiáveis batem com Makefile.

**Requirement IDs**: QTOOL-23, QTOOL-24, QTOOL-25

---

### P3: Meta-testes de verificação dos gates ⭐ Nice to have

**User Story**: Como mantenedor de CI, quero testes automatizados que provem que os comandos de qualidade existem e retornam exit 0 no baseline, para evitar regressão do próprio pipeline.

**Why P3**: Útil, mas redundante com `make lint` se bem integrado; baixa prioridade.

**Acceptance Criteria**:

1. WHEN suite Pest `tests/Feature/QualityToolingTest.php` (ou similar) roda THEN SHALL executar smoke dos comandos `pint --test`, `phpstan analyse` e `phpmd` via `Process` ou invocação documentada e assert exit code 0.

**Independent Test**: `make test-backend` inclui o meta-teste passando.

**Requirement IDs**: QTOOL-26

---

## Edge Cases

- WHEN `vendor/` não existe (fresh clone) THEN `make lint` SHALL falhar com mensagem clara sugerindo build/`composer install` no container, não stack trace obscuro.
- WHEN PHPStan encontra erro no código THEN SHALL exit ≠ 0 e imprimir caminho relativo `app/...` ou `tests/...` identificável.
- WHEN PHPMD encontra violação THEN SHALL exit ≠ 0 com regra e métrica nomeadas.
- WHEN PCOV não está carregado e cobertura é solicitada THEN SHALL falhar explicitamente ("No code coverage driver available"), não reportar 0% silencioso.
- WHEN Pint encontra arquivo não formatado THEN `pint --test` SHALL exit ≠ 0 listando arquivos afetados.
- WHEN Pest Arch não encontra testes THEN suite Architecture SHALL ser ignorada ou falhar conforme config — nunca passar silenciosamente sem testes mapeados (P2).
- WHEN Docker daemon indisponível no runner CI THEN workflow SHALL falhar com mensagem explícita, não simular sucesso parcial.
- WHEN cache de imagem Composer falha THEN workflow SHALL rebuild ou falhar — nunca pular gates por cache corrompido silenciosamente.

---

## Implicit-Requirement Dimensions (Large feature sweep)

| Dimension | Resolution |
| --- | --- |
| Input validation & bounds | N/A — ferramentas analisam código existente; configs definem paths e limites PHPMD |
| Failure / partial-failure states | Primeiro gate com falha aborta `make lint`; exit code propagado |
| Idempotency / retry | Reexecução dos mesmos comandos produz mesmo resultado determinístico |
| Auth boundaries & rate limits | N/A — tooling local/CI |
| Concurrency / ordering | Ordem fixa Pint → PHPStan → PHPMD → Pest documentada |
| Data lifecycle / expiry | N/A |
| Observability | Saída stdout/stderr das ferramentas; sem telemetria adicional |
| External-dependency failure | `composer install` falha se Packagist indisponível — comportamento padrão Composer |
| State-transition integrity | N/A |

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| QTOOL-01 | P1: Pacotes Composer | Tasks | Pending |
| QTOOL-02 | P1: Pacotes Composer | Tasks | Pending |
| QTOOL-03 | P1: Pacotes Composer | Tasks | Pending |
| QTOOL-04 | P1: Pacotes Composer | Tasks | Pending |
| QTOOL-05 | P1: Configs | Tasks | Pending |
| QTOOL-06 | P1: Configs | Tasks | Pending |
| QTOOL-07 | P1: Configs | Tasks | Pending |
| QTOOL-08 | P1: Configs | Tasks | Pending |
| QTOOL-09 | P1: Configs | Tasks | Pending |
| QTOOL-10 | P1: PCOV / cobertura | Tasks | Pending |
| QTOOL-11 | P1: PCOV / cobertura | Tasks | Pending |
| QTOOL-12 | P1: PCOV / cobertura | Tasks | Pending |
| QTOOL-13 | P1: PCOV / cobertura | Tasks | Pending |
| QTOOL-14 | P1: PCOV / cobertura | Tasks | Pending |
| QTOOL-15 | P1: Makefile | Tasks | Pending |
| QTOOL-16 | P1: Makefile | Tasks | Pending |
| QTOOL-17 | P1: Makefile | Tasks | Pending |
| QTOOL-18 | P1: Makefile | Tasks | Pending |
| QTOOL-27 | P1: GitHub Actions | Tasks | Pending |
| QTOOL-28 | P1: GitHub Actions | Tasks | Pending |
| QTOOL-29 | P1: GitHub Actions | Tasks | Pending |
| QTOOL-30 | P1: GitHub Actions | Tasks | Pending |
| QTOOL-31 | P1: GitHub Actions | Tasks | Pending |
| QTOOL-32 | P1: GitHub Actions | Tasks | Pending |
| QTOOL-33 | P1: GitHub Actions | Tasks | Pending |
| QTOOL-19 | P2: Pest Arch | Tasks | Pending |
| QTOOL-20 | P2: Pest Arch | Tasks | Pending |
| QTOOL-21 | P2: Pest Arch | Tasks | Pending |
| QTOOL-22 | P2: Pest Arch | Tasks | Pending |
| QTOOL-23 | P2: Documentação | Tasks | Pending |
| QTOOL-24 | P2: Documentação | Tasks | Pending |
| QTOOL-25 | P2: Documentação | Tasks | Pending |
| QTOOL-26 | P3: Meta-testes | Tasks | Pending |
| QTOOL-27 | P1: GitHub Actions | Design | Pending |
| QTOOL-28 | P1: GitHub Actions | Design | Pending |
| QTOOL-29 | P1: GitHub Actions | Design | Pending |
| QTOOL-30 | P1: GitHub Actions | Design | Pending |
| QTOOL-31 | P1: GitHub Actions | Design | Pending |
| QTOOL-32 | P1: GitHub Actions | Design | Pending |
| QTOOL-33 | P1: GitHub Actions | Design | Pending |
| QTOOL-34 | P2: Pest Arch + CI | Tasks | Pending |

**Coverage:** 34 total, 34 mapped to tasks, 0 unmapped ✅

---

## Success Criteria

- [ ] `make lint` passa no container com o skeleton atual (Pint, PHPStan nível 6, PHPMD, Pest).
- [ ] `make test-backend` com cobertura documentada gera relatório sem erro de driver.
- [ ] Workflow GitHub Actions backend passa no PR com a mesma revisão que passa localmente.
- [ ] Nenhum pacote excluído (PHP-CS-Fixer, PHPCS, PHP Insights) aparece em `composer.json`.
- [ ] Decisões de tooling registradas; README/Makefile alinhados.
- [ ] (P2) Pest Arch falha em violação modular sentinela, passa no baseline e roda no CI.
- [ ] CI reutiliza `composer run quality` / targets Makefile sem configs paralelas.

---

## Pacotes e artefatos esperados (referência de implementação)

### `require-dev` alvo

| Pacote | Já presente? |
| --- | --- |
| `laravel/pint` | Sim |
| `larastan/larastan` | Não |
| `phpstan/phpstan-strict-rules` | Não |
| `pestphp/pest` | Sim |
| `pestphp/pest-plugin-laravel` | Sim |
| `pestphp/pest-plugin-arch` | Não |
| `phpmd/phpmd` | Não |
| `phpunit/phpunit` | Sim |

### Arquivos alvo

| Arquivo | Propósito |
| --- | --- |
| `backend/phpstan.neon` | Larastan nível 6 + strict rules |
| `.github/workflows/backend-quality.yml` (ou nome equivalente) | CI PR/push: Pint, Larastan, PHPMD, Pest+coverage via Docker |
| `backend/phpmd.xml` | Limites de complexidade e acoplamento |
| `backend/phpunit.xml` | Seção `<coverage>` |
| `docker/php/Dockerfile` | `pecl install pcov` no stage `dev` |
| `Makefile` | `lint`, targets backend dedicados |
| `backend/composer.json` | Scripts `lint` / `analyse` / `quality` |
| `backend/tests/Architecture/*.php` | Gates modulares (P2) |
