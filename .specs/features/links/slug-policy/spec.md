# Links — Política de slug

**Status:** Fechada — confirmada 2026-08-30  
**Fatia:** 2 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-10 … LNK-16  
**Requirement IDs (fatia):** SLG-01 … SLG-18  
**Depende de:** [foundation](../foundation/spec.md) — tabelas `slug_reservations` / `short_links` e esqueleto do VO `Slug`

---

## Problem Statement

O slug é o identificador público e permanente de um link. Ele precisa ser gerado com segurança, aceitar alias personalizado normalizado, resistir a colisão e sequestro por caixa ou concorrência, e permanecer reservado para sempre — inclusive quando o link deixa de existir. Hoje o módulo `Links` tem apenas o esqueleto do VO `Slug` da fundação, sem normalização, allowlist, denylist, gerador CSPRNG nem serviço de reserva. Sem essas regras isoladas em domínio, a fatia [link-creation](../link-creation/spec.md) teria de embutir política de identidade dentro do endpoint, e o redirect não teria semântica estável de reserva órfã.

## Goals

- [ ] VO `Slug` com normalização total (trim + lowercase ASCII) e validação completa (comprimento, allowlist, hífens, denylist).
- [ ] Gerador de slug automático Base36 minúsculo de exatamente 8 caracteres por fonte criptograficamente segura.
- [ ] Política de retentativa de colisão limitada a 5 tentativas de inserção, com o PostgreSQL decidindo a concorrência.
- [ ] Denylist versionada em `config/links.php`, consumida pelo domínio por contrato, nunca espalhada em código.
- [ ] Serviço de reserva transacional sobre `slug_reservations`: namespace global, permanente, nunca removido nem reutilizado.
- [ ] Semântica de reserva órfã (reserva sem link) consultável pelas fatias de resolução.
- [ ] Cobertura de concorrência: aliases equivalentes por caixa não se sobrescrevem nem revelam proprietário.

## Out of Scope

Explicitamente excluído. Documentado para evitar scope creep.

| Item | Motivo |
| --- | --- |
| Endpoint `POST /links`, `FormRequest` e resposta HTTP (incl. `409 ALIAS_UNAVAILABLE`) | Fatia [link-creation](../link-creation/spec.md) — esta fatia entrega o resultado de domínio que o endpoint mapeia |
| Resposta `410` do redirect para reserva órfã | Fatias [resolution-contract](../resolution-contract/spec.md) e [redirect-http](../redirect-http/spec.md) |
| Normalização de caixa e barra final no caminho `/{slug}` do host curto | Fatia [redirect-http](../redirect-http/spec.md) (LNK-94) |
| Remoção de link ou de conta que cria a reserva órfã | Fase 4 (`Operations`) |
| Criação da migration de `slug_reservations` e `short_links` | Fatia [foundation](../foundation/spec.md) — aqui só se usa o esquema |
| Rate limit de criação (60/min por conta) | Fatia [link-creation](../link-creation/spec.md) (LNK-34) |
| Idempotência de criação | Fatia [idempotency](../idempotency/spec.md) |
| Validação, normalização e cifra do destino | Fatia [destination-policy](../destination-policy/spec.md) |
| Domínios personalizados e slugs por domínio | Pós-MVP |
| Sugestão de alias alternativo quando indisponível | Não faz parte do MVP; revelaria ocupação do namespace |

---

## Assumptions & Open Questions

