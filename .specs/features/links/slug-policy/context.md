# Links — Política de slug · Contexto de decisões

**Spec**: `.specs/features/links/slug-policy/spec.md`  
**Status**: Fechado — 2026-08-30  
**Origem**: gray areas levantadas no seed da fatia e decididas com o mantenedor durante o Specify.

Este arquivo registra **por que** cada decisão foi tomada e **o que foi descartado**. Os valores resultantes estão normativos na tabela `Assumptions & Open Questions` da spec — aqui ficam as alternativas rejeitadas, para que o Design não as reabra sem motivo novo.

---

## D1 — Slug automático é validado contra a denylist

**Decisão**: sim, todo candidato gerado passa pela mesma denylist do alias antes da tentativa de reserva.

**Alternativa descartada**: dispensar a validação, porque o comprimento fixo de 8 caracteres torna a colisão com a denylist matematicamente impossível hoje (todas as palavras têm ≤7 caracteres).

**Por quê**: `docs/data-model.md` §4 afirma explicitamente que o slug automático é validado contra a denylist. Manter a regra custa uma comparação em hash set e sobrevive ao crescimento futuro da lista — se um dia entrar uma palavra de 8 caracteres, a proteção já existe. Dispensar exigiria corrigir o documento e criaria uma armadilha silenciosa.

**Consequência para o Design**: o ponto de verificação da denylist é único e compartilhado pelos dois caminhos (alias e gerado) — o serviço de política que constrói o VO `Slug`. Não há segunda implementação dentro do gerador.

---

## D2 — Contagem do orçamento de tentativas

**Decisão**: as 5 tentativas contam **inserções que falharam por colisão de chave primária**. Candidato descartado pela denylist é regenerado sem consumir esse orçamento, sob um teto próprio de 5 descartes.

**Alternativa descartada**: orçamento único de 5 gerações totais, somando colisões e descartes por denylist.

**Por quê**: o mantenedor decidiu que descarte por denylist não é colisão — são falhas de natureza diferente (uma é do namespace, outra é de política). O teto próprio de 5 descartes foi acrescentado porque a decisão isolada deixaria o laço sem limite superior; com ele, o laço é provadamente limitado a no máximo 10 gerações. Ambos os tetos produzem a mesma falha (`SlugGenerationExhausted`).

**Consequência para o Design**: dois contadores independentes no serviço de geração, ambos configuráveis, ambos terminando na mesma exceção tipada.

---

## D3 — Erro estável ao esgotar as tentativas

**Decisão**: `503` com código estável **`SLUG_GENERATION_FAILED`**, acompanhado de `Retry-After`.

**Alternativas descartadas**:
- `503 SERVICE_UNAVAILABLE` (código genérico já catalogado): zero mudança de contrato, mas indistinguível de PostgreSQL/Redis fora em observabilidade e suporte.
- `500 INTERNAL_ERROR`: trata exaustão como bug, desencoraja retry legítimo do cliente e polui alertas.

**Por quê**: a exaustão é transitória e reprocessável pelo cliente; merece código próprio para ser mensurável e para orientar retry.

**Consequência para o Design**: o `Domain` lança `SlugGenerationExhausted`; o mapeamento HTTP e a entrada na OpenAPI pertencem à fatia [link-creation](../link-creation/spec.md). O registro do código em `docs/api.md` §7 é feito nesta fatia para que o contrato não fique órfão.

---

## D4 — Local da denylist

**Decisão**: lista versionada em `backend/config/links.php` (`links.slug.reserved_words`), exposta ao `Domain` por um contrato (`ReservedSlugs`).

**Alternativa descartada**: constante dentro do VO `Slug`.

**Por quê**: precedente direto no módulo Auth (`InviteAllowlist` + `JsonFileInviteAllowlist` lendo `config('auth.invite_allowlist.path')`). Mantém o `Domain` puro (sem `config()`), testável com lista injetada, e permite que operação evolua a lista sem tocar em regra de negócio.

**Consequência para o Design**: `Slug` não pode ser um VO com construtor estático puro que consulta a denylist sozinho — a denylist chega por injeção. Ver `## Tech Decisions` no `design.md` para como isso foi resolvido sem quebrar o padrão `fromString()` do projeto.

---

## D5 — Alias com Unicode ou homoglifos

**Decisão**: rejeitar no parse. A normalização faz **apenas** trim ASCII + lowercase `A-Z`→`a-z`; qualquer code point remanescente fora de `[a-z0-9-]` falha a validação com `invalid_characters`.

**Alternativa descartada**: normalizar com NFKC e transliterar antes de validar, aceitando `ADMÍN` como `admin`.

**Por quê**: transliteração cria equivalência silenciosa entre entradas visualmente parecidas e amplia a superfície de spoofing e de colisão. Rejeitar é regra trivialmente testável, com superfície zero, e o custo para o usuário é uma mensagem de validação clara.

**Consequência para o Design**: nenhuma dependência de `intl`, `Normalizer` ou tabela de homoglifos. `strtolower` é evitado em favor de conversão ASCII explícita, para não depender de locale.

---

## Discricionário do agente (não perguntado, decidido no Specify)

Registrado aqui por transparência; detalhado na tabela de assumptions da spec.

| Item | Decisão |
| --- | --- |
| Escopo do trim | Somente extremidades, `trim` ASCII padrão; espaço interno vira `invalid_characters` |
| Comparação com denylist | Igualdade exata sobre o valor normalizado completo, nunca substring |
| Uniformidade da falha de reserva | Resultado idêntico para reserva com link e reserva órfã, sem dados do ocupante |
| Escopo transacional | O serviço de reserva participa da transação do chamador; não abre nem confirma transação própria |
