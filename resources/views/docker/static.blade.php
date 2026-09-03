{{-- Static site image: build the bundle, serve it from Caddy.

     Mirrors docker/php.blade.php's `assets` stage deliberately, including the
     BuildKit secret: VITE_* values are compiled INTO the bundle at build time,
     so .env.{env} has to be readable during the build but must never survive
     as an image layer. --}}
############################################
# Assets Build Stage
############################################
FROM node:24-alpine AS assets
WORKDIR /app

# package.json first: dependencies only reinstall when they actually change.
COPY package*.json ./
RUN npm ci

COPY . .

# The env file is mounted for this RUN only. Vite reads .env.{mode} at BUILD
# time and bakes VITE_-prefixed values into the output, so it must be present
# here — and must not be baked into a layer, which a COPY would do.
RUN --mount=type=secret,id=dotenv,target=/app/.env.production \
    {{ $buildCommand }}

# Refuse to ship a bundle still pointing at a developer's machine. Vite compiles
# these values in, so by the time the image is running nothing downstream can
# tell. `[.]` keeps the class from matching the literal string in this grep.
RUN if grep -rEq "https?://[a-z0-9.-]+[.](kube|test|localhost|local|internal)([^a-z0-9-]|$)" {{ $outputDir }}; then \
      echo "ERROR: the built bundle references local hosts:"; \
      grep -rEoh "https?://[a-z0-9.-]+[.](kube|test|localhost|local|internal)" {{ $outputDir }} | sort -u; \
      echo "Point .env.{{ $environment }} at real hosts and rebuild."; \
      exit 1; \
    fi

############################################
# Production Image
############################################
FROM caddy:{{ $caddyVersion }}-alpine

COPY --from=assets /app/{{ $outputDir }} /srv
COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 8080
