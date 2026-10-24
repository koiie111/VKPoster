# Rebuild the same stable server with patched Go/HTTP2 modules; do not suppress reachable findings.
FROM golang:1.27.3-alpine@sha256:f2c872bcee949b7be9c490efd02b48a8a2fbf947cdf533f1c58525ec9d8170fd AS build
ENV CGO_ENABLED=0 GOTOOLCHAIN=local GOMAXPROCS=2
WORKDIR /build
COPY build/go.mod build/go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY build/main.go ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go build -mod=readonly -trimpath -ldflags="-s" -o /caddy .

FROM caddy:2.11.7-alpine@sha256:d8542f48d34a9cf4e4c11a478865229840e87e4c96ea3f439101f31a5d35f75f
RUN apk upgrade --no-cache
COPY --from=build /caddy /usr/bin/caddy
RUN caddy version
