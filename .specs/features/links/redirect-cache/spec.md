# Links — Cache de redirect

**Status:** Seed — 2026-08-29  
**Fatia:** 11 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-100 … LNK-106  
**Depende de:** [redirect-http](../redirect-http/spec.md)

---

## Problem Statement

O redirect precisa sustentar a referência de 1 milhão de acessos por dia sem consultar o PostgreSQL a cada requisição, e continuar correto quando Redis ou PostgreSQL falham. O cache guarda um payload mínimo cifrado, com TTL curto e jitter, e trata ciphertext corrompido como corrupção — nunca como um miss silencioso confiável.

## Escopo desta fatia

- Snapshot cifrado AES-256-GCM no Redis efêmero, com keyring dedicado e envelope versionado (chave, nonce, tag).
- Payload mínimo: somente o necessário para produzir status e `Location`; sem analytics, owner, histórico ou dados de API.
- Cache positivo com TTL base de 5 minutos e cache negativo (ausente ou indisponível) com 30 segundos.
- Jitter uniforme de ±10%; TTL nunca ultrapassa a expiração do link.
- Invalidação em listener síncrono best-effort após o commit da alteração.
- Cache corrompido, versão desconhecida ou chave ausente: métrica, remoção best-effort e fallback para PostgreSQL.
- Degradação: Redis indisponível força PostgreSQL; hit válido e decifrável atende durante falha do PostgreSQL até o TTL.
- `503 REDIRECT_UNAVAILABLE` em miss sem fonte de verdade e em falha persistente de decifra.

## Fora de escopo

| Item | Motivo |
| --- | --- |
| `stale-while-revalidate` e distributed lock | Rejeitados no desenho (`docs/architecture.md` §7) |
| Keyring de destinos no PostgreSQL | Keyring distinto — fatia [destination-policy](../destination-policy/spec.md) |
| Redis de fila | Fatia [click-publication](../click-publication/spec.md) |
| Cache de respostas da API privada | Não previsto |

## Entregas previstas

- Adaptador de cache no módulo `Redirects` com cifra própria e chaves derivadas do slug canônico.
- Listener de invalidação assinando o evento pós-commit emitido em [link-update](../link-update/spec.md).
- Métricas OTel de hit, miss, corrupção, fallback e `503`, sem rótulo de alta cardinalidade.
- Testes de resiliência com Redis lento, indisponível, reiniciado e esvaziado.

## Regras-chave a especificar

- Tabela de TTLs por resultado e regra de limite pela expiração — `docs/architecture.md` §7.
- Falha de autenticação GCM é corrupção, nunca cache miss confiável silencioso — `docs/architecture.md` §7, `docs/security.md` §9.
- Um hit decifrável pode atender durante falha do PostgreSQL somente até seu TTL — `docs/security.md` §9.
- Miss com PostgreSQL indisponível retorna `503` sem revelar detalhes — `docs/api.md` §5, `docs/testing.md` §6.4.
- Invalidação ocorre após commit e o TTL limita a falha do listener — `docs/security.md` §9, `docs/architecture.md` §6.1.
- No-op de atualização não invalida cache sem necessidade — `docs/testing.md` §6.3.
- Casos obrigatórios de resiliência de Redis e PostgreSQL — `docs/testing.md` §7.

## Open Questions

| Questão | Impacto |
| --- | --- |
| A chave Redis usa o slug em claro ou um HMAC do slug? | Vazamento por inspeção do Redis vs. custo |
| O cache negativo distingue "ausente" de "indisponível" no payload cifrado? | Precisão do status servido a partir do cache |
| Rotação do keyring de cache aceita chave anterior por quanto tempo? | Janela de rotação e runbook |
| A repopulação após um miss é síncrona no caminho da resposta ou after-response? | Orçamento de 1 segundo |
| A invalidação apaga a chave ou grava um negativo curto? | Efeito de rebanho após edição de link popular |

## Referências

| Documento | Uso |
| --- | --- |
| `docs/architecture.md` §6.2, §7 | Fluxo de redirect e política de cache |
| `docs/security.md` §9, §14 | Cifra do snapshot, keyring e rotação |
| `docs/api.md` §5 | `503 REDIRECT_UNAVAILABLE` |
| `docs/testing.md` §6.4, §7 | Casos obrigatórios e modos de falha |
