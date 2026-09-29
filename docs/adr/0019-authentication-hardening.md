# ADR-0019: Authentication hardening: sign-in and refresh limits, equal-time sign-in, refresh token families, a security log

- Status: accepted
- Date: 2026-09-29
- Amends: ADR-0007 ("Anyone signed in may print")

## Context

M1 asks for the current features to be safe to run for a pilot merchant. A review of authentication found five gaps:

1. **A Viewer could write.** `POST /api/orders/{id}/documents` and `POST /api/orders/bulk-documents` needed only Viewer (ADR-0007: "a document only reads an order"). Each request stores a `document` row, queues a Messenger job and writes a PDF to object storage. Every other write needs Operator.
2. **Sign-in was limited only per email and address** (10 attempts per 15 minutes). One password tried against many accounts, which is password spraying, was never throttled. `/api/auth/refresh` had no limit.
3. **Sign-in timing told which accounts exist.** An unknown or disabled email skipped password verification, so it answered in microseconds instead of the tens of milliseconds Argon2id takes.
4. **Refresh token consumption was not atomic** (a `GET` then a `DEL`), so two requests with one token could both succeed. A stolen token used after its owner had rotated it failed quietly and revoked nothing.
5. **The core wrote no log lines of its own.** Failed sign-ins, role changes, password changes and API key creation left no record.

## Decision

### Asking for a document needs Operator

Both document POSTs require `ROLE_OPERATOR`. `GET /api/documents/{id}` still needs only a sign-in, so a Viewer can open a document an operator asked for. The reasoning in ADR-0007 still holds for the order: printing does not change it. It does not hold for the installation, where every request stores a row, a job and a file.

### Four limits on sign-in and refresh

| Limiter (`framework.rate_limiter`) | Counts | Key | Default | Forgiven |
|---|---|---|---|---|
| `login` (as before) | every attempt | email + client address | 10 / 15 min | on success |
| `login_address` | failed sign-ins | client address | 30 / 15 min | no |
| `login_total` | failed sign-ins | the whole installation | 300 / 15 min | no |
| `refresh` | refreshes with a token that is not valid | client address | 30 / 15 min | no |

- **Failures only, for the new three.** A warehouse where everyone signs in from behind one NAT address must not be throttled for succeeding, and a legitimate client almost never presents a bad refresh token. The failure limiters are checked before the work, by consuming 0 tokens, and charged only when the attempt fails. Concurrent requests can therefore overshoot a limit by the number of requests in flight. That is acceptable.
- **A successful sign-in does not reset the address or total windows.** If it did, an attacker with one valid account could clear them between guesses.
- **A refresh without a cookie is not counted.** That is someone who is not signed in, and every load of the sign-in page sends one.
- **429 with `Retry-After`**, in seconds, as the API key limit answers. `TooManyAttempts` carries it, and `ApplicationException::headers()` gives the problem response its headers. The existing per-account limit and the password-change limit now send `Retry-After` too.
- **`login_total` is a circuit breaker against spraying from many addresses.** When it is reached, nobody can sign in anew until the window moves on. Sessions that are already open keep refreshing. An attacker can trigger this on purpose. That is the price of a total limit, and the reason it is set high.
- A project changes any limit in its own `config/packages/` (ADR-0003). Development raises all four to 10,000. The test suite keeps the limiter pool in its own Redis database (`KANSO_TEST_REDIS_DB`), and `tests/bootstrap.php` clears it when a run starts.

### Sign-in takes as long for unknown, disabled and known accounts

Every sign-in verifies exactly one password: the user's hash if the email has an account, otherwise a decoy hash with the same algorithm and cost (`PasswordHasherInterface::decoyHash()`). A disabled account is verified against its real hash and then refused. The decoy is made once and kept in `cache.app`. Making it costs as much as a verification, so making it on every request would have made the unknown email the slow one. It is made again if the hasher's settings change (`needsRehash`). It is fetched on every sign-in, not only for unknown emails, so the cache lookup does not become a signal either.

### Refresh token families

