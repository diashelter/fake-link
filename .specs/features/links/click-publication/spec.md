# Links — Publicação de clique

**Status:** Seed — 2026-08-29  
**Fatia:** 12 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-110 … LNK-113  
**Depende de:** [redirect-http](../redirect-http/spec.md)

---

## Problem Statement

A Fase 2 precisa deixar o produtor de eventos de clique pronto sem que o redirect passe a depender do Redis de fila ou de um worker. A publicação acontece depois da resposta, é best-effort, e o payload já sai sanitizado — o consumo, os agregados e as consultas ficam para a Fase 3.

## Escopo desta fatia

- `Analytics::recordClick` chamado after-response, somente para `GET` de redirect válido.
- Classificação de tráfego (`human`, `bot`, `preview`, `unknown`) e categoria ampla de device a partir do contexto em memória.
- Canonicalização do IP e cálculo do identificador diário de unicidade por HMAC escopado por link, para tráfego humano.
- Descarte de IP e user-agent brutos antes de enfileirar; referenciador reduzido a hostname público normalizado ou nulo.
- Publicação no Redis de fila com `event_id` e `occurred_at` estáveis entre retries.
- Independência: worker parado, fila indisponível ou perda aceita do evento não alteram um redirect já válido.
- Métricas de falha de publicação, sem dados brutos e sem rótulo de alta cardinalidade.

## Fora de escopo

| Item | Motivo |
| --- | --- |
| Worker, tabelas de eventos, agregados e partições | Fase 3 |
| Consultas e dashboard de analytics | Fase 3 |
| Deduplicação diária persistida | Fase 3 |
| `HEAD` e raiz | Não geram clique (`docs/api.md` §5) |

## Entregas previstas

- Contrato profundo de analytics consumido por `Redirects` em modo best-effort.
- Produtor com classificação, sanitização e HMAC diário, dentro do módulo `Analytics`.
- Payload de fila versionado e documentado.
- Testes que provam ausência de dado bruto e independência do redirect.

## Regras-chave a especificar

- A publicação ocorre após enviar a resposta e entrega contexto efêmero ao contrato profundo — `docs/architecture.md` §4.3, §6.2, §6.3.
- Fórmula e escopo do `visitor_hash` diário — `docs/data-model.md` §5.
- O evento nunca contém IP bruto, user-agent bruto, URL completa do referenciador, destino ou parâmetros sensíveis — `docs/data-model.md` §5.
- A perda de publicação após a resposta é aceita, medida e alertada; Redis counters foram rejeitados — `docs/architecture.md` §6.3.
- Falhas de analytics são contabilizadas por métricas sem registrar dados proibidos — `docs/testing.md` §6.4.
- Rotulagem de telemetria sem alta cardinalidade proibida — `docs/security.md` §13.

## Open Questions

| Questão | Impacto |
| --- | --- |
| Quanto do produtor entra na Fase 2 e quanto fica para a Fase 3 (classificação completa vs. payload mínimo)? | Escopo real desta fatia |
| O DeviceDetector local já entra aqui, com seu custo por requisição? | Orçamento de 1 segundo do redirect |
| A chave HMAC diária é rotacionada por scheduler ou derivada da data? | Operação e testes determinísticos |
| Sem worker na Fase 2, os eventos publicados apenas se acumulam e expiram? | Configuração da fila e limpeza |
| `after-response` usa `terminate()` do Laravel ou um mecanismo próprio? | Garantia de execução sob FPM |

## Referências

| Documento | Uso |
| --- | --- |
| `docs/architecture.md` §4.3, §4.4, §6.2, §6.3, §9 | Fluxo after-response e filas |
| `docs/data-model.md` §5 | Payload do evento e `visitor_hash` |
| `docs/security.md` §10, §13 | Privacidade de analytics e telemetria |
| `docs/testing.md` §6.4, §6.7 | Casos obrigatórios e privacidade |
| `docs/roadmap.md` | Limite entre Fase 2 e Fase 3 |
