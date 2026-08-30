# Links — Política de destino · Contexto

**Spec**: `.specs/features/links/destination-policy/spec.md`
**Capturado em:** 2026-08-30

Registro das gray areas discutidas com o mantenedor durante o Specify. Estas decisões são **travadas** para Design e Execute — divergir delas exige nova conversa, não julgamento do agente.

---

## D1 — Resolução DNS faz parte da política?

**Pergunta:** o classificador de host deve resolver o hostname e rejeitar quando algum registro cair em faixa privada/loopback/link-local?

**Decisão:** **não.** A política é puramente sintática — literais de IP e nomes de uso especial são rejeitados, mas nenhuma consulta DNS é feita.

**Razão:** `docs/security.md` §8.1 declara explicitamente que não há fetch server-side, preview, verificação de disponibilidade ou reputação no desenho inicial, e condiciona qualquer função desse tipo a isolamento próprio. Resolver DNS na validação introduziria I/O de rede em `POST`/`PATCH`, latência e flakiness em teste, e uma janela TOCTOU (DNS rebinding) que a própria checagem não fecha — o valor resolvido no momento da validação não é o valor resolvido no momento do redirect.

**Alternativa descartada:** resolver e bloquear IP privado. Só faria sentido junto do pacote completo que §8.1 descreve (isolamento de saída, controle de rebinding, limite de redirects e timeout próprio), que é uma fatia inteira em si.

**Consequência aceita e documentada:** `https://interno.example.com/x`, cujo A record aponta para `10.0.0.5`, é **aceito**. Isso está nas edge cases da spec para que o Verifier não trate como buraco de cobertura.

---

## D2 — Granularidade do código de erro devolvido ao cliente

**Pergunta:** cada motivo de rejeição tem código estável distinto, ou todos caem em um código genérico?

**Decisão:** **um único código público** — `422 VALIDATION_FAILED` com `errors.destination_url[].code = INVALID_DESTINATION_URL` e mensagem fixa `The destination URL is not allowed.`, idêntica para todos os motivos.

**Razão:** é literalmente o exemplo já publicado em `docs/api.md` §2, então o contrato não muda. Além disso, códigos por motivo transformariam o endpoint em oráculo: um atacante distinguiria "host existe mas é interno" de "host malformado" e mapearia a rede a partir das respostas.

**Complemento decidido junto:** o motivo específico **existe**, como enum interno (`DestinationRejectionReason`, 12 valores) carregado pela exceção de domínio, disponível para teste e métrica. Nome de motivo é cardinalidade fixa e não contém dado do usuário, então é publicável sob `docs/security.md` §13.

**Alternativa descartada:** códigos estáveis por motivo (`DESTINATION_HOST_NOT_PUBLIC`, `DESTINATION_USERINFO_PRESENT`, …). Melhoraria a mensagem de UI, mas amplia o contrato OpenAPI e revela a classificação de host ao cliente.

---

## D3 — Quais hosts próprios são bloqueados

**Pergunta:** o host curto próprio é bloqueado por configuração fixa ou por lista derivada de env?

**Decisão:** **lista derivada de env**, em `config/links.php` → `destination.self_hosts`, contendo o host de `SHORT_HOST` **e** o host de `APP_URL`. Bloqueia o host exato e qualquer subdomínio.

**Razão:** `SHORT_HOST` já é variável obrigatória validada em `docker/scripts/validate-env.sh` e usada no `docker-compose.yml`; derivar dela faz local (`go.localhost`), CI e produção rodarem o mesmo código sem lista paralela para manter. Incluir o host de `APP_URL` impede também que alguém encurte a própria API/painel, o que criaria laço entre os dois hosts.

**Alternativas descartadas:**

- Somente `SHORT_HOST` — escopo literal de `docs/api.md` §4.6, mas deixa o host da aplicação encurtável.
- Lista fixa por ambiente, sem derivar de env — explícita, porém exige manutenção paralela ao `SHORT_HOST` já validado, e diverge silenciosamente quando um ambiente muda de domínio.

---

## D4 — Entrada não-ASCII e IDN

**Pergunta:** como tratar hostname não-ASCII (`https://exemplo.café.com`)?

**Decisão:** **rejeitar qualquer byte não-ASCII na entrada crua** — em host, path, query ou fragmento. O cliente envia o host em A-label (`xn--…`) e os demais componentes já percent-encoded.

**Razão original (parcialmente incorreta) e correção:** a decisão foi tomada partindo de que `ext-intl` não está na imagem PHP e portanto não haveria como converter U-label. **Isso se mostrou errado na verificação de código:** `league/uri` 7.8.1 já está vendorizado (transitivo de `laravel/framework`) e converte `café.com` → `xn--caf-dma.com` em PHP puro, sem `ext-intl`.

A decisão **se mantém**, agora por um motivo mais forte e verificado empiricamente no container: o parser não só converte o host como **percent-encoda bytes não-ASCII de path e query** (`?a=á` → `?a=%C3%A1`). Aceitar essa entrada significaria persistir e cifrar um valor **diferente** do informado pelo usuário, quebrando a preservação byte a byte exigida por `docs/data-model.md` §4 — o oposto do que esta fatia promete. Rejeitar é o que mantém a normalização não destrutiva.

**Alternativas descartadas:**

- Adicionar `ext-intl` à imagem — desnecessário (o converter é PHP puro) e mexeria em infraestrutura sob AD-005.
- Aceitar e deixar o parser converter — mais permissivo, mas transforma a normalização em transformação com perda de fidelidade, e reintroduz as "formas ambíguas entre parsers" proibidas em `docs/security.md` §8.1.

**Consequência aceita:** `https://café.com/x` é rejeitado com o mesmo `422` genérico dos demais motivos. Se no futuro o produto quiser aceitar IDN, a mudança é local ao VO e exige uma regra explícita de conversão antes da checagem de comprimento.

---

## Fora da discussão (já fixado por decisão de projeto ou por fatia anterior)

| Item | Fonte |
| --- | --- |
| Mecanismo de cifra, envelope, AAD, keyring e rotação de `key_id` | Fatia [foundation](../foundation/spec.md), LFND-11 … LFND-15 |
| `DestinationCipher::encrypt` recebe `DestinationUrl`, nunca `string` | `design.md` da fatia 1 |
| Envelope de destino separado dos keyrings de cache e de idempotência | `docs/security.md` §14; fatias 5 e 11 |
| Testes com I/O de banco somente em `fake_link_testing` | AD-011 |
| Gates backend rodam somente via Docker | AD-009 |
| Nenhum endpoint, `FormRequest` ou entrada de OpenAPI nesta fatia | Índice do módulo — fatias 4 e 7 |
