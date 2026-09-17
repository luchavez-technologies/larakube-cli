# Source Control
.git
.github
.gitlab-ci.yml

# LaraKube Infrastructure
.infrastructure
!.infrastructure/**/local-ca.pem
!.infrastructure/conf/
.infrastructure/k8s/overlays/local/
.larakube.json

# Docker & Container Files
Dockerfile*
docker-*.yml
.dockerignore

# Secrets & Environment
.env*

# Next.js build output — rebuilt inside the image; never ship the host's copy.
.next
@if($config->framework === \App\Enums\AppFramework::WORDPRESS)

# WordPress uploads live on a volume, never in the image.
web/app/uploads/*
@endif

@if($config->framework?->isStaticSpa() || $config->framework === \App\Enums\AppFramework::NEXTJS)
# Dependencies and build output: the image runs `npm ci` and the build itself.
# A host node_modules copied over them carries the wrong platform's binaries.
node_modules
dist
build
.astro
.docusaurus
@else
# vendor/ ships in the image: every build path runs composer install first
# (CI jobs and cloud:deploy alike), and the deploy stage copies the context.
@if(! $config->getGithubActions())
node_modules
@endif
@endif
@unless($config->framework?->isStaticSpa() || $config->framework === \App\Enums\AppFramework::NEXTJS)

# Laravel Specifics
# Vite HMR marker. If this ships in an image, Laravel's Vite directive serves
# every asset URL from the local Vite dev server instead of the built
# manifest — breaking assets in production. It's dev-only and must never ship.
public/hot

storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
storage/logs/*
!storage/framework/cache/.gitignore
!storage/framework/sessions/.gitignore
!storage/framework/views/.gitignore
!storage/logs/.gitignore
@endunless
