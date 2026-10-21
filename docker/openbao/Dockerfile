# syntax=docker/dockerfile:1
# Optional named public module cache speeds local verification; default CI fetches checksum-verified modules.
FROM scratch AS module-cache
FROM golang:1.27.3-alpine@sha256:f2c872bcee949b7be9c490efd02b48a8a2fbf947cdf533f1c58525ec9d8170fd AS build
ENV CGO_ENABLED=0 GOTOOLCHAIN=local GOMAXPROCS=2 GOGC=40 GOMEMLIMIT=1800MiB
ARG GOPROXY=https://proxy.golang.org,direct
ENV GOPROXY=$GOPROXY
ADD --checksum=sha256:4d69328fa9110734f7740547f5aa7fc77f32141eaab9f7e965abb4f748c4a81a https://codeload.github.com/openbao/openbao/tar.gz/refs/tags/v2.7.1 /tmp/source.tar.gz
RUN mkdir /build && tar -xzf /tmp/source.tar.gz --strip-components=1 -C /build
WORKDIR /build
# x/net security update and its required Go module versions; readonly build freezes all transitive choices.
COPY go.mod go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    --mount=type=bind,from=module-cache,target=/module-cache \
    go build -p 1 -mod=readonly -trimpath -tags=openbao \
    -ldflags="-s -w -X github.com/openbao/openbao/v2/internal/version.fullVersion=2.7.1 -X github.com/openbao/openbao/v2/internal/version.VersionMetadata=vkposter-security -X github.com/openbao/openbao/v2/internal/version.GitCommit=a5db72cef75c24b920ade02065b18dd8eb666bac -X github.com/openbao/openbao/v2/internal/version.CommitDate=2026-10-01T15:32:54Z" -o /bao .
FROM quay.io/openbao/openbao:2.7.1@sha256:6d2b93856e3fcf7b18ad855a0b51eaba474dc8b79cf554379ea32034797d2acf
COPY --from=build --chmod=0555 /bao /usr/bin/bao
# An operator must supply initialized durable storage/TLS/unseal policy. Never default to a dev server.
CMD ["server", "-config=/openbao/config"]
