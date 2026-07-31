# BFF Auth — Fundação frontend — Context

**Gathered:** 2026-07-30  
**Spec:** `.specs/features/bff-auth/foundation/spec.md`  
**Status:** Locked — Spec Approved 2026-07-30; ready for Design/Tasks/Execute

---

## Feature Boundary

Entregar o scaffold modular do frontend (`modules/auth`, `modules/shared`), defaults testáveis de RHF+Zod e TanStack Query, Tailwind + shell pt-BR/tema claro/360px, primitivos UI mínimos, gates Vitest/ESLint/Prettier/TypeScript no Makefile, e Husky+lint-staged na raiz — **sem** sessão BFF, CSRF, Route Handlers Auth de produto ou páginas de fluxo.

---

## Implementation Decisions

### Árvore de pastas

- `frontend/modules/{auth,shared}/` + App Router em `frontend/app/` (opção A).
- Sem mover para `src/`.

### Pacotes

- Runtime: `react-hook-form`, `zod`, `@hookform/resolvers`, `@tanstack/react-query`.
- Estilo: Tailwind CSS (sim nesta fatia).
- Teste: RTL + jest-dom + user-event + MSW + jsdom.
- Qualidade: ESLint + Prettier + Husky + lint-staged (pedido explícito).
- **Não** instalar Radix nesta fatia.

### Primitivos de UI

- `Button`, `Input`, `Label`, `FormField` em `shared` (opção B).
- Sem design system completo; tokens/utilitários Tailwind suficientes para o shell.

### Gates de lint

- ESLint + TypeScript strict + Prettier reais nesta fatia (opção A).
- Novo `make lint-frontend`; `make lint` inclui frontend além do backend.

### Husky + lint-staged

- Bootstrap na raiz do monorepo (`package.json` raiz hoje inexistente).
- `pre-commit` roda lint-staged em globs `frontend/**`.
- Makefile gates permanecem a fonte de verdade via Docker; hooks são rede de segurança no host do dev.

### Agent's Discretion

- Ranges exatos de versão dos pacotes (compatíveis com Next 16.2.11 / React 19 / pnpm 11.15.1).
- Flat config ESLint específica (eslint-config-next vs composição manual).
- Local exato dos arquivos de config Prettier/Tailwind/PostCSS.
- Se o harness de formulário é um componente de teste-only ou um exemplo mínimo em `shared`.
- Variantes visuais exatas dos primitivos (desde que acessíveis e tema claro).

### Declined / Undiscussed Gray Areas → Assumptions

- Client OpenAPI gerado: fora desta fatia (já em Out of Scope da spec).
- Radix: adiado conscientemente apesar do roadmap mencionar primitives.
- lint-staged em backend: fora; backend continua via `make lint` no container.

---

## Specific References

- Mantenedor confirmou: `1A`, lista de pacotes + Tailwind, `3B`, `4A`.
- Adendo explícito: “Instalar e configurar ESLint + Prettier + Husky + lint-staged”.

---

## Deferred Ideas

- Radix Primitives (roadmap visual).
- Playwright / axe gate (fatia `e2e-security-gate`).
- Sessão BFF / CSRF / Auth pages (fatias 2–8).
- Extender Husky para Pint/Pest no backend.