Toda ambiguidade está resolvida ou registrada aqui — nada fica silenciosamente indefinido.

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Slug automático é validado contra a denylist | Sim — todo candidato gerado passa pela mesma denylist do alias antes da tentativa de reserva | `docs/data-model.md` §4 exige a validação; custo desprezível e a regra sobrevive ao crescimento futuro da denylist. Hoje é inerte por comprimento (todas as palavras têm ≤7 caracteres vs. slug de 8), mas a regra fica testada e explícita | y |
| Contagem do orçamento de 5 tentativas | As 5 tentativas contam **inserções** que falharam por colisão de chave primária. Candidato descartado pela denylist é regenerado sem consumir esse orçamento, sob teto próprio de 5 descartes | Mantém a decisão do mantenedor (descarte por denylist ≠ colisão) e ainda assim mantém o laço provadamente limitado (máx. 10 gerações). Ambos os tetos produzem o mesmo erro ao esgotar | y |
| Erro ao esgotar as tentativas | `503` com código estável **`SLUG_GENERATION_FAILED`**, com `Retry-After`; adicionado a `docs/api.md` §7 e à OpenAPI pela fatia [link-creation](../link-creation/spec.md) | Falha transitória e reprocessável; distinguível de Redis/PostgreSQL fora em observabilidade. Domínio expõe falha tipada; o mapeamento HTTP é da fatia de endpoint | y |
| Local da denylist | Lista versionada em `backend/config/links.php` (chave `links.slug.reserved_words`), injetada no `Domain` por contrato (ex.: `ReservedSlugs`) | Precedente do Auth (`config/invite-allowlist.*.json`); mantém o `Domain` puro e testável e permite evolução operacional sem alterar regra de negócio | y |
| Alias com Unicode ou homoglifos | Rejeitado. A normalização faz **apenas** trim + lowercase ASCII; qualquer code point fora de `[a-z0-9-]` após isso falha a validação. Sem NFKC, sem transliteração, sem mapeamento de homoglifos | Superfície de spoofing zero e regra trivialmente testável; equivalência silenciosa entre `аdmin` (cirílico) e `admin` nunca é criada | y |
| Lowercase ASCII vs. locale | Conversão restrita a `A-Z` → `a-z`, independente de locale (sem `mb_strtolower`, sem regras turcas de `I`/`İ`) | Evita que `İ` colapse em `i` e que a mesma entrada produza slugs diferentes por ambiente | y |
| Trim aplicado ao alias | Somente espaço, tab, CR, LF, NUL e vertical tab (`trim` ASCII padrão do PHP) nas extremidades; espaço interno não é removido — vira caractere inválido | Espaço interno é entrada errada, não formatação; silenciosamente removê-lo criaria alias diferente do digitado | y |
| Comparação com a denylist | Ocorre **após** a normalização e sobre o valor completo, com igualdade exata (`admin` bloqueado; `admin-panel` e `myadmin` permitidos) | `docs/api.md` §4.6; bloquear por substring inutilizaria grande parte do namespace | y |
| Denylist aplica-se a alias e a slug automático | Sim, mesma lista, mesmo ponto de verificação (construção do VO `Slug`) | Uma única regra impede divergência entre os dois caminhos | y |
| Verificação prévia de disponibilidade | Não existe consulta prévia confiável; o `INSERT` em `slug_reservations` é a única autoridade | `docs/security.md` §8.2, `docs/architecture.md` §6.1 — check-then-insert é TOCTOU e ainda revelaria ocupação do namespace | y |
| Reserva e link na mesma transação | O serviço de reserva participa da transação aberta pelo chamador; não abre nem confirma transação própria | `docs/data-model.md` §4; a fatia [link-creation](../link-creation/spec.md) é dona do escopo transacional | y |
| Conteúdo de `slug_reservations` | Somente `slug` (PK) e `reserved_at`; sem proprietário, sem FK para o link, sem `deleted_at` | `docs/data-model.md` §4 — a ausência de proprietário é o que impede o vazamento na colisão | y |
| Reserva nunca é removida | Não existe caminho de código (repositório, UseCase, comando ou rota) que faça `DELETE` em `slug_reservations` | `docs/security.md` §8.2 e `docs/testing.md` §6.3: reserva permanente mesmo após exclusão do link ou da conta | y |
| Mensagem de falha de reserva | Resultado tipado, uniforme e sem dados do ocupante (sem proprietário, sem data de reserva, sem distinguir reserva órfã de reserva com link) | `docs/testing.md` §6.3 — indisponível é indisponível; qualquer diferenciação enumera o namespace | y |
| Imutabilidade do slug | O VO é imutável e o agregado não expõe operação de troca de slug; a persistência de `short_links.slug` também não é atualizável pelo repositório | `docs/api.md` §4.1 | y |
| Alfabeto Base36 do slug automático | `abcdefghijklmnopqrstuvwxyz0123456789` (36 símbolos), exatamente 8 posições, sem prefixo, sufixo ou separador | `docs/data-model.md` §4 | y |
| Fonte de aleatoriedade | CSPRNG do sistema por trás de um contrato injetável (`random_int` / `random_bytes`), com distribuição sem viés de módulo | `docs/security.md` §8.2; contrato permite injetar sequência determinística nos testes de colisão | y |
| Slug automático de 8 caracteres e o mínimo de 3 do alias | Ambos convivem no mesmo namespace e nas mesmas colunas `varchar(48)`; o gerador nunca produz comprimento diferente de 8 | `docs/data-model.md` §4 | y |
| `slug_source` | Determinado pelo caminho de criação (`automatic` quando gerado, `custom` quando veio de alias), decidido nesta fatia e persistido pela fatia de criação | `docs/data-model.md` §4 (`CHECK`) | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions (fatia slug-policy)

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Alias 3–48 caracteres após normalização; allowlist `a-z0-9-`; início/fim alfanuméricos; sem hífens consecutivos; denylist exata; slug automático fixo em 8 |
| Failure / partial-failure states | Alias indisponível e exaustão de tentativas são falhas tipadas de domínio; a reserva participa da transação do chamador, logo rollback do link desfaz a reserva daquela transação |
| Idempotency / retry / duplicate handling | Retentativa apenas para slug automático (5 colisões / 5 descartes por denylist); alias personalizado **nunca** é retentado nem alterado automaticamente. Idempotência de request é da fatia [idempotency](../idempotency/spec.md) |
| Auth boundaries & rate limits | N/A nesta fatia — a política é de domínio e não conhece identidade; autenticação, ownership e rate limit ficam em [link-creation](../link-creation/spec.md) |
| Concurrency / ordering | Constraint `PRIMARY KEY` de `slug_reservations` é a autoridade; violação de unicidade concorrente vira falha tipada sem sobrescrita e sem revelar proprietário |
| Data lifecycle / expiry | Reserva é permanente e sem TTL; reserva órfã é estado válido e final, não é limpa por rotina |
| Observability | Falhas de reserva e exaustão de tentativas emitem métrica/log com contagem e motivo; SHALL NOT registrar proprietário do slug ocupante nem destino |
| External-dependency failure | Única dependência é o PostgreSQL; indisponibilidade propaga como falha de infraestrutura do chamador, sem retentativa cega de reserva |
| State-transition integrity | Reserva tem transição única e irreversível (ausente → reservado); slug é imutável após criação; reserva nunca volta a ficar disponível |

