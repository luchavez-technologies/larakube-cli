{{-- Axum production image: compile a release binary against musl, then run it
     on a minimal base. Dependencies are built first against a stub main so a
     source-only change does not rebuild them. --}}
############################################
# Build
############################################
FROM docker.io/library/rust:1-alpine AS builder
RUN apk add --no-cache musl-dev
WORKDIR /src

COPY Cargo.toml Cargo.loc[k] ./
RUN mkdir src && echo 'fn main() {}' > src/main.rs \
    && cargo build --release \
    && rm -rf src

COPY src ./src
# The stub's timestamp must not make cargo think the real sources are unchanged.
RUN touch src/main.rs && cargo build --release \
    && cp target/release/{{ $config->getName() }} /out-server

############################################
# Production Image
############################################
FROM docker.io/library/alpine:3.22 AS deploy
RUN apk add --no-cache ca-certificates tzdata \
    && adduser -D -u 10001 app
WORKDIR /app

COPY --from=builder /out-server ./server

USER app
EXPOSE {{ $config->framework->containerPort() }}

CMD ["./server"]
