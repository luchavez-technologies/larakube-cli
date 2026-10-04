# A LaraKube workspace: the language toolchain, git and a browser editor (code-server, MIT).
FROM {{ $base }}

ARG CODE_SERVER_VERSION={{ $codeServerVersion }}

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends git openssh-client curl ca-certificates unzip sudo procps \
@if ($installNode)
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
@endif
    && arch="$(dpkg --print-architecture)" \
    && curl -fsSL -o /tmp/code-server.deb "https://github.com/coder/code-server/releases/download/v${CODE_SERVER_VERSION}/code-server_${CODE_SERVER_VERSION}_${arch}.deb" \
    && apt-get install -y /tmp/code-server.deb \
    && rm -rf /tmp/code-server.deb /var/lib/apt/lists/*
@if ($phpExtensions !== [])

RUN install-php-extensions {{ implode(' ', $phpExtensions) }}
@endif

# One home and one user id for every runtime, whatever the base already has at 1000.
RUN if id -u 1000 >/dev/null 2>&1; then userdel -r "$(id -un 1000)" 2>/dev/null || true; fi \
    && useradd -m -u 1000 -s /bin/bash coder
USER coder
WORKDIR /home/coder
ENV SHELL=/bin/bash
EXPOSE 8080
CMD ["code-server", "--bind-addr", "0.0.0.0:8080", "--auth", "password", "/home/coder/project"]
