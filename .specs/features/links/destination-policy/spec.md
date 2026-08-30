# Links — Política de destino

**Status:** Fechada — confirmada 2026-08-30
**Fatia:** 3 de 13 — ver [índice](../README.md)
**Requirement IDs (catálogo):** LNK-20 … LNK-26
**Requirement IDs (fatia):** LDST-01 … LDST-24
**Depende de:** [foundation](../foundation/spec.md) — `DestinationUrl` (invariantes mínimas), porta `DestinationCipher`, keyring de destinos, tabela `link_destination_versions`

---

## Problem Statement

A fundação (fatia 1) entregou `DestinationUrl` apenas como VO de tipo: esquema `http`/`https`, comprimento ≤2.048 e host não vazio. Hoje `https://user:senha@127.0.0.1:8080/x`, `http://localhost/admin` e `https://go.localhost/abc` são **aceitos** — a própria fatia 1 documenta isso como dívida explícita.

Essa URL termina em um header `Location` público servido pelo host curto. Ela precisa de parsing por parser de URL, política de host, normalização que não altere semântica e cifra AES-256-GCM antes de tocar o PostgreSQL. Como a mesma política vale para a criação (fatia 4) e para **toda** nova versão de destino (fatia 7), ela é uma fatia própria, anterior aos endpoints — caso contrário cada endpoint reimplementaria a sua, e a versão mais fraca venceria.

## Goals

- [ ] `DestinationUrl` passa a ser o único ponto de verdade da política: nenhuma URL atravessa o VO sem parse, política de host e normalização completos.
- [ ] Classificador de host público reutilizável, puro e determinístico (sem DNS, sem rede), com nomes especiais e hosts próprios do produto derivados de env.
- [ ] Normalização idempotente que remove porta padrão redundante e preserva integralmente path, query string e fragmento.
- [ ] Nenhum caminho de escrita consegue cifrar um destino que não tenha sido validado imediatamente antes daquela persistência.
- [ ] URL de destino em claro, query string e fragmento ausentes de log, exceção serializada, trace e métrica — provado por teste, não por revisão.
- [ ] Contrato de erro estável: `422 VALIDATION_FAILED` com `errors.destination_url[].code = INVALID_DESTINATION_URL`, sem revelar qual regra reprovou.

## Escopo desta fatia

