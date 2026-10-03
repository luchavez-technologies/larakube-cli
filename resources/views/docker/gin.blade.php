{{-- Gin production image: a static Go binary on a minimal base. The scaffold
     ships go.mod without go.sum, so the build resolves modules with
     `go mod tidy` (needs network, like any first build). --}}
############################################
# Build
############################################
FROM docker.io/library/golang:1.27-alpine AS builder
WORKDIR /src

COPY . .
RUN go mod tidy
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/server .

############################################
# Production Image
############################################
FROM docker.io/library/alpine:3.22 AS deploy
RUN apk add --no-cache ca-certificates tzdata \
    && adduser -D -u 10001 app
WORKDIR /app

ENV GIN_MODE=release
COPY --from=builder /out/server ./server

USER app
EXPOSE {{ $config->framework->containerPort() }}

CMD ["./server"]