---

## Entregáveis técnicos

### Domínio (`backend/modules/Links/Domain/`)

| Artefato | Regra |
| --- | --- |
| `ValueObjects/Slug` | Imutável; `fromCustomAlias(string)` normaliza e valida como alias; `fromGenerated(string)` valida o formato automático; igualdade por valor normalizado |
| Normalização | `trim` ASCII + lowercase restrito a `A-Z` → `a-z`, aplicado **antes** de qualquer validação, reserva ou comparação |
| Validação de alias | Regex de referência pós-normalização: `^[a-z0-9](?:[a-z0-9]|-(?!-)){1,46}[a-z0-9]$` |
| Códigos estáveis de falha | `too_short`, `too_long`, `invalid_characters`, `invalid_boundary`, `consecutive_hyphens`, `reserved_word` |
| `Services/SlugGenerator` | Base36 minúsculo, exatamente 8 caracteres, por contrato de aleatoriedade; aplica denylist a cada candidato |
| Falhas tipadas | `SlugUnavailable` (colisão / alias ocupado) e `SlugGenerationExhausted` (orçamento esgotado) |

### Contratos e infraestrutura

| Artefato | Regra |
| --- | --- |
| `Contracts/Services/ReservedSlugs` | Expõe a denylist ao `Domain` sem acoplá-lo a `config()`; adaptador lê `config('links.slug.reserved_words')` |
| `Contracts/Services/RandomSlugSource` | Fonte CSPRNG injetável; implementação usa `random_int`/`random_bytes` sem viés de módulo; testes injetam sequência determinística |
| `Contracts/Repositories/SlugReservationRepository` | `reserve(Slug): void` (falha tipada em violação de PK) e `existsWithoutLink(Slug): bool` para a semântica de reserva órfã; **sem** método de remoção |
| `Infrastructure/Persistence/Eloquent/...` | `INSERT` em `slug_reservations` mapeando violação de unicidade PostgreSQL (SQLSTATE `23505`) para `SlugUnavailable`; participa da transação corrente |