- Validação de esquema (`http`/`https`) e limite de 2.048 caracteres.
- Exigência de hostname público válido; rejeição de literais IPv4/IPv6, nomes locais e especiais, e dos hosts próprios do produto.
- Rejeição de `userinfo`, caracteres de controle e percent-encoding malformado.
- Parsing da autoridade por parser de URL, nunca por concatenação ou busca textual.
- Normalização: porta customizada válida aceita, porta padrão redundante removida, query string e fragmento preservados.
- Uso da porta `DestinationCipher` (entregue na fatia 1) a partir de um valor já validado, com revalidação antes de cada persistência de nova versão.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Endpoints que recebem o destino (`POST`, `PATCH`) | Fatias [link-creation](../link-creation/spec.md) e [link-update](../link-update/spec.md); esta fatia entrega a política, não a superfície HTTP |
| `FormRequest`, mapeamento HTTP do erro e entrada na OpenAPI | Fatia [link-creation](../link-creation/spec.md); aqui só se fixa o contrato de código/mensagem que ela deve emitir |
| Transação, versionamento de vigência e `ETag` | Fatias 4 e 7 |
| Cifra do snapshot de cache | Keyring distinto — fatia [redirect-cache](../redirect-cache/spec.md) |
| Mecanismo de cifra, envelope, AAD e rotação de `key_id` | Já entregues e verificados na fatia [foundation](../foundation/spec.md) (LFND-11 … LFND-15); esta fatia é consumidora da porta |
| Uso do destino decifrado no `Location` | Fatia [redirect-http](../redirect-http/spec.md) — o valor volta a ser tratado como não confiável lá |
| Fetch server-side, preview, disponibilidade ou reputação | Rejeitado no desenho inicial (`docs/security.md` §8.1) |
| Resolução DNS e bloqueio por IP resolvido | Decisão registrada abaixo; exigiria I/O de rede, tratamento de DNS rebinding (TOCTOU) e contraria `docs/security.md` §8.1 |
| Suporte a IDN (U-label) e a bytes não-ASCII | O parser converteria e reescreveria o valor; decisão registrada abaixo |
| Busca por URL de destino | Não suportada (`docs/data-model.md` §4) |
| Runbook de rotação de chave em produção | Runbook de segurança; mecanismo já existe desde a fatia 1 |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Onde vive a política | `DestinationUrl` (Domain de `Links`) deixa de ser VO de tipo e passa a VO de política: `fromString()` faz parse, política e normalização. As invariantes mínimas da fatia 1 continuam válidas, agora como subconjunto | Um único ponto de entrada impede que uma fatia futura construa um destino sem política (`LARAVEL_CODE_DESIGN.md`, domínio rico) | y |
| Classificador de host | Serviço de domínio puro `PublicHostClassifier`, sem I/O, recebendo a lista de hosts próprios por injeção | Reutilizável, determinístico e testável sem banco ou rede | y |
| **Resolução DNS** | **Não faz parte da política.** O bloqueio é sintático: literais de IP e nomes especiais. Um hostname público que resolva para faixa privada **não** é bloqueado | `docs/security.md` §8.1 não prevê verificação server-side; resolver na validação introduz I/O de rede, flakiness, latência no `POST`/`PATCH` e uma janela TOCTOU (rebinding) que ela mesma não fecha | y |
| Faixas RFC 6890 na denylist | **Nenhuma faixa é necessária.** Como **todo** literal de IP (IPv4 e IPv6, em qualquer forma) é rejeitado antes de qualquer classificação, uma lista de faixas privadas seria código morto | Resolve a Open Question original da seed pela regra mais forte: nenhum IP literal, público ou privado, é destino válido | y |
| Nomes especiais bloqueados | Sufixo, case-insensitive: `localhost`, `.localhost`, `.local`, `.internal`, `.home.arpa`, `.test`, `.invalid`, `.example`, `.onion`, `.alt` | RFC 6761, RFC 6762, RFC 9476 e o `.internal` reservado pela ICANN (2024); cobre mDNS, redes internas e Tor | y |
| **Hosts próprios** | Derivados de env em `config/links.php` → `destination.self_hosts`: host de `SHORT_HOST` **e** host de `APP_URL` (em dev, `go.localhost` e `app.localhost`), bloqueando o host exato e qualquer subdomínio | Impede laço de redirect e encurtamento da própria API; `SHORT_HOST` já é obrigatório em `docker/scripts/validate-env.sh`, então local, CI e produção usam o mesmo código | y |
| Host sem ponto | Rejeitado (exige ao menos dois labels e TLD alfabético de ≥2 caracteres) | Elimina de uma vez nomes de intranet (`intranet`, `wiki`), `localhost` sem sufixo e formas inteiras de IPv4 (`2130706433`, `0x7f000001`), que nunca têm TLD alfabético | y |
| **Entrada não-ASCII (inclui IDN)** | Rejeitada: **qualquer** byte não-ASCII na entrada crua reprova, não só no host. O host precisa chegar em ASCII LDH (A-label/punycode) | Verificado empiricamente em `league/uri` 7.8.1 (já vendorizado): o parser **converte** `café.com` → `xn--caf-dma.com` mesmo sem `ext-intl` (IDNA em PHP puro) e **percent-encoda** bytes não-ASCII de path e query (`?a=á` → `?a=%C3%A1`). Aceitar essa entrada significaria persistir um valor diferente do informado, quebrando a preservação byte a byte de `docs/data-model.md` §4. Rejeitar é a única forma de manter normalização não destrutiva e de eliminar as "formas ambíguas entre parsers" de `docs/security.md` §8.1. `xn--` continua aceito normalmente | y |
| **Granularidade do erro** | Um único código público: `422 VALIDATION_FAILED` + `errors.destination_url[].code = INVALID_DESTINATION_URL`, mensagem fixa `The destination URL is not allowed.` | É literalmente o exemplo de `docs/api.md` §2; não vira oráculo de sondagem de rede interna. O motivo específico existe como enum interno (`DestinationRejectionReason`) para teste e métrica | y |
| Motivo da rejeição | Enum interno estável carregado pela exceção de domínio: `SCHEME_NOT_ALLOWED`, `TOO_LONG`, `MALFORMED_URL`, `USERINFO_PRESENT`, `CONTROL_CHARACTER`, `INVALID_PERCENT_ENCODING`, `NON_ASCII_INPUT`, `INVALID_HOSTNAME`, `IP_LITERAL`, `SPECIAL_USE_HOST`, `SELF_HOST`, `INVALID_PORT` | Nome de motivo é cardinalidade fixa e não contém dado do usuário — publicável em métrica sob `docs/security.md` §13 | y |
| **Limite de 2.048** | Verificado **duas vezes**: na entrada crua (após trim, antes do parse) e no valor normalizado, antes da cifra. Ambos ≤2.048 | O primeiro protege o parser de entrada absurda; o segundo é o contrato de `link_destination_versions.destination_url` (`docs/data-model.md` §4). A normalização só encurta, então na prática o segundo nunca reprova sozinho — e é justamente isso que o teste fixa | y |
| Unidade do limite | Caracteres = **bytes** (a URL válida é ASCII após as regras acima) | Sem host não-ASCII e com percent-encoding preservado, byte e caractere coincidem; elimina ambiguidade `strlen` vs `mb_strlen` | y |
| Whitespace na borda | Espaços e `\t\r\n` no início/fim são removidos antes do parse; qualquer whitespace ou caractere de controle **remanescente** rejeita | Colar URL com espaço à direita é acidente comum; whitespace interno é forma ambígua entre parsers | y |
| Caracteres de controle | U+0000–U+001F e U+007F em qualquer posição após o trim ⇒ rejeição | `docs/security.md` §8.1; também impede CRLF injection no header `Location` | y |
| `userinfo` | Rejeitado inclusive nas formas vazias (`https://@host/`, `https://:@host/`) | `docs/security.md` §8.1; `@` na autoridade é vetor clássico de spoofing de host | y |
| Percent-encoding | **Nunca** decodificado nem recodificado. Sequência malformada (`%zz`, `%A` truncado, `%` isolado) rejeita, e a checagem roda **antes** do parser | `docs/data-model.md` §4: normalização não altera significado. Recodificar `%2F` mudaria a rota no destino; e o parser reescreve `%` isolado como `%25` se deixado decidir (probe em `league/uri` 7.8.1) | y |
| Normalização de esquema e host | Ambos convertidos para minúsculas; ponto final do FQDN (`host.com.`) removido | Case-insensitive por RFC 3986 §6.2.2.1; a remoção do ponto final evita duas formas do mesmo host | y |
| Porta | `:80` em `http` e `:443` em `https` removidas; porta vazia (`https://host:/p`) tratada como padrão e removida; demais portas 1–65535 preservadas sem zeros à esquerda; porta não numérica, `0` ou >65535 rejeita | `docs/api.md` §4.6 e `docs/testing.md` §6.3 | y |
| Path vazio | `https://host` ⇒ `https://host/` | Equivalência de RFC 3986 §6.2.3 para http(s); não altera o recurso apontado e dá uma forma canônica única | y |
| Path, query e fragmento | Preservados byte a byte, incluindo `?` e `#` vazios, `//` duplicado, `.`/`..` **não** colapsados | Colapsar `..` ou reordenar query altera o recurso em servidores reais; `docs/data-model.md` §4 proíbe alterar significado | y |
| Idempotência da normalização | Normalizar um valor já normalizado devolve exatamente o mesmo valor | Sem isso, revalidar a cada persistência produziria drift de valor entre versões | y |
| **Revalidação antes de cada persistência** | Estrutural: a cifra só é alcançável por um serviço de aplicação que recebe `string` crua, reconstrói `DestinationUrl` e só então chama `DestinationCipher`. Nenhum caller recebe um caminho que cifre string arbitrária | `docs/security.md` §8.1 exige revalidação a cada nova versão; garantir por tipo é mais forte que garantir por disciplina de code review | y |
| Rotação de `key_id` | Apenas consumida (`encrypt()` usa a chave ativa). O comportamento de rotação já é coberto por LFND-15 e não é reexercitado aqui | Evita duplicar teste já verificado na fatia 1 | y |
| Comparação de host próprio | Feita **após** a normalização do host (minúsculas, sem ponto final), por igualdade ou sufixo `.<self_host>` | Sem isso, `GO.Localhost.` escaparia da lista | y |
| Ordem de avaliação | Comprimento cru → caracteres de controle → parse → esquema → `userinfo` → host (ASCII, sintaxe, IP literal, especial, próprio) → porta → normalização → comprimento normalizado | Fixar a ordem torna o motivo interno determinístico e os testes estáveis | y |
| Destino em log | Reafirmado nesta fatia: URL, query string e fragmento **SHALL NOT** aparecer em log, mensagem de exceção, contexto de exceção, trace ou métrica — nem em caso de rejeição | `docs/security.md` §13, `docs/testing.md` §6.3; a rejeição é justamente onde a tentação de logar a URL é maior | y |
| OpenAPI nesta fatia | Nenhuma alteração em `docs/openapi.yaml` — não há endpoint ainda. A fatia 4 documenta `maxLength: 2048`, `format: uri` e descreve o resto em prosa | `docs/api.md` §4.6 | y |

