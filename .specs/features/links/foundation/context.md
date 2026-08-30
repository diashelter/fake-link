# Links — Fundação dos módulos · Contexto

**Spec**: `.specs/features/links/foundation/spec.md`
**Capturado em:** 2026-08-30

Registro das gray areas discutidas com o mantenedor durante o Specify. Estas decisões são **travadas** para Design e Execute — divergir delas exige nova conversa, não julgamento do agente.

---

## D1 — Registro de rotas dos módulos

**Pergunta:** `Links` e `Redirects` compartilham um único `ServiceProvider` de rotas ou um por módulo?

**Decisão:** **Um provider por módulo.**

- `Modules\Links\ServiceProviders\LinksServiceProvider` — reserva o prefixo `api/v1/links` (host da aplicação).
- `Modules\Redirects\ServiceProviders\RedirectsServiceProvider` — reserva a superfície do host curto.
- Ambos registrados em `backend/bootstrap/providers.php`.

**Razão:** segue o precedente do `AuthServiceProvider`, mantém a fronteira de `docs/architecture.md` §4.2/§4.3 e evita que o bootstrap de `Redirects` dependa de `Links`.

**Alternativa descartada:** provider único de rotas roteando por `server_name` — menos arquivos, mas acopla os dois módulos no bootstrap e contraria o seam que a própria fatia precisa provar.

---

## D2 — Local do keyring de destinos

**Pergunta:** o keyring de destinos entra como serviço em `Shared` ou dentro de `Links`?

**Decisão:** **porta e adaptador dentro de `Links`.**

- `Modules\Links\Contracts\Services\DestinationCipher` (porta).
- `Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher` (adaptador).
- **Nenhum módulo `Shared` é criado nesta fatia.**

**Razão:** `docs/architecture.md` §4.6 — `Shared` só recebe contratos sem owner de domínio. "Destino" é vocabulário de Link, então pertence a `Links`. `Operations` consumirá pelo contrato público quando existir.

**Alternativas descartadas:**

- `EnvelopeCipher` genérico em `Shared`: criaria o módulo `Shared` já aqui, antes de haver dois consumidores reais.
- Primitive em `Shared` + porta nomeada em `Links`: mais peças agora; a duplicação do envelope nas fatias 5 (idempotência) e 11 (cache) é aceita como custo, e a extração para `Shared` fica disponível quando o segundo consumidor existir de fato.

**Consequência aceita:** as fatias 5 e 11 podem reimplementar o envelope AES-256-GCM com seus próprios keyrings. Isso é **desejável** do ponto de vista de segurança (`docs/security.md` §14: chaves com finalidade única) e a extração futura é refatoração local.

---

## D3 — Granularidade das migrations

**Pergunta:** as migrations das três tabelas vêm em um arquivo ou em três?

**Decisão:** **três arquivos**, com timestamps crescentes na ordem `slug_reservations` → `short_links` → `link_destination_versions`.

**Razão:** a ordenação por timestamp já garante a ordem das FKs; rollback é granular e o diff de cada tabela fica legível.

**Alternativa descartada:** arquivo único — rollback atômico do conjunto, mas perde granularidade e concentra o diff.

---

## D4 — Value Objects que nascem nesta fatia

**Pergunta:** quais VOs já nascem aqui e quais nascem nas fatias 2 e 3?

**Decisão:** **tipos agora, políticas depois.**

| VO | Nesta fatia | Nas fatias 2 e 3 |
| --- | --- | --- |
| `Slug` | ASCII minúsculo `[a-z0-9-]`, 1–48 caracteres, não vazio; rejeita maiúscula sem normalizar | Base36 automático, denylist, regex de alias (bordas e hífens consecutivos), normalização de entrada, retentativa de colisão |
| `DestinationUrl` | Esquema `http`/`https`, host não vazio, ≤2.048 caracteres; preserva query e fragmento | Bloqueio de IP literal / rede privada / host próprio, userinfo, caracteres de controle, canonicalização e normalização de porta |
| `LinkStatus` | **Completo** — enum + derivação de precedência `blocked` > `expired` > `inactive` > `active` | Nada; a fatia 9 apenas consome |

**Razão:** migrations, models e futuros repositórios precisam de tipos já na fundação; puxar as políticas completas esvaziaria `slug-policy` e `destination-policy` e alargaria demais esta fatia. `LinkStatus` é exceção porque a precedência é consumida por três fatias distintas (6, 9 e 10) — duplicá-la garantiria divergência.

**Consequência aceita e documentada nas edge cases da spec:** nesta fatia, `Slug::fromString('a--b')` e `DestinationUrl::fromString('http://192.168.0.1/x')` **passam**. Isso está explícito para não gerar falsa sensação de cobertura durante a verificação.

---

## Fora da discussão (já fixado por decisão de projeto)

| Item | Fonte |
| --- | --- |
| UUID v7 gerado na aplicação para todas as entidades | AD-010, AD-012 |
| Testes com I/O de banco somente em `fake_link_testing` | AD-011 |
| Roteamento por `server_name` é responsabilidade do Nginx | AD-006 |
| Gates backend rodam somente via Docker | AD-009 |
