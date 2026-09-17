{{-- Static site image: build the bundle, serve it from Caddy.

     When the framework compiles public env values into the bundle, the build
     reads .env.{env} through a BuildKit secret (readable during the build,
     never an image layer) and refuses a bundle that still points at local
     hosts. Frameworks that read no env file get a plain build. --}}
############################################
# Assets Build Stage
############################################
FROM docker.io/library/node:24-alpine AS assets
WORKDIR /app

# package.json first: dependencies only reinstall when they actually change.
COPY package*.json ./
RUN npm ci

COPY . .

@if($bakesEnv)
# The env file is mounted for this RUN only: the build reads .env.{mode} and
# compiles its public values into the output, so it must be present here, and
# must not be baked into a layer, which a COPY would do.
RUN --mount=type=secret,id=dotenv,target=/app/.env.production \
    {{ $buildCommand }}

# Refuse to ship a bundle still pointing at a developer's machine. The values
# are compiled in, so by the time the image is running nothing downstream can
# tell. `[.]` keeps the class from matching the literal string in this grep.
#
# `larakube up --preview` builds this same Dockerfile to rehearse the production
# serving layer on the local cluster, where pointing at .test hosts is the whole
# point — that is the only caller that passes STRICT_HOSTS=0. It defaults to 1,
# so a plain `docker build` and every deploy keep the guard.
ARG STRICT_HOSTS=1
RUN if [ "$STRICT_HOSTS" = "1" ] && grep -rEq "https?://[a-z0-9.-]+[.](kube|test|localhost|local|internal)([^a-z0-9-]|$)" {{ $outputDir }}; then \
      echo "ERROR: the built bundle references local hosts:"; \
      grep -rEoh "https?://[a-z0-9.-]+[.](kube|test|localhost|local|internal)" {{ $outputDir }} | sort -u; \
      echo "Point .env.{{ $environment }} at real hosts and rebuild."; \
      exit 1; \
    fi
@else
RUN {{ $buildCommand }}
@endif

############################################
# Production Image
############################################
FROM docker.io/library/caddy:{{ $caddyVersion }}-alpine

COPY --from=assets /app/{{ $outputDir }} /srv
COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 8080
