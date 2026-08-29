############################################
# Base Image
############################################

# Learn more about the Server Side Up PHP Docker Images at:
# https://serversideup.net/open-source/docker-php/
FROM serversideup/php:{{ $config->getPhpVersion()->value }}-{{ $config->getServerVariation()->value }}{{ $config->getOsSuffix() }} AS base

@if($config->hasPhpExtensions())
USER root
RUN install-php-extensions {{ implode(' ', $config->getAllPhpExtensions()) }}
USER www-data
@endif

############################################
# Development Image
############################################
FROM base AS development

# We can pass USER_ID and GROUP_ID as build arguments
# to ensure the www-data user has the same UID and GID
# as the user running Docker.
ARG USER_ID
ARG GROUP_ID

# Switch to root so we can set the user ID and group ID
USER root

# Vite HMR runs in a local `node` pod built from this development image
# (`npm run dev`), so Node.js is always required here — independent of SSR.
RUN {{ $config->getOs()->getNodeInstallCommand() }}

# Match www-data's UID/GID to the host user (for hostPath code mounts locally).
RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID  && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID && \
    mkdir -p storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

# Drop privileges back to www-data
USER www-data

############################################
# CI image
############################################
FROM base AS ci

# Sometimes CI images need to run as root
USER root

############################################
# Assets Build Stage
# Runs `npm run build` inside Docker so the compiled JS always reflects the
# correct per-environment VITE_* values and the host's public/build/ is never
# touched. Uses the PHP base image so Wayfinder's Vite plugin can call
# `php artisan wayfinder:generate` without a stub. The per-environment .env
# file is mounted via BuildKit secret (id=dotenv) so VITE_* vars are visible
# to Vite at build time but never baked into any image layer.
############################################
FROM base AS assets
USER root
RUN {{ $config->getOs()->getNodeInstallCommand() }}
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
RUN --mount=type=secret,id=dotenv,target=/var/www/html/.env \
    npm ci && npm run build

############################################
# Production Image
############################################
FROM base AS deploy

# Switch to root to fix permissions
USER root
@if($config->hasFeature(\App\Enums\LaravelFeature::SSR))

# Inertia SSR runs `node bootstrap/ssr/ssr.js` from this production image, so
# Node.js is required here. Non-SSR apps serve pre-built static assets and never
# run Node, so it's omitted to keep the image lean.
RUN apk add --no-cache nodejs npm
@endif

# Copy application files
COPY --chown=www-data:www-data . /var/www/html

# Overlay assets built inside Docker (correct VITE_* baking, no host pollution)
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build /var/www/html/public/build

# Ensure storage and bootstrap are owned by www-data
# Sub-paths will be handled by K8s volume mounts
RUN mkdir -p storage bootstrap/cache && \
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
    mkdir -p .infrastructure/volume_data/sqlite && \
    chown -R www-data:www-data storage bootstrap/cache .infrastructure/volume_data/sqlite && \
@else
    chown -R www-data:www-data storage bootstrap/cache && \
@endif
    chmod -R 775 storage bootstrap/cache

# Drop privileges back to www-data
USER www-data