### Configuração (`backend/config/links.php`)

| Chave | Valor inicial |
| --- | --- |
| `links.slug.length` | `8` |
| `links.slug.alphabet` | `abcdefghijklmnopqrstuvwxyz0123456789` |
| `links.slug.min_alias_length` / `max_alias_length` | `3` / `48` |
| `links.slug.max_collision_attempts` | `5` |
| `links.slug.max_denylist_discards` | `5` |
| `links.slug.reserved_words` | `admin`, `api`, `login`, `register`, `docs`, `health`, `status`, `support`, `terms`, `privacy` |

---

## User Stories

### P1: Slug automático seguro ⭐ MVP

**User Story**: Como usuário que cria um link sem alias, quero um slug curto e imprevisível para que ninguém consiga adivinhar ou enumerar meus links.

**Why P1**: Sem gerador, `POST /links` não consegue criar link algum; previsibilidade de slug expõe todo o namespace.

**Acceptance Criteria**:

1. WHEN o gerador produz um slug THEN ele SHALL ter exatamente 8 caracteres, todos pertencentes a `[a-z0-9]`.
2. WHEN o gerador é executado THEN SHALL obter cada caractere do contrato de aleatoriedade criptográfica, e SHALL NOT usar `rand`, `mt_rand`, `uniqid`, timestamp ou o `id` do usuário como fonte.
3. WHEN 10.000 slugs são gerados em sequência THEN SHALL NOT haver repetição e cada um dos 36 símbolos do alfabeto SHALL ser alcançável (ausência de viés de módulo).
4. WHEN um candidato gerado pertence à denylist THEN SHALL ser descartado e um novo candidato SHALL ser gerado, sem consumir o orçamento de colisões.
5. WHEN um slug é gerado THEN o `Slug` resultante SHALL ser marcado com origem `automatic`.

**Independent Test**: Teste unitário do gerador com fonte de aleatoriedade injetada — assert formato, comprimento, uso exclusivo do contrato CSPRNG e descarte de candidato em denylist.

**Requirement IDs**: LNK-10, SLG-01, SLG-02, SLG-03

---

### P1: Retentativa de colisão limitada ⭐ MVP

**User Story**: Como sistema, quero que uma colisão de slug automático seja resolvida pelo banco em poucas tentativas para que a criação nunca entre em laço nem sobrescreva um link existente.

**Why P1**: É a única defesa correta contra corrida; sem teto, uma colisão sistemática travaria o endpoint.

**Acceptance Criteria**:

