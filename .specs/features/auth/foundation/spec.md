# Auth — Fundação do módulo

**Status:** Rascunho  
**Fatia:** 1 de 7 — ver [índice](../README.md)  
**Requirement IDs:** AUTH-06, AUTH-07, AUTH-08

## Problem Statement

Antes de expor endpoints HTTP, o módulo `Auth` precisa existir como unidade hexagonal registrada no Laravel, com domínio compartilhado, política de senha e persistência inicial de `users`. Sem essa base, fatias seguintes (tokens, registro, login) não têm onde ancorar regras nem migrations.

## Goals

- [ ] Estrutura `backend/modules/Auth/` criada conforme `LARAVEL_CODE_DESIGN.md`.
- [ ] Migration `users` com campos, constraints e enums alinhados a `docs/data-model.md` §3.
- [ ] Domínio compartilhado: `User` (entity ou aggregate mínimo), `UserStatus`, normalização de e-mail.
- [ ] Política de senha validável e hash Argon2id encapsulados (sem vazar regra para Form Requests).
- [ ] Service provider registra bindings, rotas vazias ou prefixo preparado, sem endpoints de negócio ainda.
- [ ] Testes Pest cobrem política de senha, normalização de e-mail e persistência básica de `User`.

## Out of Scope

| Item | Motivo |
| --- | --- |
| `auth_tokens`, `email_action_tokens` | Fatias `bearer-tokens` e `email-verification` |
| Endpoints HTTP de Auth | Fatias 3–7 |
| Allowlist de convite | Fatia `registration` |
| Jobs Resend | Fatias `email-verification` e `password` |
| Rate limiting | Cada fatia de endpoint |

---

## Entregáveis

### Estrutura de pastas

```txt
backend/modules/Auth/
  Domain/
    Entities/
    Enums/
    ValueObjects/
    Exceptions/
  UseCases/
  Contracts/
  DTOs/
  Infrastructure/
    Persistence/
    ServiceProviders/
  Tests/
    Unit/
    Feature/
```

Ajustes finos de subpastas HTTP entram nas fatias que expõem rotas.

### Migration `users`

| Campo | Regra |
| --- | --- |
| `id` | ULID PK |
| `name` | varchar(120), obrigatório |
| `email` | varchar(254), único, normalizado minúsculas |
| `password` | hash Argon2id |
| `status` | `pending_verification`, `active`, `suspended`, `deletion_pending` |
| `email_verified_at` | timestamptz nullable |
| `terms_version` | varchar |
| `terms_accepted_at` | timestamptz |
| `created_at`, `updated_at` | UTC |

E-mail imutável após criação (regra de domínio documentada; enforcement completo na fatia `session-and-profile`).

### Domínio

- **`UserStatus`** enum com os quatro estados públicos.
- **`EmailAddress`** ou normalizador: trim, minúsculas, validação sintática.
- **`Password` / `PasswordPolicy`**: comprimento 12–128; minúscula, maiúscula, dígito e símbolo ASCII; falhas com códigos estáveis para camada HTTP mapear depois.
- **`PasswordHasher` port** + adaptador Argon2id (parâmetros via config; alvo ~250 ms documentado).

### Infraestrutura

- **`AuthServiceProvider`**: registra provider no bootstrap Laravel.
- **Repository port** `UserRepository` + implementação Eloquent em `Infrastructure/Persistence`.
- Autoload PSR-4 `Modules\Auth\` → `modules/Auth/` (confirmar em `composer.json`).

---

## User Stories

### P1: Scaffold e registro do módulo ⭐ MVP

**Como** mantenedor, **quero** o módulo Auth registrado no Laravel **para** implementar fatias seguintes sem retrabalho estrutural.

**Acceptance Criteria:**

1. WHEN a aplicação boota THEN o namespace `Modules\Auth` SHALL autoloadar e o service provider SHALL estar registrado.
2. WHEN Pest Arch roda THEN regras modulares SHALL aplicar a `Modules\Auth\*` conforme gates existentes.
3. WHEN não há rotas de negócio Auth THEN health da API SHALL continuar passando.

---

### P1: Migration e persistência de User ⭐ MVP

**Como** desenvolvedor, **quero** a tabela `users` **para** persistir contas nas fatias de registro e login.

**Acceptance Criteria:**

1. WHEN `php artisan migrate` roda no container THEN a tabela `users` SHALL existir com constraints de `status` e unicidade de `email`.
2. WHEN um `User` é salvo com e-mail `Foo@Bar.com` THEN o valor persistido SHALL ser normalizado para minúsculas.
3. WHEN dois registros com o mesmo e-mail normalizado são inseridos THEN a segunda operação SHALL falhar por unicidade.

---

### P1: Política de senha e hash ⭐ MVP

**Como** sistema, **quero** validar e hashear senhas de forma uniforme **para** registro, login e fluxos de senha futuros.

**Acceptance Criteria:**

1. WHEN a senha tem menos de 12 ou mais de 128 caracteres THEN a validação SHALL falhar.
2. WHEN falta minúscula, maiúscula, dígito ou símbolo ASCII THEN a validação SHALL falhar.
3. WHEN a senha é válida THEN o hash persistido SHALL ser verificável por Argon2id e SHALL NOT conter a senha em claro.
4. WHEN logs ou exceções são emitidos THEN a senha em claro SHALL NOT aparecer.

---

## Edge Cases

- E-mail com espaços externos → normalizado antes de validação/uniqueness.
- Senha no limite exato (12 e 128 caracteres) → aceita se cumprir complexidade.
- `terms_accepted_at` e `terms_version` obrigatórios na criação (UseCase de registro na fatia seguinte; colunas existem desde foundation).

---

## Verificação

- `make test-backend` — testes unitários de `PasswordPolicy`, normalização de e-mail, repository.
- `make lint` — sem regressão nos gates.
- Migration aplicável em compose de teste efêmero.

---

## Referências

- [Índice Auth](../README.md)
- `docs/data-model.md` §3 (`users`)
- `docs/security.md` §4.2 (senha)
- `LARAVEL_CODE_DESIGN.md`