**Open questions:** none — as cinco questões da seed foram resolvidas acima (host próprio, denylist, código de erro, ponto de medição do limite, rotação de chave).

---

## Implicit-Requirement Dimensions (fatia destination-policy)

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Núcleo da fatia: esquema, ≤2.048 (cru e normalizado), host ASCII LDH com labels 1–63 e total ≤253, porta 1–65535, sem `userinfo`, sem controle, percent-encoding bem formado |
| Failure / partial-failure states | Rejeição é total e sem efeito colateral: exceção de domínio antes de qualquer cifra ou escrita; nenhum valor parcial é persistido ou retornado |
| Idempotency / retry / duplicate | Normalização idempotente (`f(f(x)) = f(x)`) e política determinística: a mesma entrada sempre produz o mesmo veredito e o mesmo valor normalizado. Idempotência de request é da fatia 5 |
| Auth boundaries & rate limits | N/A — esta fatia não tem superfície HTTP; ownership e rate limit ficam nas fatias 4 e 7 |
| Concurrency / ordering | N/A — a política é pura e sem estado compartilhado; concorrência de versões é da fatia 7 |
| Data lifecycle / expiry | N/A — vigência (`valid_from`/`valid_to`) é das fatias 4 e 7; aqui só o valor cifrado da versão |
| Observability | URL, query e fragmento ausentes de log, exceção, trace e métrica; apenas o motivo (enum de cardinalidade fixa) é observável |
| External-dependency failure | N/A por decisão — nenhuma dependência externa é consultada (sem DNS, sem HTTP). Falha de chave/keyring já é tratada na fatia 1 |
| State-transition integrity | N/A — sem máquina de estados nesta fatia |

