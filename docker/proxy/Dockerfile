# syntax=docker/dockerfile:1.7
# The reference entry point as an image: Nginx with the routes (nginx.conf),
# the security headers and the strict Content Security Policy built in. It
# runs as the stock image's unprivileged `nginx` user on 8080. The only
# setting is PROXY_TRUSTED_PROXIES (trusted-proxies.sh). ADR-0018.
#
# compose.yaml loosens the policy for the Vite dev server by mounting
# csp-dev.conf over /etc/nginx/kanso/csp.conf; nothing else should.
FROM nginx:1.27-alpine
RUN rm /etc/nginx/conf.d/default.conf
COPY docker/proxy/main.conf /etc/nginx/nginx.conf
COPY docker/proxy/security-headers.conf docker/proxy/csp.conf docker/proxy/api.conf /etc/nginx/kanso/
COPY docker/proxy/nginx.conf /etc/nginx/conf.d/kanso.conf
COPY --chmod=0755 docker/proxy/trusted-proxies.sh /docker-entrypoint.d/40-kanso-trusted-proxies.sh
# Numeric, so a platform can verify it is not root.
USER 101:101
EXPOSE 8080
