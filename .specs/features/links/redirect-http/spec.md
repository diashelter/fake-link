# Links — Superfície HTTP do redirect

**Status:** Seed — 2026-08-29  
**Fatia:** 10 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-90 … LNK-98  
**Depende de:** [resolution-contract](../resolution-contract/spec.md)

---

## Problem Statement

O host curto é a única superfície pública não autenticada do produto e o caminho mais sensível a latência e a abuso de path. Ele precisa resolver caixa e barra final, rejeitar tudo que não seja um slug canônico, ignorar query string de entrada e responder erros em HTML mínimo, sem revelar nada sobre o link ou o proprietário.

## Escopo desta fatia

- `GET /{slug}`: `302`, `Location` exato e `Cache-Control: no-store`.
- `HEAD /{slug}`: mesmos status e headers, sem corpo e sem contabilizar clique.
- `GET /`: `302` para a landing page da aplicação, sem analytics.
- `GET /robots.txt`: conteúdo estático com `User-agent: *` e `Disallow: /`.
- Parâmetro de 3 a 48 letras ASCII, números ou hífen; maiúsculas normalizadas para minúsculas.
- Uma única barra final opcional com a mesma semântica.
- Rejeição de percent-encoding no slug e de segmentos extras.
- Query string recebida ignorada no lookup e nunca anexada ao destino.
- Erros em HTML mínimo com código, mensagem e request ID no conteúdo, e `X-Request-ID` no header: `404 SLUG_NOT_FOUND`, `410 LINK_UNAVAILABLE`, `429 RATE_LIMIT_EXCEEDED`, `405 METHOD_NOT_ALLOWED`.
- Rate limit de 120 por minuto por combinação de IP e slug, além da proteção do Nginx.

## Fora de escopo

| Item | Motivo |
| --- | --- |
| Cache e `503 REDIRECT_UNAVAILABLE` | Fatia [redirect-cache](../redirect-cache/spec.md) |
| Publicação de clique | Fatia [click-publication](../click-publication/spec.md) |
| Regras de negócio do estado do link | Fatia [resolution-contract](../resolution-contract/spec.md) |
| HSTS | Rejeitado permanentemente (`docs/security.md` §12.1) |
| `noindex` nas páginas da aplicação | Responsabilidade do frontend |

## Entregas previstas

- Rotas do host curto no módulo `Redirects`, roteadas por `server_name` no Nginx.
- Middleware de normalização e validação do path, antes de qualquer consulta.
- Templates de erro mínimos, sem dado do link, destino ou proprietário.
- Rate limiter com chave HMAC de IP + slug.
- Contract tests e testes de feature de cada status.

## Regras-chave a especificar

- Tabela de métodos, caminhos e comportamentos — `docs/api.md` §5.
- A OpenAPI descreve a equivalência da barra final em prosa por não conseguir declarar dois templates portáveis — `docs/api.md` §5.
- Erros não revelam owner, destino, suspensão, bloqueio ou expiração — `docs/security.md` §8.2.
- Entradas de IP são canonicalizadas e transformadas por HMAC antes de virar chave Redis; valores brutos não aparecem em chaves, métricas ou logs — `docs/security.md` §11.
- Não há limite global por slug, para não permitir derrubar um link popular — `docs/security.md` §11.
- Orçamento total da aplicação para o redirect é de 1 segundo — `docs/architecture.md` §6.2.
- `429` inclui `Retry-After` — `docs/api.md` §8.

## Open Questions

| Questão | Impacto |
| --- | --- |
| A landing page de destino da raiz vem de configuração por ambiente? | Bloqueador de deploy sobre domínio exato |
| A normalização de caixa e barra é redirect `301` para a forma canônica ou resolução direta? | O contrato diz "resolvem"; confirmar que não há salto extra |
| O `405` cobre também `POST /{slug}` e métodos exóticos, e vem do Laravel ou do Nginx? | Cobertura de teste e responsabilidade de camada |
| Os templates de erro são Blade ou strings estáticas? | Custo por requisição no caminho crítico |
| O `429` do redirect é aplicado antes ou depois da consulta ao cache? | Custo sob abuso |

## Referências

| Documento | Uso |
| --- | --- |
| `docs/api.md` §5, §7, §8 | Contrato do host curto |
| `docs/architecture.md` §4.3, §6.2 | Papel do módulo e orçamento de tempo |
| `docs/security.md` §8.2, §11, §12 | Não vazamento, rate limiting e headers |
| `docs/testing.md` §6.4 | Casos obrigatórios de redirect |
