# Fake Link — Backend

Laravel API Only: JSON API (`/api/v1`) and the Short host surface (`/`, `/robots.txt`, `GET /{slug}`). There is no Blade/Vite UI in this package; the product UI lives in `frontend/` (Next.js).

## Stack

- PHP / Laravel (API Only)
- PostgreSQL, Redis
- PestPHP, Pint, Larastan, PHPMD

## Local development

Use Docker from the repository root. See the root [README](../README.md) for bootstrap, Makefile targets (`make test-backend`, `make lint-backend`), and troubleshooting.

## Routes

| Surface | Path | Notes |
| --- | --- | --- |
| Ops | `GET /health` | Liveness JSON |
| API | `GET /api/v1/health` | Versioned health (API middleware stack) |
| Short host | `GET /`, `GET /robots.txt`, `GET /{slug}` | Redirect stubs |

Route files: `routes/api.php` (API) and `routes/web.php` (Short host + `/health`).