---

## User Stories

### P1: Política de URL rejeita destino inseguro ⭐ MVP

**User Story**: Como sistema, quero rejeitar toda URL de destino que não seja `http`/`https` com hostname público válido, sem credenciais e sem caracteres ambíguos, para que o header `Location` público nunca aponte para a rede interna, para o próprio produto ou para uma forma interpretada de maneira diferente por dois parsers.

**Why P1**: É a superfície de ataque da fatia. Sem ela, a fundação aceita `https://user:senha@127.0.0.1/x`.

**Acceptance Criteria**:

1. WHEN `DestinationUrl::fromString` recebe URL com esquema diferente de `http` ou `https` (`ftp:`, `javascript:`, `data:`, `file:`, esquema ausente) THEN SHALL lançar a exceção de domínio com motivo `SCHEME_NOT_ALLOWED` e SHALL NOT construir o VO.
2. WHEN a entrada crua, após remoção de whitespace de borda, tem mais de 2.048 caracteres THEN SHALL rejeitar com motivo `TOO_LONG`; WHEN tem exatamente 2.048 THEN SHALL prosseguir para as demais regras.
3. WHEN a entrada contém qualquer caractere U+0000–U+001F ou U+007F após o trim de borda (incluindo `\r`, `\n`, `\t` internos) THEN SHALL rejeitar com motivo `CONTROL_CHARACTER`.
4. WHEN a autoridade contém `userinfo` em qualquer forma (`https://u:p@host/`, `https://u@host/`, `https://@host/`, `https://:@host/`) THEN SHALL rejeitar com motivo `USERINFO_PRESENT`.
5. WHEN o host é literal IPv4 em qualquer notação (`127.0.0.1`, `10.0.0.5`, `8.8.8.8`, `0x7f.1`, `2130706433`) ou literal IPv6 entre colchetes (`[::1]`, `[fd00::1]`, `[2606:4700::1111]`) THEN SHALL rejeitar — IP público literal inclusive.
6. WHEN o host termina em nome de uso especial (`localhost`, `qualquer.localhost`, `nas.local`, `db.internal`, `x.home.arpa`, `a.test`, `a.invalid`, `a.example`, `x.onion`, `x.alt`), em qualquer caixa THEN SHALL rejeitar com motivo `SPECIAL_USE_HOST`.
7. WHEN o host é igual a um `self_host` configurado ou é subdomínio dele (`go.localhost`, `GO.Localhost.`, `abc.go.localhost`, `app.localhost`) THEN SHALL rejeitar com motivo `SELF_HOST`.
8. WHEN o host não tem ponto (`intranet`, `wiki`), tem label vazio (`a..b.com`), label >63 caracteres, total >253 caracteres, label iniciando ou terminando em hífen (`-a.com`, `a-.com`), ou TLD não alfabético/com menos de 2 caracteres THEN SHALL rejeitar com motivo `INVALID_HOSTNAME`.
9. WHEN a entrada crua contém qualquer byte não-ASCII, em qualquer componente (`https://café.com/x`, `https://example.com/pá`, `https://example.com/q?a=á`) THEN SHALL rejeitar com motivo `NON_ASCII_INPUT`; WHEN a mesma URL chega com o host em A-label e os demais componentes percent-encoded (`https://xn--caf-dma.com/q?a=%C3%A1`) THEN SHALL aceitar.
10. WHEN a entrada contém percent-encoding malformado (`%zz`, `%A` no fim, `%` isolado) THEN SHALL rejeitar com motivo `INVALID_PERCENT_ENCODING` **antes** de entregar o valor ao parser — que, verificado em `league/uri` 7.8.1, reescreveria `%` como `%25` e alteraria o valor em silêncio.
11. WHEN a porta é não numérica, `0` ou maior que 65535 THEN SHALL rejeitar com motivo `INVALID_PORT`.
12. WHEN a URL é sintaticamente inválida para o parser (`https://`, `http:///path`, `https://ho st.com/`) THEN SHALL rejeitar com motivo `MALFORMED_URL`, sem lançar erro de PHP nem warning.
13. WHEN uma URL válida de host público é fornecida (`https://example.com/a/b?q=1#f`) THEN SHALL construir o VO com sucesso.
14. WHEN a mesma entrada é avaliada duas vezes THEN o veredito e o motivo SHALL ser idênticos (política determinística, sem I/O e sem consulta DNS).

