# Browser runtime is isolated from the PHP application; no host/public port.
FROM mcr.microsoft.com/playwright:v1.64.1-noble
WORKDIR /opt/analytics-pdf
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY server.mjs ./
USER pwuser
CMD ["node", "server.mjs"]
