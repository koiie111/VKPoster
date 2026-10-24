# Patch distro security packages without changing the Redis major/runtime contract.
FROM redis:7-alpine@sha256:858f009f9709ce576febc734aa78b8f6d624b82571f9ddb6bda4377c833b3499
RUN apk upgrade --no-cache \
    && apk info -e libcrypto3 libssl3