**Independent Test**: Suíte unitária de tabela em `modules/Links/Tests/Unit`, sem banco e sem rede, com a matriz de aceitos × rejeitados por motivo, e um teste que garante ausência de qualquer chamada de resolução DNS/HTTP no caminho.

---

### P1: Normalização preserva semântica ⭐ MVP

**User Story**: Como sistema, quero normalizar o destino para uma forma canônica única sem alterar o recurso apontado, para que duas grafias do mesmo destino produzam o mesmo valor cifrado e para que query string e fragmento cheguem intactos ao `Location`.

**Why P1**: A cifra congela o valor; normalizar errado corrompe destinos de forma silenciosa e irreversível no histórico.

**Acceptance Criteria**:

1. WHEN o esquema ou o host vêm em caixa mista (`HTTPS://Example.COM/Path`) THEN o valor normalizado SHALL ser `https://example.com/Path` — esquema e host em minúsculas, **path preservado na caixa original**.
2. WHEN a URL traz porta padrão redundante (`http://example.com:80/x`, `https://example.com:443/x`) THEN a porta SHALL ser removida do valor normalizado.
3. WHEN a URL traz porta customizada válida (`https://example.com:8443/x`) THEN a porta SHALL ser preservada exatamente.
4. WHEN a URL traz porta vazia (`https://example.com:/x`) THEN SHALL normalizar para `https://example.com/x`.
5. WHEN a URL traz query string e/ou fragmento (`?b=2&a=1&empty=&flag#frag`) THEN ambos SHALL ser preservados byte a byte, sem reordenar, sem remover chave vazia e sem decodificar.
6. WHEN a URL contém percent-encoding válido (`/a%2Fb?q=%C3%A1&r=a+b`) THEN o valor normalizado SHALL conter exatamente as mesmas sequências, sem decodificar nem recodificar (`%2F` continua `%2F`).
7. WHEN a URL contém segmentos `.`, `..` ou barras duplicadas no path (`/a/./b/../c//d`) THEN SHALL ser preservados sem colapsar.
8. WHEN o path é vazio (`https://example.com`) THEN o valor normalizado SHALL ser `https://example.com/`.
9. WHEN o host tem ponto final (`https://example.com./x`) THEN o ponto SHALL ser removido do valor normalizado.
10. WHEN um valor já normalizado é normalizado novamente THEN o resultado SHALL ser idêntico ao entrado (idempotência).
11. WHEN o valor normalizado excede 2.048 caracteres THEN SHALL rejeitar com motivo `TOO_LONG` antes de qualquer cifra.
12. WHEN o VO é convertido para string THEN SHALL devolver o valor normalizado, e SHALL NOT existir caminho público que devolva a entrada crua.

