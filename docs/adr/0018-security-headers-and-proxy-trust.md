# ADR-0018: The proxy sets the security headers and decides who the client is

- Status: accepted
- Date: 2026-09-29

## Context

M1 (ROADMAP.md) is about running Kanso for a pilot merchant on a real host, and the entry point was not ready for that:

- **No security headers.** The only `add_header` lines were `Cache-Control` in the web image. CLAUDE.md asks for a strict Content Security Policy.
- **The API never learned the real scheme or client.** `framework.yaml` reads `TRUSTED_PROXIES`, but nothing set it. The proxy sent `X-Forwarded-Proto: $scheme`, which is `http` behind a TLS terminator, and appended to whatever `X-Forwarded-For` a client sent. Behind TLS, the refresh cookie was set without `Secure` (`$request->isSecure()`), and the login limiter keyed every client on the proxy's address.
- **Readiness was public.** The proxy forwarded all of `/health`, and `/health/ready` returns raw exception messages. `/metrics` was not routed to the API, but the single-page app answered it with a 200.
- **Nginx ran as root** in the web image and in the proxy, which was the stock image with a mounted file.
- **The web image served the bundle uncompressed.**

## Decision

- **Security headers at the proxy, in one file.** `docker/proxy/security-headers.conf` is included at server level and sets `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, a `Permissions-Policy` that turns off sensors, camera, microphone, geolocation, payment and USB, and `Strict-Transport-Security` over TLS only. Each carries `always`, so error responses get them too. The proxy hides an upstream's copy of each (API Platform sends its own `X-Content-Type-Options`), and hides `X-Powered-By`. Nginx drops every inherited `add_header` in a location that declares one of its own, so such a location must include the file again. `Referrer-Policy: same-origin` rather than `no-referrer`, because `no-referrer` turns a same-origin POST's `Origin` into `null`.
- **The policy allows what the built bundle loads, and nothing more:** `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'` (`csp.conf`).
  - The bundle is one module script and one stylesheet from its own origin. It has no inline scripts or styles, no fonts, no `eval` and no `data:` URIs. Zod 3 does not compile with `new Function`.
  - Styles that React sets on elements go through the CSSOM, which `style-src` does not govern.
  - A PDF is a presigned URL on object storage, a different origin. It is opened as a link in a new tab, which is a navigation and not a fetch, so the policy needs no entry for it. If the app ever fetches from or embeds object storage, `connect-src` or `frame-src` will need `S3_PUBLIC_ENDPOINT`'s origin, which differs per installation. The policy would then have to be rendered from the environment.
  - API responses get `default-src 'none'; frame-ancestors 'none'` (`api.conf`), because JSON is never a page to render.
- **Only the Vite dev server gets a looser policy.** `csp-dev.conf` adds `'unsafe-inline'` to `script-src` for the React Refresh preamble that the plugin writes inline into `index.html`. It adds `'unsafe-inline'` to `style-src` for CSS injected as `<style>` elements, and `ws: wss:` to `connect-src` for hot reload. `compose.yaml` mounts it over `csp.conf`. The image, the e2e proxy and `compose.prod.yaml` use the strict policy. A hash of the preamble was rejected: its text changes with `@vitejs/plugin-react`, and a stale hash blanks the dev app.
- **The proxy decides the client's address and scheme, and the API trusts the proxy.**
  - `PROXY_TRUSTED_PROXIES` lists the addresses of a TLS terminator in front of the proxy: addresses or CIDR ranges, or `PRIVATE_SUBNETS`. It is empty by default. At start, `trusted-proxies.sh` renders it into `set_real_ip_from` lines and a `geo` for Nginx, and a malformed entry stops the container.
  - The proxy believes `X-Forwarded-For`, `-Proto` and `-Port` from those peers only. It then *replaces* all three on the way to the API with what it worked out, and drops `X-Forwarded-Host` and `Forwarded`.
  - `TRUSTED_PROXIES` for the API defaults to `PRIVATE_SUBNETS` in `compose.yaml`, which covers the proxy at whatever address Docker gives it. The API trusts more than the proxy's single address, but that only matters to a client that can reach the API container directly, so its port is never published.
  - The trust decision is made once, at the edge. Two lists, one in Nginx and one in Symfony, would have to agree.
  - The proxy trusts nobody by default. Trusting private ranges would let a browser on a LAN (an on-site warehouse, a development machine) claim any address and step around the per-address login limit.
- **HSTS for one year, without `includeSubDomains` or `preload`.** It is sent only when the scheme the proxy worked out is `https`. The host name is the merchant's, and other services under it are not Kanso's to pin. A plain-HTTP installation is never pinned.
- **Readiness and metrics are not public.** The proxy forwards `/health/live` only. `/health`, `/health/ready` and `/metrics` get a 404 from the proxy itself. A platform probes readiness on the API container, as Prometheus scrapes `/metrics` there. This is still protection by routing alone: the M1 item that protects `/metrics` in the API and takes exception messages out of readiness stays open.
- **The web and proxy images run as `nginx` (uid 101) on 8080.** Both use the stock `nginx:1.27-alpine` image with a main configuration of their own: no `user` directive, and the pid and temp files in `/tmp`. `USER` is numeric, so a platform can verify that it is not root. The proxy is now an image (`docker/proxy/Dockerfile`) with the routes, headers and strict policy built in. The e2e proxy is the same image with `e2e.conf` mounted. `nginxinc/nginx-unprivileged` would give the same result, but it is one more base image to trust.
- **Compression in the web image:** gzip at level 5 for text types, with `gzip_proxied any` and `gzip_http_version 1.0`. Nginx proxies over HTTP/1.0 unless told otherwise (`e2e.conf` does), and the defaults would then compress nothing. The bundle's script shrinks from 833 kB to 242 kB. The proxy does not compress; the API already compresses its JSON.

## Consequences

- The e2e suite (`e2e/tests/security.spec.js`) checks the headers on the app and the API, including on errors. It checks that metrics and readiness are unreachable, and that a request from a trusted terminator gets HSTS and a `Secure` refresh cookie. It also fails on any policy violation while signing in, listing orders, opening an order and opening its pick list.
- A new origin (web fonts, a CDN, error reporting, an embedded PDF viewer) needs a policy change, which that test will demand.
- Violations in operators' browsers go unseen: there is no `report-to` endpoint. One could be added to the API later.
- `camera=()` rules out camera barcode scanning until the policy allows it.
- Once a browser has seen HSTS, the host stays on HTTPS for a year. Moving an installation back to plain HTTP needs a new host name.
- A deployment that uses its own reverse proxy instead of the proxy image must send the same headers and forward the same way. The images alone set none.
