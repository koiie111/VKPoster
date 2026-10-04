# Playwright + axe-core for visual review. Node lives only in this image, never in the app image.
FROM mcr.microsoft.com/playwright:v1.49.1-jammy
WORKDIR /opt/ui-snap
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY snap.mjs ./
ENV NODE_PATH=/opt/ui-snap/node_modules
WORKDIR /work
ENTRYPOINT ["node", "/opt/ui-snap/snap.mjs"]
