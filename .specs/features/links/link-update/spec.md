# Links — Atualização de link

**Status:** Seed — 2026-08-29  
**Fatia:** 7 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-60 … LNK-66  
**Depende de:** [link-queries](../link-queries/spec.md)

---

## Problem Statement

Editar um link é a operação mais sujeita a perda de dados por concorrência: duas abas podem trocar o destino ao mesmo tempo. O contrato exige `If-Match` obrigatório, transição de versão de destino na mesma transação e um no-op que realmente não muda nada — nem `updated_at`, nem histórico, nem `ETag`.

## Escopo desta fatia

- `PATCH /api/v1/links/{link}` aceitando um ou mais entre `destination_url`, `title`, `is_enabled` e `expires_at`.
- `If-Match` obrigatório: ausência devolve `428 IF_MATCH_REQUIRED`; valor obsoleto devolve `412 ETAG_MISMATCH`.
- Troca de destino encerra a vigência da versão corrente e cria uma nova na mesma transação, com lock explícito.
- Alteração efetiva incrementa `version` e produz novo `ETag`.
- No-op semântico: não altera `updated_at`, não cria histórico, devolve o mesmo `ETag` e não invalida cache sem necessidade.
- Link bloqueado continua editável; nenhum campo público limpa `blocked_at` nem torna o redirect disponível.
- Rate limit de escritas privadas: 120 por minuto por conta.

## Fora de escopo

| Item | Motivo |
| --- | --- |
| Alteração de `slug` | Imutável (`docs/api.md` §4.1) |
| `DELETE` de link | Não existe endpoint |
| Bloqueio e desbloqueio administrativos | Fase 4 (`Operations`) |
| Invalidação do cache de redirect | Fatia [redirect-cache](../redirect-cache/spec.md) — aqui só o gancho pós-commit |

## Entregas previstas

- Rota, `FormRequest`, Controller fino, UseCase de atualização e transição de versão de destino.
- Comparador semântico que decide entre alteração efetiva e no-op.
- Emissão do evento interno pós-commit consumido depois pela invalidação de cache.
- Contract test do endpoint, incluindo `412` e `428`.

## Regras-chave a especificar

- Campos mutáveis e obrigatoriedade de `If-Match` — `docs/api.md` §4.5.
- `ETag` opaco derivado também do estado efetivo, para que bloqueio ou mudança temporal invalide pré-condições — `docs/api.md` §4.5.
- Índice parcial único garante uma única versão corrente; locks explícitos são a primeira defesa — `docs/data-model.md` §4.
- Duas atualizações concorrentes não perdem dados; somente uma confirma com o mesmo `ETag` — `docs/testing.md` §6.3, §7.
- Bloqueio operacional mantém o status efetivo `blocked` apesar da intenção do proprietário — `docs/data-model.md` §4, `docs/testing.md` §6.3.
- Destino é revalidado pela política completa antes de cada nova versão — `docs/security.md` §8.1.
- O commit publica o evento interno de invalidação síncrona best-effort — `docs/architecture.md` §6.1.

## Open Questions

| Questão | Impacto |
| --- | --- |
| O que conta como no-op quando o cliente envia `destination_url` textualmente diferente mas com a mesma normalização? | Definição do comparador e do histórico |
| Um `PATCH` que só muda `expires_at` para o passado devolve qual `ETag`, já refletindo `expired`? | Interação entre `ETag` e tempo |
| `expires_at` pode ser definido no passado, ou só no futuro como na criação? | Contrato e casos de borda |
| Enviar `{}` ou nenhum campo mutável é `422` ou no-op? | Precisão da validação |
| O evento pós-commit é `Event` do Laravel ou chamada direta a uma porta? | Limites entre `Links` e `Redirects` |

## Referências

| Documento | Uso |
| --- | --- |
| `docs/api.md` §4.5, §7, §8 | Contrato de atualização e concorrência |
| `docs/data-model.md` §4 | Versões de destino, locks e estado efetivo |
| `docs/architecture.md` §6.1 | Fluxo de alteração e invalidação |
| `docs/testing.md` §6.3, §7 | Casos obrigatórios e concorrência |
| `docs/security.md` §8.1 | Revalidação do destino |
