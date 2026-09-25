# syntax=docker/dockerfile:1.7
# `dev` runs the Vite dev server against the bind-mounted source; `web` builds
# the one static bundle every installation is served and hands it to Nginx.
# Nothing environment-specific is baked in either way.

FROM node:22-alpine AS base
WORKDIR /app
ENV CI=1

FROM base AS deps
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund

FROM base AS build
COPY --from=deps /app/node_modules ./node_modules
COPY frontend/ ./
RUN npm run build

FROM base AS dev
ARG UID=1000
# npm writes its cache under $HOME; the host UID has no home in this image.
ENV HOME=/tmp
USER $UID
EXPOSE 8080
CMD ["npm", "run", "dev"]

FROM nginx:1.27-alpine AS web
COPY docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 8080
