# Links — Idempotência

**Status:** Seed — 2026-08-29  
**Fatia:** 5 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-40 … LNK-44  
**Depende de:** [link-creation](../link-creation/spec.md)

---

## Problem Statement

Um cliente que perde a resposta de `POST /links` precisa poder repetir a chamada sem criar um segundo link nem receber um erro enganoso. A idempotência é escopada por conta, guarda um snapshot criptografado da resposta original por 24 horas e distingue repetição exata de reuso indevido da chave.

## Escopo desta fatia

- Header `Idempotency-Key` opcional, de 16 a 128 caracteres, no charset `[A-Za-z0-9._:-]+`.
- Tabela `idempotency_keys` com unicidade `(user_id, key_hash)`.
- `key_hash` como HMAC do valor recebido — a chave bruta nunca é persistida.
- `request_fingerprint` como HMAC do comando normalizado (operação, rota e payload canônico), com chave de aplicação separada.
- `response_snapshot` cifrado, contendo status, headers originais e bytes do corpo original, com `key_id` do keyring exclusivo de idempotência.
- Replay exato dentro de 24 horas: mesmo status, headers e corpo, mesmo que o recurso tenha mudado.
- `409 IDEMPOTENCY_KEY_REUSED` quando a mesma chave chega com fingerprint diferente.
- Expiração exata em 24 horas e limpeza por scheduler.

## Fora de escopo

| Item | Motivo |
| --- | --- |
| Idempotência em `PATCH` | O contrato usa `If-Match` — fatia [link-update](../link-update/spec.md) |
| Reconstrução da resposta a partir do estado atual do recurso | Proibido por `docs/data-model.md` §7 |
| Chaves de integração e API keys | Pós-MVP |

## Entregas previstas

- Migration de `idempotency_keys` e Model/repositório correspondente.
- Middleware ou decorator de UseCase que reserva a chave e conclui o comando na mesma unidade transacional.
- Serviço de canonicalização de payload para o fingerprint.
- Job/comando de scheduler para remoção de registros expirados.

## Regras-chave a especificar

- Formato, tamanho e opcionalidade do header — `docs/api.md` §4.4.
- Campos, unicidade e semântica de `idempotency_keys` — `docs/data-model.md` §7.
- A primeira execução reserva a chave e conclui o comando na unidade transacional da Action — `docs/data-model.md` §7.
- Repetição exata reproduz status, headers e corpo originais sem criar novo link — `docs/testing.md` §6.3.
- Fingerprint usa HMAC com chave separada e representação canônica, não hash sem chave — `docs/data-model.md` §7.
- Material de replay é criptografado com keyring próprio — `docs/testing.md` §6.3, `docs/security.md` §14.
- Idempotência concorrente é caso obrigatório das suítes de concorrência — `docs/testing.md` §7.

## Open Questions

| Questão | Impacto |
| --- | --- |
| Qual a canonicalização exata do payload (ordem de chaves, tratamento de nulos, alias já normalizado)? | Define quando duas chamadas são "o mesmo comando" |
| Duas requisições concorrentes com a mesma chave: a segunda espera, recebe `409` ou um código de "em progresso"? | Contrato e experiência do cliente |
| Quais headers entram no snapshot (`Location`, `ETag`, request ID)? | Fidelidade do replay |
| Uma requisição que falhou com `422` grava a chave? | Reuso legítimo após corrigir o payload |
| A limpeza é por scheduler dedicado ou aproveita um comando existente? | Operação e runbook |

## Referências

| Documento | Uso |
| --- | --- |
| `docs/api.md` §4.4, §7 | Contrato do header e erros |
| `docs/data-model.md` §7 | `idempotency_keys` |
| `docs/security.md` §14 | Keyrings e rotação |
| `docs/testing.md` §6.3, §7 | Casos obrigatórios e concorrência |