1. WHEN a reserva de um slug gerado falha por violação de chave primária THEN o serviço SHALL gerar um novo slug e tentar novamente.
2. WHEN 5 tentativas de inserção falham por colisão THEN SHALL falhar com `SlugGenerationExhausted`, e SHALL NOT tentar uma sexta vez.
3. WHEN uma tentativa é bem-sucedida antes do limite THEN SHALL parar imediatamente e SHALL NOT gerar candidatos adicionais.
4. WHEN 5 candidatos consecutivos são descartados pela denylist THEN SHALL falhar com `SlugGenerationExhausted` (teto próprio, laço provadamente limitado).
5. WHEN `SlugGenerationExhausted` ocorre THEN a fatia de endpoint SHALL mapear para `503 SLUG_GENERATION_FAILED` com `Retry-After`, e a resposta SHALL NOT revelar quantas tentativas ocorreram nem qualquer slug candidato.
6. WHEN uma colisão ocorre THEN SHALL NOT haver consulta prévia de existência (`SELECT`) usada como decisão de disponibilidade — o `INSERT` é a autoridade.

**Independent Test**: Teste de integração em `fake_link_testing` com fonte determinística que repete um slug já reservado; assert 5 tentativas, exceção tipada e ausência de sobrescrita do link existente.

**Requirement IDs**: LNK-11, SLG-04, SLG-05, SLG-06

---

### P1: Normalização e validação de alias personalizado ⭐ MVP

**User Story**: Como usuário, quero digitar meu alias em qualquer caixa e receber sempre o mesmo slug canônico para que o link funcione de forma previsível e não possa ser sequestrado por variação de caixa.

**Why P1**: O namespace é global e case-insensitive; sem normalização antes da reserva, `Foo` e `foo` seriam recursos distintos.

**Acceptance Criteria**:

1. WHEN o alias `  Architecture  ` é informado THEN o valor normalizado SHALL ser `architecture`, e a normalização SHALL ocorrer antes de validação, denylist, reserva e comparação.
2. WHEN o alias normalizado tem menos de 3 ou mais de 48 caracteres THEN a validação SHALL falhar com `too_short` / `too_long`.
3. WHEN o alias normalizado tem exatamente 3 ou 48 caracteres e cumpre as demais regras THEN SHALL ser aceito.
4. WHEN o alias contém qualquer caractere fora de `[a-z0-9-]` após a normalização (incluindo espaço interno, `_`, `.`, `/`, `%`, emoji ou qualquer code point não-ASCII) THEN a validação SHALL falhar com `invalid_characters`, e SHALL NOT haver transliteração, NFKC ou mapeamento de homoglifos.
5. WHEN o alias começa ou termina com hífen THEN a validação SHALL falhar com `invalid_boundary`.
6. WHEN o alias contém dois hífens consecutivos THEN a validação SHALL falhar com `consecutive_hyphens`.
7. WHEN a conversão de caixa é aplicada THEN SHALL mapear somente `A-Z` para `a-z`, independente do locale do processo.
8. WHEN um alias válido é aceito THEN o `Slug` resultante SHALL ser marcado com origem `custom`.

**Independent Test**: Testes unitários de `Slug::fromCustomAlias` cobrindo limites 2/3/48/49, cada código de falha e a tabela de casos Unicode — sem banco.

**Requirement IDs**: LNK-12, LNK-13, SLG-07, SLG-08, SLG-09, SLG-10

---

### P1: Denylist de palavras reservadas ⭐ MVP

**User Story**: Como operador, quero que caminhos de confiança e operação nunca virem alias para que ninguém publique um link em `admin`, `login` ou `api`.

**Why P1**: É superfície direta de phishing e de conflito com rotas operacionais.

**Acceptance Criteria**:

