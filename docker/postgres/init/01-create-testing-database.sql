-- Creates the dedicated testing database on first Postgres volume init.
-- Re-run manually if an existing dev volume predates this script:
--   docker compose exec postgres psql -U fake_link -d fake_link -f /docker-entrypoint-initdb.d/01-create-testing-database.sql

SELECT 'CREATE DATABASE fake_link_testing OWNER fake_link'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'fake_link_testing')\gexec

GRANT ALL PRIVILEGES ON DATABASE fake_link_testing TO fake_link;
