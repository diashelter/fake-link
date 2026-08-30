# Links — Contrato de resolução

**Status:** Seed — 2026-08-29  
**Fatia:** 9 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-80 … LNK-83  
**Depende de:** [link-update](../link-update/spec.md)

---

## Problem Statement

O módulo `Redirects` não pode conhecer as regras de negócio que tornam um link utilizável, e o módulo `Links` não pode conhecer HTTP, Redis ou templates. Esta fatia define a porta entre os dois: uma resolução de slug que devolve exatamente um de três resultados, mantendo todas as regras em `Links`.

## Escopo desta fatia

- Contrato de resolução por slug com três resultados possíveis: snapshot válido, ausente, ou indisponível por estado de negócio.
- Snapshot mínimo e suficiente para produzir status e `Location`, sem analytics, owner, histórico ou dados de API.
- Aplicação da precedência do estado efetivo (`blocked` > `expired` > `inactive` > `active`) na resolução.
- Distinção entre slug nunca reservado (ausente) e reserva órfã ou link indisponível.
- Independência total de HTTP, Redis, headers e templates de erro.

## Fora de escopo

| Item | Motivo |
| --- | --- |
| Status HTTP, headers e HTML de erro | Fatia [redirect-http](../redirect-http/spec.md) |
| Cache e TTL | Fatia [redirect-cache](../redirect-cache/spec.md) |
| Publicação de clique | Fatia [click-publication](../click-publication/spec.md) |

## Entregas previstas

- Interface pública em `Modules\Links\Contracts` e DTO estável do snapshot.
- UseCase de resolução com consulta única ao PostgreSQL e decifra do destino corrente.
- Testes de unidade da precedência de estado e de integração da consulta.

## Regras-chave a especificar

- O contrato de leitura devolve exatamente um dos três resultados — `docs/architecture.md` §4.2.
- Erros do redirect não podem revelar owner, destino, suspensão, bloqueio ou expiração; a causa não trafega além do necessário — `docs/security.md` §8.2.
- Reserva órfã responde como indisponível, nunca como ausente — `docs/data-model.md` §4, `docs/testing.md` §6.4.
- Comunicação entre módulos ocorre por contratos públicos e DTOs estáveis, sem import de Model Eloquent ou `Domain` alheio — `docs/architecture.md` §4.0, §5.
- Falha persistente de decifra do destino na fonte de verdade não pode produzir um `Location` incerto — `docs/security.md` §9.
- A expiração é limite exclusivo em UTC — `docs/data-model.md` §4.

## Open Questions

| Questão | Impacto |
| --- | --- |
| O snapshot carrega `expires_at` para que o cache limite o TTL, ou o cache pergunta de novo? | Acoplamento entre fatias 9 e 11 |
| O resultado "indisponível" carrega a causa internamente para métrica, mesmo sem expô-la? | Observabilidade sem vazamento |
| O snapshot inclui `short_link_id` para o payload de analytics? | Dependência da fatia 12 |
| Falha de decifra é um quarto resultado do contrato ou uma exceção? | Forma da porta e tratamento em `Redirects` |

## Referências

| Documento | Uso |
| --- | --- |
| `docs/architecture.md` §4.0, §4.2, §4.3, §5, §6.2 | Fronteira entre `Links` e `Redirects` |
| `docs/data-model.md` §4 | Estado efetivo e reserva órfã |
| `docs/security.md` §8.2, §9 | Não vazamento de causa e falha de decifra |
| `docs/testing.md` §6.4 | Casos obrigatórios de resolução |