**Independent Test**: Suíte unitária de tabela entrada → valor normalizado esperado, mais um teste de propriedade de idempotência sobre todos os casos aceitos da matriz.

---

### P1: Destino validado imediatamente antes de cada cifra ⭐ MVP

**User Story**: Como sistema, quero que a cifra de um destino só seja alcançável a partir de um valor recém-validado, para que a criação (fatia 4) e cada troca de destino (fatia 7) sejam obrigadas a revalidar, sem depender de disciplina de quem escrever aquele código depois.

**Why P1**: `docs/security.md` §8.1 exige revalidação a cada nova versão; é a regra mais fácil de perder em uma fatia futura.

**Acceptance Criteria**:

1. WHEN o serviço de aplicação de destino recebe uma string crua THEN SHALL construir `DestinationUrl` (política completa) e só então chamar `DestinationCipher::encrypt` com o valor normalizado.
2. WHEN a string crua é reprovada pela política THEN SHALL lançar a exceção de domínio e SHALL NOT chamar `DestinationCipher::encrypt` (provado por spy/mock da porta, com zero invocações).
3. WHEN o mesmo serviço é chamado duas vezes para o mesmo link (criação e depois troca de destino) THEN a política SHALL ser executada nas duas chamadas.
4. WHEN a cifra ocorre THEN o `key_id` devolvido SHALL ser o `active_key_id` da configuração e SHALL ser entregue ao chamador separado do envelope, pronto para a coluna própria.
5. WHEN o envelope produzido é decifrado pela porta THEN o plaintext SHALL ser exatamente o valor normalizado, incluindo query string e fragmento.
6. WHEN se procura na superfície pública do módulo THEN SHALL NOT existir caminho que cifre uma `string` de destino sem passar pela política (verificado por teste de arquitetura Pest sobre a assinatura dos colaboradores).

**Independent Test**: Testes unitários com `DestinationCipher` real (chave de teste em memória) para o round-trip e com spy da porta para os casos de rejeição; teste de arquitetura para a regra de acessibilidade.

---

### P2: Erro estável e ausência de vazamento do destino

**User Story**: Como responsável pela segurança, quero que a rejeição produza sempre o mesmo código público e que a URL nunca apareça em log, exceção, trace ou métrica, para que a API não vire oráculo de rede interna e para que o destino não vaze pelo caminho de erro.

**Why P2**: Não bloqueia o funcionamento da política (P1), mas é condição de saída da fatia e de `docs/testing.md` §6.3. Entregue nesta fatia, não depois.

**Acceptance Criteria**:

