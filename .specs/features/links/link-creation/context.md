# Links — Criação de link · Context

**Gathered:** 2026-08-30  
**Spec:** [spec.md](./spec.md)  
**Status:** Ready for design

---

## Feature Boundary

`POST /api/v1/links` autenticado por token `session`: payload fechado de quatro campos, transação única (reserva de slug + link + primeira versão de destino), resposta `201` com `Location`, `ETag` forte e `LinkDetail`, `409 ALIAS_UNAVAILABLE`, `503 SLUG_GENERATION_FAILED` e rate limit de 60/min por conta.

Não entra nesta fatia: idempotência, listagem, detalhe, histórico, edição, `If-Match`, redirect, cache e analytics. As políticas internas de slug e de destino são consumidas das fatias 2 e 3, não redefinidas aqui.

---

## Implementation Decisions

### Montagem do `short_url`

- Nova chave `links.short_url.base_url` em `backend/config/links.php`, alimentada por uma env dedicada `SHORT_URL_BASE` (ex.: `https://go.localhost`), com fallback derivado de `SHORT_HOST` + esquema `https`.
- O Resource monta `{base_url}/{slug}`.
- O host que atendeu à requisição **nunca** é usado como base — host da aplicação e host curto são servidores distintos, e chamadas server-to-server produziriam URL errada.
- Consequência aceita: mais uma variável de ambiente a validar em `docker/scripts/validate-env.sh`.

### Contagem do rate limit de criação

- O limitador incrementa **antes** da validação, aplicado como middleware de rota — mesmo padrão dos `Throttle*` do módulo Auth.
- Requisições que terminam em `422`, `409` ou `503` consomem tentativa: payload inválido em laço também é abuso.
- Requisições sem Bearer válido não consomem cota de conta alguma — o limite roda depois de `auth.bearer` e `token.kind:session`.
- Consequência aceita: um cliente com bug de integração pode se auto-bloquear por um minuto; é o comportamento desejado.

### Composição do `ETag`

- `HMAC-SHA256` com chave de aplicação sobre a tupla canônica: `id`, `slug`, `destination_url` normalizada, `title`, `is_enabled`, `expires_at`, `blocked_at`, `updated_at` e `status` efetivo derivado.
- Serializado como ETag **forte**: `"<hex>"` — sem prefixo `W/`, casando `^"[^"]+"$`.
- Incluir o `status` efetivo é o que faz bloqueio administrativo e passagem da expiração invalidarem pré-condição sem expor `version`.
- A chave HMAC impede que o cliente reconstrua o valor a partir do corpo da resposta.
- Consequência aceita: rotacionar a chave HMAC invalida todos os `ETag` em circulação — pré-condições obsoletas retornam `412` na fatia 7, que é a falha correta e segura.

### Agent's Discretion

Áreas resolvidas pelo agente e registradas na tabela de Assumptions da spec, sem consulta por serem consequência direta da documentação existente ou do precedente do módulo Auth:

- `Idempotency-Key` aceito e ignorado nesta fatia (semântica completa é da fatia 5).
- `title` com trim, `""` → `null`, limite de 160 medido em caracteres.
- `expires_at` somente ISO 8601 com sufixo `Z` e estritamente futuro.
- `custom_alias: null` explícito é inválido (≠ ausente).
- Campo desconhecido → `422` com código `UNKNOWN_FIELD`.
- Precedência `422` antes de `409` para não enumerar o namespace.
- `Location` como caminho relativo `/api/v1/links/{id}`.
- Fail-open com métrica quando o Redis do rate limit estiver indisponível.

### Declined / Undiscussed Gray Areas → Assumptions

Nenhuma área foi declinada. As quatro Open Questions do seed foram fechadas: três por decisão do mantenedor (acima) e a quarta — erro de exaustão de geração de slug — já estava resolvida na spec de [slug-policy](../slug-policy/spec.md) como `503 SLUG_GENERATION_FAILED` com `Retry-After`.

---

## Specific References

- Precedente direto: `.specs/features/auth/session-and-profile/spec.md` — rota privada com `auth.bearer` → `token.kind` → `throttle.*`, Controller fino, Resource dedicado e casos de fronteira de autenticação provados no próprio caminho.
- Precedente de política com códigos estáveis e configuração versionada: [slug-policy](../slug-policy/spec.md).
- Contrato design-first: `docs/openapi.yaml` (`CreateLinkRequest`, `LinkCreated`, `LinkDetail`, `LinkConflict`) — o endpoint se conforma ao contrato existente, não o contrário; a única alteração prevista é documentar `SLUG_GENERATION_FAILED`.

---

## Deferred Ideas

- **Sugestão de alias alternativo** quando o pedido está indisponível — já rejeitado em [slug-policy](../slug-policy/spec.md) por enumerar o namespace.
- **`request_id` real por requisição** — hoje todas as respostas do projeto usam o literal `stub-request-id` (`app/Http/Responses/ApiResponse.php`, `AuthResponseFactory`). É uma lacuna transversal, não desta fatia; ver Risks & Concerns em [design.md](./design.md).
- **Códigos de erro por motivo de rejeição do destino** — decisão aberta da fatia [destination-policy](../destination-policy/spec.md); até lá o endpoint emite `INVALID_DESTINATION_URL` genérico.
- **Invalidação do cache negativo do slug recém-criado** — só passa a existir com a fatia [redirect-cache](../redirect-cache/spec.md).
