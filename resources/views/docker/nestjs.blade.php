{{-- NestJS production image: compile with the Nest CLI, then run the compiled
     server on plain Node with production dependencies only.

     `nest build` writes dist/main.js (the `start:prod` script runs exactly
     that). Runtime configuration arrives through the environment at run time,
     so nothing from .env is needed while building. --}}
############################################
# Dependencies
############################################
FROM docker.io/library/node:24-alpine AS dependencies
WORKDIR /app

# package.json first: dependencies only reinstall when they actually change.
COPY package*.json ./
RUN npm ci

############################################
# Builder
############################################
FROM docker.io/library/node:24-alpine AS builder
WORKDIR /app

COPY --from=dependencies /app/node_modules ./node_modules
COPY . .

@if($hasPrisma)
# The app imports @prisma/client, which must be generated before compiling.
RUN npx --yes prisma@6 generate

@endif
RUN npm run build

# Keep only what the server needs at run time.
RUN npm prune --omit=dev

############################################
# Production Image
############################################
FROM docker.io/library/node:24-alpine AS deploy
WORKDIR /app

ENV NODE_ENV=production
ENV PORT={{ $config->framework->containerPort() }}

COPY --from=builder --chown=node:node /app/package*.json ./
COPY --from=builder --chown=node:node /app/node_modules ./node_modules
COPY --from=builder --chown=node:node /app/dist ./dist
@if($hasPrisma)
# Schema for the migrate init container (`prisma migrate deploy`).
COPY --from=builder --chown=node:node /app/prisma ./prisma
@endif

USER node
EXPOSE {{ $config->framework->containerPort() }}

CMD ["node", "dist/main.js"]
