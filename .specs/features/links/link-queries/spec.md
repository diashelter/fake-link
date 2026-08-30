# Links — Consultas de link

**Status:** Seed — 2026-08-29  
**Fatia:** 6 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-50 … LNK-56  
**Depende de:** [link-creation](../link-creation/spec.md)

---

## Problem Statement

O proprietário precisa listar, pesquisar e abrir seus links. A listagem usa cursor assinado com ordem determinística, o detalhe entrega o `ETag` que habilita a edição concorrente da próxima fatia, e o estado efetivo do link é derivado — nunca lido de uma coluna.

## Escopo desta fatia

- `GET /api/v1/links`: cursor opaco, ordem fixa da criação mais recente para a mais antiga, desempate estável por ID.
- `per_page` com padrão 20, mínimo 1 e máximo 100; `meta` contém somente `next_cursor` e `per_page`.
- `search` de 2 a 160 caracteres: substring no título e prefixo no slug, sem diferenciar caixa e diferenciando acentos.
- `status`: `active`, `inactive`, `expired`, `blocked` ou `all`, com padrão `all`.
- `422 INVALID_CURSOR` para cursor inválido.
- `GET /api/v1/links/{link}`: `LinkDetail` com `destination_url` decifrado e `ETag` em header.
- Derivação do estado efetivo com a precedência `blocked` > `expired` > `inactive` > `active`.
- Ownership por policy, com `404` uniforme para link de outro proprietário.
- Rate limit de leituras privadas: 300 por minuto por token.

## Fora de escopo

| Item | Motivo |
| --- | --- |
| Histórico de destinos | Fatia [destination-history](../destination-history/spec.md) |
| Edição e pré-condições | Fatia [link-update](../link-update/spec.md) |
| Métricas de clique no resumo | Fase 3 |
| Busca por URL de destino | Não suportada (`docs/data-model.md` §4) |

## Entregas previstas

- Rotas, Controllers finos, UseCases de listagem e detalhe, Resources `LinkSummary` e `LinkDetail`.
- Codificação e assinatura do cursor, com validação de integridade.
- Índices de suporte a ordenação, prefixo de slug e busca de título.
- Contract tests dos dois endpoints.

## Regras-chave a especificar

- Parâmetros, limites e conteúdo de `meta` — `docs/api.md` §4.3.
- Campos exatos de `LinkSummary` e `LinkDetail`; o resumo não contém destino, `ETag` nem versão interna — `docs/api.md` §4.2.
- Comparação de slug e título não diferencia caixa, mas diferencia acentos; não há remoção de diacríticos — `docs/data-model.md` §9.
- Prefixo de slug consulta o valor já persistido em minúsculas e usa índice apropriado — `docs/data-model.md` §9.
- Precedência do estado efetivo e sua derivação — `docs/data-model.md` §4, `docs/api.md` §4.2.
- `404` uniforme de ownership, sem revelar existência — `docs/security.md` §7.
- Leituras privadas limitadas a 300/min por token — `docs/api.md` §8.

## Open Questions

| Questão | Impacto |
| --- | --- |
| O cursor é assinado por HMAC com qual chave e o que ele carrega (`created_at` + `id`)? | Estabilidade e resistência a adulteração |
| `status=expired` é avaliado por `now()` na query ou em memória após a leitura? | Consistência da paginação sob o passar do tempo |
| O `ETag` do detalhe é calculado pelo mesmo componente usado na criação? | Reuso e coerência com a fatia 7 |
| A decifra do destino na listagem é evitada por completo (o resumo não o expõe)? | Custo e superfície de exposição |
| A extensão `pg_trgm` e o índice GIN da busca de título entram nesta fatia ou na fundação? | Ordem de migrations e gate de desempenho |
| `search` casa slug e título com `OR`, e o mínimo de 2 caracteres é medido antes ou depois da normalização? | Precisão da validação e dos testes |

## Referências

| Documento | Uso |
| --- | --- |
| `docs/api.md` §4.2, §4.3, §7, §8 | Contrato de listagem e detalhe |
| `docs/data-model.md` §4, §9 | Estado efetivo, busca e índices |
| `docs/security.md` §7 | Autorização e `404` uniforme |
| `docs/testing.md` §6.3 | Casos obrigatórios |
