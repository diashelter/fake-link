# Links — Histórico de destinos

**Status:** Seed — 2026-08-29  
**Fatia:** 8 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-70 … LNK-72  
**Depende de:** [link-update](../link-update/spec.md)

---

## Problem Statement

Toda troca de destino precisa ser auditável pelo proprietário. O histórico é imutável, criptografado e ordenado, e expõe a vigência de cada versão sem revelar número de versão interno.

## Escopo desta fatia

- `GET /api/v1/links/{link}/history` com cursor, do registro mais recente para o mais antigo.
- Cada item contém `destination_url` decifrado, `valid_from` e `valid_to`; `valid_to = null` identifica o destino vigente.
- Ausência de número de versão público.
- Imutabilidade: nenhuma rota altera ou remove uma versão passada.
- Ownership com `404` uniforme e rate limit de leituras privadas (300/min por token).

## Fora de escopo

| Item | Motivo |
| --- | --- |
| Criação de novas versões | Fatias [link-creation](../link-creation/spec.md) e [link-update](../link-update/spec.md) |
| Reverter para uma versão anterior | Não previsto no MVP |
| Exports do histórico | Pós-MVP |

## Entregas previstas

- Rota, Controller fino, UseCase de listagem de histórico e Resource do item.
- Reuso da codificação de cursor da fatia [link-queries](../link-queries/spec.md).
- Contract test do endpoint.

## Regras-chave a especificar

- Cursor, ordem e conteúdo do item — `docs/api.md` §4.3.
- Índice `(short_link_id, valid_from DESC)` para leitura do histórico — `docs/data-model.md` §4.
- `CHECK (valid_to IS NULL OR valid_to > valid_from)` e índice parcial único da versão corrente — `docs/data-model.md` §4.
- Histórico é imutável e preserva a ordem temporal — `docs/testing.md` §6.3.
- Destino e histórico são decifrados somente pelos caminhos autorizados — `docs/testing.md` §6.3, `docs/security.md` §8.1.
- Link bloqueado mantém histórico privado visível ao proprietário — `docs/testing.md` §6.3.

## Open Questions

| Questão | Impacto |
| --- | --- |
| O cursor do histórico reusa exatamente o formato da listagem de links? | Reuso de componente e testes |
| Falha de decifra de uma versão antiga degrada o item, omite ou devolve erro? | Contrato e resiliência |
| Há `per_page` próprio ou vale o mesmo padrão 20 / máximo 100? | Contrato `docs/api.md` §4.3 |

## Referências

| Documento | Uso |
| --- | --- |
| `docs/api.md` §4.1, §4.3 | Contrato do histórico |
| `docs/data-model.md` §4 | `link_destination_versions`, índices e constraints |
| `docs/testing.md` §6.3 | Casos obrigatórios |
| `docs/security.md` §8.1 | Criptografia de histórico |