1. WHEN qualquer regra da política reprova THEN a exceção de domínio SHALL carregar o motivo como enum de valor estável, e a mensagem destinada ao cliente SHALL ser sempre `The destination URL is not allowed.` — idêntica para todos os motivos.
2. WHEN a exceção é serializada (mensagem, contexto, `getTraceAsString`) THEN SHALL NOT conter a URL, o host, a query string nem o fragmento fornecidos.
3. WHEN a política roda com log capturado (`Log::fake`/spy), em caso de aceitação **e** de rejeição THEN nenhum registro SHALL conter a URL, a query string ou o fragmento.
4. WHEN um valor é cifrado THEN nenhum log SHALL conter o plaintext, o envelope ou material de chave.
5. WHEN o motivo é publicado como atributo de observabilidade THEN SHALL ser somente o nome do enum, cuja cardinalidade SHALL ser fixa e não derivada de entrada do usuário.
6. WHEN dois destinos inválidos por motivos diferentes são submetidos THEN a resposta pública SHALL ser indistinguível entre eles.

**Independent Test**: Teste com `Log::fake` e sentinela: submeter URLs contendo um token único na query e no fragmento e afirmar zero ocorrências do token em logs, mensagem e trace da exceção — mesmo padrão de sentinela já usado no gate E2E de Auth.

---

## Edge Cases

- `https://example.com` (path vazio) → aceito, normalizado para `https://example.com/`.
- Entrada com exatamente 2.048 e com 2.049 caracteres → aceita e rejeitada, respectivamente.
- Entrada de 2.048 caracteres cuja normalização remove `:443` → aceita; valor persistido com 2.044.
- `https://example.com:0443/x` (porta com zero à esquerda) → normalizada para `https://example.com/x`.
- `https://example.com:00080/x` → normalizada para `http`? **Não**: esquema nunca muda; `:00080` em `https` é porta 80 customizada e SHALL ser preservada como `:80`.
- `https://example.com/#` (fragmento vazio) e `https://example.com/?` (query vazia) → preservados como vieram.
- `https://xn--caf-dma.com/x` → aceito; `https://café.com/x`, `https://example.com/pá` e `https://example.com/q?a=á` → rejeitados (`NON_ASCII_INPUT`).
- `https://example.com\t/x` e `https://example.com/x%0d%0aSet-Cookie:%20a` → o primeiro rejeitado (`CONTROL_CHARACTER`); o segundo **aceito**, pois o CRLF está percent-encoded e não injeta header — a proteção do `Location` é da fatia 10.
- ` https://example.com/x ` (espaços na borda) → aceito após trim.
- `https://example.com./x` → aceito, ponto final removido.
- `HTTPS://GO.LOCALHOST./abc` → rejeitado (`SELF_HOST`), provando que a comparação ocorre após normalizar host.
- `https://mylocalhost.com/x` e `https://internal-tools.com/x` → **aceitos**: a denylist é por label/sufixo, não por substring.
- `https://8.8.8.8/x` (IP público literal) → rejeitado, junto com os privados.
- `https://example.com:65536/x` e `https://example.com:-1/x` → rejeitados (`INVALID_PORT`).
- Host com 253 e 254 caracteres → aceito e rejeitado, respectivamente.
- URL cujo hostname público resolve para `10.0.0.5` → **aceito** por decisão explícita (sem DNS); registrado aqui para que o Verifier não o trate como falha de cobertura.
- `self_hosts` vazio na configuração → a política continua válida; nenhum host próprio é bloqueado e o boot SHALL falhar explicitamente, já que `SHORT_HOST` é obrigatório no ambiente.

---

## Requirement Traceability

