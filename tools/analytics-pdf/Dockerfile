# Browser runtime is isolated from the PHP application; no host/public port.
FROM mcr.microsoft.com/playwright:v1.64.1-noble@sha256:7852dc5a7d72f5fe458a597d690f6c42fbc8e9e26ddfa95f8e6ef552558d9bbf
WORKDIR /opt/analytics-pdf
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund \
    && apt-get purge -y gstreamer1.0-plugins-bad libgstreamer-plugins-bad1.0-0 \
    && rm -rf /usr/lib/node_modules/npm /usr/bin/npm /usr/bin/npx \
        /ms-playwright/firefox-* /ms-playwright/webkit-* /var/lib/apt/lists/*
COPY server.mjs ./
USER pwuser
CMD ["node", "server.mjs"]