1. WHEN o alias normalizado é exatamente uma das palavras `admin`, `api`, `login`, `register`, `docs`, `health`, `status`, `support`, `terms` ou `privacy` THEN a validação SHALL falhar com `reserved_word`.
2. WHEN o alias informado é `ADMIN` ou `Admin` THEN SHALL falhar com `reserved_word` (comparação após normalização).
3. WHEN o alias é `admin-panel`, `myadmin` ou `apis` THEN SHALL ser aceito (igualdade exata, não substring).
4. WHEN a denylist é lida THEN SHALL vir de `config('links.slug.reserved_words')` através do contrato `ReservedSlugs`, e a lista literal SHALL NOT aparecer duplicada em `Domain`, `FormRequest`, migration ou rota.
5. WHEN uma palavra é acrescentada à configuração THEN SHALL passar a bloquear alias e candidato automático sem alteração de código de domínio.

**Independent Test**: Teste unitário com denylist injetada (incluindo uma palavra fictícia adicionada em runtime) provando que a fonte é a configuração; mais o teste de igualdade exata.

**Requirement IDs**: LNK-14, SLG-11, SLG-12, SLG-13

---

### P1: Reserva global e permanente ⭐ MVP

**User Story**: Como sistema, quero que todo slug seja reservado de forma global e permanente para que um slug já usado nunca aponte para outro destino, mesmo depois de o link deixar de existir.

**Why P1**: É a garantia central contra sequestro de link e contra reaproveitamento de slug já divulgado.

**Acceptance Criteria**:

1. WHEN um link é criado THEN a linha em `slug_reservations` SHALL ser inserida na mesma transação do link, e SHALL conter somente `slug` e `reserved_at`.
2. WHEN a transação de criação sofre rollback THEN a reserva daquela transação SHALL NOT persistir.
3. WHEN um slug já reservado é reservado novamente THEN SHALL falhar com `SlugUnavailable`, e a reserva existente SHALL permanecer inalterada (mesmo `reserved_at`).
4. WHEN `SlugUnavailable` é produzida THEN o resultado SHALL ser idêntico para reserva com link e para reserva órfã, e SHALL NOT conter proprietário, título, destino ou `reserved_at` do ocupante.
5. WHEN o código do módulo é inspecionado THEN SHALL NOT existir `DELETE`, `truncate`, soft delete ou update de `slug_reservations` em repositório, UseCase, comando ou rota.
6. WHEN uma reserva existe sem `short_link` associado THEN a porta de consulta SHALL identificá-la como reserva órfã (estado válido e final).
7. WHEN um `short_link` existe THEN seu `slug` SHALL ser imutável — não há operação de domínio nem caminho de repositório que altere o valor.

**Independent Test**: Teste de integração em `fake_link_testing`: reserva + rollback, reserva duplicada, remoção manual do link deixando a reserva órfã, e gate Pest Arch/grep provando ausência de caminho de remoção.

**Requirement IDs**: LNK-15, LNK-16, SLG-14, SLG-15, SLG-16

---

### P1: Concorrência entre aliases equivalentes ⭐ MVP

**User Story**: Como usuário, quero que duas criações simultâneas do mesmo alias em caixas diferentes tenham um único vencedor para que meu alias não seja sobrescrito nem revele quem o possui.

**Why P1**: `docs/testing.md` §6.3 e §7 exigem cobertura explícita de reserva concorrente; é o cenário em que a política falharia silenciosamente.

**Acceptance Criteria**:

1. WHEN duas transações concorrentes reservam `Foo` e `foo` THEN exatamente uma SHALL confirmar e a outra SHALL falhar com `SlugUnavailable`.
2. WHEN a transação perdedora falha THEN SHALL NOT sobrescrever, atualizar ou remover a reserva vencedora, e SHALL NOT criar um segundo `short_link` com o mesmo slug.
3. WHEN a transação perdedora falha THEN a falha SHALL ser resolvida pela constraint do PostgreSQL, e SHALL NOT depender de verificação prévia na aplicação.
4. WHEN a falha é observada pelo cliente ou pelos logs THEN SHALL NOT revelar o proprietário do slug vencedor.

