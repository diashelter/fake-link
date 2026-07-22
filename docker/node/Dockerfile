ARG NODE_VERSION=24.18.0
ARG PNPM_VERSION=11.15.1
ARG NEXT_VERSION=16.2.11

FROM node:${NODE_VERSION}-bookworm-slim AS base

ARG PNPM_VERSION
ARG NEXT_VERSION

ENV PNPM_HOME="/pnpm" \
    PATH="/pnpm:${PATH}" \
    NEXT_TELEMETRY_DISABLED=1

RUN corepack enable \
    && corepack prepare "pnpm@${PNPM_VERSION}" --activate \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

FROM base AS dev

ENV NODE_ENV=development

CMD ["pnpm", "dev"]

FROM base AS deps

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile

FROM deps AS build

COPY . .
RUN pnpm build

FROM base AS prod

ENV NODE_ENV=production

COPY --from=build /app/.next ./.next
COPY --from=build /app/public ./public
COPY --from=build /app/package.json ./package.json
COPY --from=build /app/pnpm-lock.yaml ./pnpm-lock.yaml
COPY --from=build /app/node_modules ./node_modules

CMD ["pnpm", "start"]
