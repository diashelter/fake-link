# Docker Foundation Context

**Gathered:** 2026-07-21  
**Spec:** `.specs/features/docker-foundation/spec.md`  
**Status:** Ready for design

---

## Feature boundary (locked)

Infraestrutura container do Fake Link: `docker/`, Compose, Dockerfiles, Nginx, TLS local, datastores, workers, perfis e Makefile. Stubs mínimos de readiness nas apps; scaffold completo de domínio fica fora.

---

## Decisions locked

### TLS local

**Decisão:** script OpenSSL versionado em `docker/nginx/certs/` gera CA + certificados para `app.localhost` e `go.localhost`.

**Rationale:** reproduzível em qualquer máquina com Docker/OpenSSL, sem depender de mkcert instalado no host. Trade-off aceito: desenvolvedor importa a CA local uma vez (documentado no README/Makefile).

**Rejeitado:** mkcert como dependência obrigatória do bootstrap.

### Workers no profile padrão (dev)

**Decisão:** 1 `analytics-worker` + 1 `notification-worker`.

**Rationale:** menor uso de RAM no dia a dia. Profiles `benchmark` e produção usam 2+1 conforme arquitetura.

### Interface de comandos

**Decisão:** `Makefile` na raiz como interface única (`make up`, `make down`, `make migrate`, etc.). README referencia os targets, não duplica comandos longos.

### Portas de datastore no host

**Decisão:** profile padrão (`dev`) publica PostgreSQL e ambos Redis no host com portas mapeadas fixas documentadas. Profiles `test` e produção **não** publicam essas portas.

**Rejeitado:** opt-in via `EXPOSE_DATASTORE_PORTS` — simplifica DX local; prod/test permanecem fechados.

### Pin de versões

**Decisão:** arquivo canônico `docker/versions.env` com tags exatas abaixo, revisado no scaffold e em bumps de segurança.

| Componente | Versão pinada | Notas |
| --- | --- | --- |
| PHP | `8.4.23` | Active support; compatível com Laravel 13 (8.3–8.5) |
| Laravel | `13.16.1` | Active LTS da framework; security até 2028-03 |
| Composer | `2.10.2` | getcomposer.org stable |
| Node.js | `24.18.0` | Node.js 24 Active LTS latest |
| pnpm | `11.15.1` | Via Corepack no Dockerfile frontend |
| Next.js | `16.2.11` | Active LTS; patch de segurança jul/2026 |
| PostgreSQL | `18.4` | Imagem oficial; PGDATA versionado em `/var/lib/postgresql/18/docker` |
| Redis | `8.8.0` | Ambas instâncias; tri-license 8.x |
| Nginx | `1.30.4` | Tag `stable`; não mainline |

Imagens base preferidas: variantes Debian (`bookworm`/`trixie`) nas imagens oficiais, salvo necessidade documentada de Alpine.

### Stubs de readiness

**Decisão:**

- Backend Laravel: `GET /health` retorna `200` JSON `{"status":"ok"}`.
- Frontend Next.js: `GET /health` retorna `200` JSON `{"status":"ok"}`.
- Nginx expõe TLS nos dois vhosts; health checks HTTP(S) internos conforme design.

### Estrutura `docker/`

**Decisão:** `docker/nginx/`, `docker/php/`, `docker/node/`, `docker/postgres/`, `docker/redis/`, `docker/scripts/` (TLS, bootstrap helpers).

### Swagger UI

**Decisão:** serviço no profile `docs`, acessível em `https://app.localhost/docs` via Nginx.

### Compose de produção

**Decisão:** `docker-compose.prod.yml` override com limites de recurso, `restart: unless-stopped`, sem bind mounts de código, datastores não publicados, workers 2+1, profile observabilidade completo (implementação P2).

---

## Deferred ideas (out of scope)

- GitHub Actions build/publish GHCR
- Ansible / Cloudflare / Tailscale
- SOPS/age