**Independent Test**: Teste de concorrência em `fake_link_testing` com duas conexões e commits sobrepostos; assert um vencedor, uma única linha em `slug_reservations` e ausência de dados do ocupante na falha.

**Requirement IDs**: LNK-12, LNK-15, SLG-17, SLG-18

---

## Edge Cases

- Alias com espaços externos (`"  foo  "`) → trim antes de lowercase e validação; alias com espaço interno (`"my link"`) → `invalid_characters`.
- Alias exatamente nos limites: 2 → `too_short`; 3 → aceito; 48 → aceito; 49 → `too_long`.
- Alias composto só de hífens (`---`) → `invalid_boundary` (a fronteira falha antes da regra de hífens consecutivos).
- Alias `a-b` (mínimo válido com hífen) → aceito; `a--b` → `consecutive_hyphens`.
- Alias `аdmin` com `а` cirílico (U+0430) → `invalid_characters`, e SHALL NOT ser transliterado para `admin`.
- Alias `ADMÍN` → lowercase ASCII produz `admín`, que falha em `invalid_characters` (não em `reserved_word`).
- Alias `İstanbul` (U+0130) → `invalid_characters`; a conversão de caixa restrita a ASCII impede colapso dependente de locale.
- Alias com percent-encoding (`%61dmin`) → `invalid_characters`; nenhuma decodificação é feita nesta camada.
- Alias vazio ou só espaços → `too_short` após normalização (não é tratado como "sem alias").
- Alias com NUL ou caractere de controle → `invalid_characters`; nunca chega ao driver do banco.
- Slug automático que colide 4 vezes e vence na 5ª → sucesso, sem erro.
- Slug automático que colide 5 vezes → `SlugGenerationExhausted`, nenhum link criado, nenhuma reserva parcial.
- Reserva órfã de uma palavra que **depois** entra na denylist → a reserva permanece; a denylist só bloqueia novas criações.
- PostgreSQL indisponível durante a reserva → erro de infraestrutura propagado ao chamador, sem retentativa cega e sem consumir o orçamento de colisões.

---

## Requirement Traceability

| Requirement ID | Story | Descrição | Phase | Status |
| --- | --- | --- | --- | --- |
| LNK-10 | P1: Slug automático | Base36 8 caracteres por CSPRNG | Execute | Done |
| SLG-01 | P1: Slug automático | Formato e comprimento exatos | Execute | Done |
| SLG-02 | P1: Slug automático | Fonte CSPRNG injetável sem viés de módulo | Execute | Done |
| SLG-03 | P1: Slug automático | Candidato em denylist é descartado e regerado | Execute | Done |
| LNK-11 | P1: Retentativa | Máximo de 5 tentativas de colisão | Execute | Done |
| SLG-04 | P1: Retentativa | Parada imediata no primeiro sucesso | Execute | Done |
| SLG-05 | P1: Retentativa | `SlugGenerationExhausted` → `503 SLUG_GENERATION_FAILED` | Execute | Done |
| SLG-06 | P1: Retentativa | Sem check-then-insert; `INSERT` é a autoridade | Execute | Done |
| LNK-12 | P1: Alias / P1: Concorrência | Normalização para ASCII minúsculo antes de tudo | Execute | Done |
| LNK-13 | P1: Alias | Allowlist de caracteres e comprimento 3–48 | Execute | Done |
| SLG-07 | P1: Alias | Limites 3/48 inclusivos com códigos estáveis | Execute | Done |
| SLG-08 | P1: Alias | Fronteiras alfanuméricas e hífens não consecutivos | Execute | Done |
| SLG-09 | P1: Alias | Unicode/homoglifo rejeitado, nunca normalizado | Execute | Done |
| SLG-10 | P1: Alias | Lowercase ASCII independente de locale | Execute | Done |
| LNK-14 | P1: Denylist | Denylist de palavras reservadas | Execute | Done |
| SLG-11 | P1: Denylist | Comparação exata pós-normalização | Execute | Done |
| SLG-12 | P1: Denylist | Fonte única em `config/links.php` via contrato | Execute | Done |
| SLG-13 | P1: Denylist | Mesma lista aplicada a alias e slug automático | Execute | Done |
| LNK-15 | P1: Reserva / P1: Concorrência | Reserva global e permanente | Execute | Done |
| LNK-16 | P1: Reserva | Reserva órfã sem proprietário nem destino | Execute | Done |
| SLG-14 | P1: Reserva | Reserva na transação do chamador; rollback desfaz | Execute | Done |
| SLG-15 | P1: Reserva | Ausência de qualquer caminho de remoção | Execute | Done |
| SLG-16 | P1: Reserva | `short_links.slug` imutável | Execute | Done |
| SLG-17 | P1: Concorrência | Único vencedor entre aliases equivalentes por caixa | Execute | Done |
| SLG-18 | P1: Concorrência | Falha uniforme que não revela o proprietário | Execute | Done |

