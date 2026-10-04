# A LaraKube workspace: PHP, Composer, Node, git and a browser editor (code-server, MIT).
FROM php:{{ $php }}-cli-bookworm

ARG CODE_SERVER_VERSION={{ $codeServerVersion }}

RUN apt-get update \
    && apt-get install -y --no-install-recommends git openssh-client curl ca-certificates unzip sudo procps \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && arch="$(dpkg --print-architecture)" \
    && curl -fsSL -o /tmp/code-server.deb "https://github.com/coder/code-server/releases/download/v${CODE_SERVER_VERSION}/code-server_${CODE_SERVER_VERSION}_${arch}.deb" \
    && apt-get install -y /tmp/code-server.deb \
    && rm -rf /tmp/code-server.deb /var/lib/apt/lists/*

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN install-php-extensions pdo_mysql pdo_pgsql intl zip gd bcmath pcntl redis

RUN useradd -m -u 1000 -s /bin/bash coder
USER coder
WORKDIR /home/coder
ENV SHELL=/bin/bash
EXPOSE 8080
CMD ["code-server", "--bind-addr", "0.0.0.0:8080", "--auth", "password", "/home/coder/project"]