- **A sign-in starts a family, and every rotation continues it.** A family has at most one live token.
- **Redis layout.** Only SHA-256 hashes of tokens are stored:
  - `refresh_token:<hash>` → `<user>|<family>`, the live token
  - `refresh_spent:<hash>` → the same, kept for the rest of the token's life after it is spent
  - `refresh_family:<family>` → the hash of the live token, or `revoked`
  - `refresh_families:<user>` → the user's families
- **Consumption is one Lua script.** It reads the token, deletes it and writes the spent marker atomically, so only one of two requests with the same token can succeed. Issuing a successor is another script, and it refuses if the family was revoked in the meantime. A revocation therefore cannot be undone by a rotation that was already in flight.
- **Reuse ends the session.** A spent token presented again means two parties hold it, and we cannot tell which one is the owner. The whole family is revoked, which signs out the owner as well, and the event goes to the security log. The response is the same 401 as for an expired session.
- **Revocation scope.** Sign-out revokes the family of the presented token, whether that token is live or spent. A password change, a password set by an admin, or a deactivation revokes every family of the user. Other users' sessions are untouched.
- The scripts touch only keys they are given, but one script spans several keys. Under Redis Cluster those keys would need a shared hash tag. Kanso runs one Redis.

### A security log

- **Its own channel and handler.** `Application\Security\SecurityLog` writes to the Monolog channel `kanso_security`, which has its own handler (`security`, JSON on stderr by default). The main handler excludes that channel. Symfony's own `security` channel is not used because it logs every authenticated request.
- **Events:**

| `event` | Level | Fields |
|---|---|---|
| `login_succeeded` | info | `user_id`, `client_ip` |
| `login_failed` | warning | `user_id` (null when unknown), `client_ip`, `reason` (`unknown_account`, `wrong_password`, `deactivated`) |
| `throttled` | warning | `limit`, `client_ip` |
| `refresh_token_reused` | warning | `user_id`, `client_ip` |
| `password_changed` | notice | `user_id`, `actor_id` |
| `user_created` | notice | `user_id`, `role`, `actor_id` |
| `user_role_changed` | notice | `user_id`, `from`, `to`, `actor_id` |
| `user_deactivated`, `user_activated` | notice | `user_id`, `actor_id` |
| `api_key_created` | notice | `api_key_id`, `role`, `actor_id` |
| `api_key_revoked` | notice | `api_key_id`, `actor_id` |

- `actor_id` is the admin who made the change. It is the user's own id for their own password change, and null for a change made from the console. A line is written after the change is saved, and only if something changed: a repeated deactivation writes nothing.
- **What is never logged:** passwords, tokens and key values. The email typed at sign-in is not logged either, because people sometimes type a password into that field. Ids and the client address are enough to investigate.

## Consequences

- **The per-address limits need the real client address.** Without `TRUSTED_PROXIES`, every request behind a proxy comes from the proxy's address. The per-address limits would then count failures from all users together, and 30 failed sign-ins anywhere in 15 minutes would stop all new sign-ins. The reference deployment sets it (ADR-0018). An installation behind a proxy of its own must set it too.
- **Windows that refresh at the same moment would sign each other out.** When several windows load at once (a browser restoring its session, or several orders opened in new tabs), they send the same cookie. The first request rotates the token, the others present a spent one, and the family is revoked. The web UI avoids this: windows take turns refreshing under a Web Locks API lock, so the second window sends the rotated cookie (ADR-0020). Any other client that shares one refresh cookie across concurrent requests must do the same. A reuse grace window on the server was not chosen, because it would let a thief who is quick enough keep a session.
- **Existing sessions end once.** Tokens stored under the old key layout are not recognised, so everyone signs in again after the upgrade. Kanso is not deployed anywhere yet.
- **The web UI offers Print to operators only** (order page, per-shipment packing slip, `p` shortcut, bulk print), hidden from Viewers with `useCanOperate()` as other writes are. A Viewer's order list has no bulk actions left, so it shows no selection checkboxes.
- **More now depends on Redis.** A Redis flush signs everyone out and resets every limit. This feeds the M1 decision on what may be lost with Redis.
- **The security log is not an audit trail.** It lives wherever the installation ships its logs and cannot be queried in the app. The M1 item "An audit trail for users, API keys and locations" remains open.
