ARG NGINX_VERSION=1.30.4

FROM nginx:${NGINX_VERSION}

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

COPY conf.d/ /etc/nginx/conf.d/
COPY snippets/ /etc/nginx/snippets/
COPY certs/ /etc/nginx/certs/
