# Load tests (stub)

Perfil Compose `benchmark` sobe **2** workers de analytics (`analytics-worker` + `analytics-worker-2`) e 1 `notification-worker` (AD-002).

## Subir o perfil

```bash
docker compose --env-file docker/versions.env \
  -f docker-compose.yml -f docker-compose.dev.yml \
  --profile benchmark up -d --wait
```

## Variáveis relevantes

| Variável | Uso |
| --- | --- |
| `COMPOSE_PROJECT_NAME` | Isola o projeto Compose (ex.: `fake_link_bench`) |
| `REDIS_QUEUE_HOST` / `REDIS_QUEUE_PORT` | Broker da fila Redis persistente |
| `DB_*` | PostgreSQL usado pelos workers |
| `NGINX_HTTPS_PORT` | Porta HTTPS publicada (evitar conflito com stack dev) |

Cenários k6/Artillery e limiares de aceite entram em uma fase posterior do roadmap; este diretório só documenta o bootstrap do perfil.
