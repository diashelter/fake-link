# BFF Auth — Núcleo de sessão

**Status:** Seed — deepen before Design/Tasks/Execute  
**Fatia:** 2 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-10 … BFFUI-17  
**Requirement IDs (fatia):** SC-01 … SC-12  
**Depende de:** [foundation](../foundation/spec.md)

## Problem Statement

O browser oficial não pode receber Bearer. É preciso um núcleo de sessão no Next que cifre o token, guarde estado mínimo no Redis efêmero e exponha apenas um ID opaco via cookie seguro — sem ainda expor handlers de login/logout de produto.

## Goals

- [ ] Biblioteca/serviço de sessão: gerar ID 256-bit, cifrar Bearer com AES-256-GCM (chave fora do Redis), envelope versionado com key id.
- [ ] Persistência Redis: chave = `HMAC(session_id)`; valor = estado mínimo + ciphertext; ID bruto não pesquisável.
- [ ] Cookie `__Host-` (quando aplicável): `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`, sem `Domain`.
- [ ] TTL absoluto e idle alinhados ao kind: session 7d/24h; verification 24h/1h.
- [ ] Rotação de ID em pontos sensíveis (API pública para fatias seguintes).
- [ ] Miss/falha de decrypt/flush Redis → sessão inválida; cookie limpo; **sem** devolver Bearer ao cliente.
- [ ] Vitest cobre crypto, HMAC lookup, TTL/idle e ausência de plaintext em serialização.

## Out of Scope

| Item | Motivo |
| --- | --- |
| CSRF / Origin / allowlist de rotas | Fatia `csrf-proxy` |
| Handlers login/register/logout | Fatias 4–8 |
| UI | Fatias 4–8 |
| Playwright browser suite | Fatia `e2e-security-gate` |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Redis target | `redis-ephemeral` (já no compose) | `docs/architecture.md` / security §5 | y |
| Chaves AES vs HMAC | Materiais distintos; nunca compartilhar com rate-limit HMAC da API | `docs/security.md` §5.1, §14 | y |
| Nome do cookie | Prefixo `__Host-` + nome curto a fechar no deepen (ex.: `__Host-fl_session`) | Spec exige prefixo; nome exato ainda aberto | n — deepen |
| Nonce GCM | Aleatório por escrita; nunca reutilizar com mesma chave | Security §5.1 | y |
| Rotação de chave BFF | Encerra todas as sessões; operação comunicável | Security §5.2 | y |
| Extensão de cookie helper | Reutilizar `frontend/lib/session-cookie.ts` como base | Já força Secure/HttpOnly/SameSite | y |
| Probe de teste | Permitir Route Handler de probe **somente** em test/dev se necessário | Espelha probes Auth API | n — deepen |

**Open questions:** nome final do cookie; formato exato do valor Redis; onde vivem env vars de chave (`BFF_SESSION_*`); idle touch throttle espelhando `last_used_at` 15min ou a cada request.

---

## Implicit-Requirement Dimensions (seed)

| Dimension | Resolução preliminar |
| --- | --- |
| Input validation & bounds | Session id com entropia fixa; rejeitar cookie malformado sem lookup Redis |
| Failure / partial-failure | Decrypt fail / Redis down / miss → sessão morta + clear cookie |
| Idempotency / retry | Create session é única por emissão; rotate gera novo id |
| Auth boundaries | Sem HTTP de produto; APIs internas usadas por csrf-proxy |
| Concurrency | Duas escritas no mesmo id: last-write-wins ou rotate — fechar no deepen |
| Data lifecycle | TTL Redis ≤ TTL absoluto do kind; idle corta acesso |
| Observability | Sem Bearer/session plaintext em logs; métrica de decrypt fail |
| External-dependency failure | Redis indisponível = logout/invalid session |
| State-transition | Kind `verification` vs `session` carrega TTLs distintos |

---

## User Stories

### P1: Emitir e validar sessão BFF ⭐ MVP

**Acceptance Criteria (seed):**

1. WHEN uma sessão é criada com Bearer e kind THEN o Redis SHALL armazenar somente ciphertext + metadados mínimos sob chave HMAC, e o cookie SHALL carregar só o ID opaco.
2. WHEN o cookie válido é apresentado e Redis tem entrada válida THEN o serviço SHALL recuperar o Bearer **somente em memória server-side**.
3. WHEN idle ou absoluto expira OR decrypt falha OR Redis miss THEN o sistema SHALL invalidar a sessão e limpar o cookie, sem expor Bearer.
4. WHEN o ID é rotacionado THEN o id antigo SHALL deixar de resolver e o novo cookie SHALL ser emitido.

**Independent Test:** Vitest com Redis fake/test container; asserts de ausência de Bearer em estruturas serializáveis.

---

## Deepen checklist

- [ ] Fechar nome do cookie, schema Redis, env keys
- [ ] Definir idle touch policy
- [ ] ACs precisos + sensor de mutação mental (Bearer leak)
- [ ] Status → Approved

## Referências

- `docs/security.md` §5.1–5.2  
- `docs/architecture.md` §8  
- `docs/testing.md` §6.2  
- `frontend/lib/session-cookie.ts`