| Requirement ID | Catálogo | Story | Requisito | Status |
| --- | --- | --- | --- | --- |
| LDST-01 | LNK-20 | P1: Política | Esquema `http`/`https` obrigatório | Pending |
| LDST-02 | LNK-20 | P1: Política | Limite de 2.048 na entrada crua | Pending |
| LDST-03 | LNK-20 | P1: Normalização | Limite de 2.048 no valor normalizado | Pending |
| LDST-04 | LNK-23 | P1: Política | Rejeição de caracteres de controle | Pending |
| LDST-05 | LNK-23 | P1: Política | Rejeição de `userinfo` em todas as formas | Pending |
| LDST-06 | LNK-23 | P1: Política | Rejeição de percent-encoding malformado | Pending |
| LDST-07 | LNK-21 | P1: Política | Parse por parser de URL, sem busca textual | Pending |
| LDST-08 | LNK-21 | P1: Política | Sintaxe de hostname (labels, comprimento, hífen, TLD) | Pending |
| LDST-09 | LNK-21 | P1: Política | Rejeição de qualquer byte não-ASCII na entrada; `xn--` aceito | Pending |
| LDST-10 | LNK-22 | P1: Política | Rejeição de literal IPv4 em qualquer notação | Pending |
| LDST-11 | LNK-22 | P1: Política | Rejeição de literal IPv6 | Pending |
| LDST-12 | LNK-22 | P1: Política | Denylist de nomes de uso especial | Pending |
| LDST-13 | LNK-22 | P1: Política | Bloqueio dos hosts próprios (`SHORT_HOST` + `APP_URL`) e subdomínios | Pending |
| LDST-14 | LNK-24 | P1: Política | Validação de porta (1–65535) | Pending |
| LDST-15 | LNK-24 | P1: Normalização | Remoção de porta padrão redundante e porta vazia | Pending |
| LDST-16 | LNK-24 | P1: Normalização | Preservação de porta customizada válida | Pending |
| LDST-17 | LNK-25 | P1: Normalização | Preservação byte a byte de query string e fragmento | Pending |
| LDST-18 | LNK-25 | P1: Normalização | Preservação de path, percent-encoding e `.`/`..` | Pending |
| LDST-19 | LNK-25 | P1: Normalização | Canonicalização de esquema, host e path vazio | Pending |
| LDST-20 | LNK-25 | P1: Normalização | Idempotência da normalização | Pending |
| LDST-21 | LNK-26 | P1: Cifra | Cifra somente a partir de valor validado, com `key_id` ativo | Pending |
| LDST-22 | LNK-26 | P1: Cifra | Revalidação obrigatória antes de cada persistência de versão | Pending |
| LDST-23 | LNK-20…26 | P2: Erro | Código público único `INVALID_DESTINATION_URL` + motivo interno | Pending |
| LDST-24 | LNK-20…26 | P2: Erro | Zero vazamento de URL, query e fragmento em log, exceção e trace | Pending |

**Status values:** Pending → In Design → In Tasks → Implementing → Verified

**Coverage:** 24 requisitos, 0 mapeados para tasks (Tasks ainda não gerado).

---

## Success Criteria

- [ ] Toda URL da matriz de rejeição é reprovada pelo motivo correto, e toda URL da matriz de aceitação é normalizada exatamente no valor esperado.
- [ ] Nenhuma URL rejeitada alcança `DestinationCipher::encrypt` (zero invocações provadas por spy).
- [ ] Round-trip cifra → decifra devolve o valor normalizado idêntico, incluindo query string e fragmento.
- [ ] Sentinela plantada em query e fragmento não aparece em log, mensagem de exceção nem trace (zero ocorrências).
- [ ] Nenhuma consulta DNS ou HTTP é feita durante a validação; a suíte roda sem rede.
- [ ] Cobertura de `modules/Links` mantém 90% linhas / 85% métodos (`docs/testing.md` §4).
- [ ] As fatias 4 e 7 conseguem consumir a política sem reimplementar nenhuma regra.

---

## Referências

| Documento | Uso |
| --- | --- |
| `docs/security.md` §8.1, §13, §14 | URL policy, proibição de destino em telemetria, keyrings |
| `docs/data-model.md` §4 | `link_destination_versions`, validação e normalização do destino |
| `docs/api.md` §2, §4.6, §7 | Envelope de erro, contrato público de destinos, códigos estáveis |
| `docs/testing.md` §6.3, §4 | Casos obrigatórios de destino e gate de cobertura |
| `docs/architecture.md` §6.1 | Posição da validação no fluxo de criação e alteração |
| `.specs/features/links/foundation/spec.md` | `DestinationUrl` mínimo, `DestinationCipher`, keyring (LFND-10 … LFND-15) |