**Coverage:** 25 total, 25 mapped to tasks (T1–T13, `feat/links-slug-policy`); Execute ✅ · Verifier ✅ **PASS** 2026-09-01 (`.specs/features/links/slug-policy/validation.md`) — 5/5 discrimination mutants killed, 0 surviving; 2 spec deviations scrutinized (both non-blocking, see report)

---

## Success Criteria

- [ ] `make lint` e `make test-backend` passam com a política de slug introduzida.
- [ ] Nenhum teste consegue criar dois recursos para `Foo` e `foo`, sequencial ou concorrentemente.
- [ ] Nenhum teste consegue reservar um alias da denylist, em qualquer caixa.
- [ ] Nenhum caminho de código remove uma linha de `slug_reservations` (verificável por gate arquitetural/grep).
- [ ] Falha de alias indisponível é byte-a-byte idêntica para reserva com link e reserva órfã.
- [ ] Substituir a fonte CSPRNG por uma sequência fixa faz os testes de colisão falharem de forma determinística (sensor de discriminação preparado).
- [ ] Cobertura de `modules/Links/` referente a esta fatia ≥90% linhas / ≥85% branches (`docs/testing.md` §4).
- [ ] A fatia [link-creation](../link-creation/spec.md) pode iniciar consumindo `Slug`, gerador e serviço de reserva sem alterar assinatura nem schema.

---

## Verificação (gates da fatia)

| Gate | Comando / artefato |
| --- | --- |
| Lint + análise estática | `make lint` |
| Testes backend | `make test-backend` — PostgreSQL `fake_link_testing` only (AD-011) |
| Cobertura | `make test-backend-coverage` — 90% linhas / 85% branches |
| Concorrência | Suíte de reserva concorrente com duas conexões (`docs/testing.md` §7) |
| Arquitetura | Pest Arch: `Domain` sem `config()`/Eloquent; ausência de remoção de reserva |
| Configuração | `config/links.php` versionada; denylist não duplicada no código |

---

## Referências

| Documento | Uso |
| --- | --- |
| [Índice Links](../README.md) | Catálogo `LNK-XX` e ordem das fatias |
| [foundation](../foundation/spec.md) | Esquema de `slug_reservations` / `short_links` e esqueleto do VO |
| `docs/data-model.md` §4 | `slug_reservations`, regras de slug e alias, regex de referência |
| `docs/api.md` §4.1, §4.4, §4.6, §7 | Imutabilidade do slug, payload de criação, contrato de alias, códigos estáveis |
| `docs/security.md` §8.2 | CSPRNG, allowlist e reserva permanente |
| `docs/testing.md` §6.3, §7 | Casos obrigatórios e concorrência |
| `docs/architecture.md` §6.1 | Fluxo de criação e decisão de colisão |
| `.specs/features/auth/foundation/spec.md` | Precedente de VO com códigos de falha estáveis e config versionada |
