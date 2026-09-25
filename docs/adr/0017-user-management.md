# ADR-0017: Admins manage users in the browser; there is always an active admin

- Status: accepted
- Date: 2026-09-25

## Context

Users could only be added with `make user` or `kanso:user:create`. A pilot merchant cannot add a new warehouse worker, change someone's role, or lock out someone who has left without a person at a shell. Kanso sends no email yet (customer and internal notifications are Phase 2), so there is no way to deliver an invitation link.

## Decision

- **Admin-only REST endpoints** under `/api/users`: list (search, `status=active|deactivated`, sort, paging), read, create, merge-patch `email`/`name`/`role`, `POST …/deactivate`, `POST …/activate` and `POST …/password`. An API key can never have the admin role, so no integration can manage users.
- **Create with a first password, no invitation.** The admin types a first password and passes it on; the person changes it under Settings. An invitation by email waits for Phase 2 notifications.
- **One role per user** in the API and UI: admin, operator or viewer, each including the ones below. The `roles` column stays a JSON list, so a user created from the console with several roles shows as the highest of them, and an update stores just the one.
- **At least one active admin, always.** Taking the admin role from, or deactivating, the last active admin is a 409 with the code `last_admin`. The check locks the active admins (`SELECT … FOR UPDATE`) in the transaction that makes the change, so two admins demoting each other at the same time cannot leave none. Nobody deactivates themselves (`self`), so an admin cannot lock themselves out by mistake.
- **Deactivate, never delete.** The row stays, so what a user did stays attributable in order and stock histories. Deactivation reuses the existing `enabled` column; no migration.
- **A deactivated user is out at once.** Access tokens are checked against the user on every request (the user provider refuses a disabled user), and their refresh tokens are revoked. Role changes likewise apply from the next request.
- **Everyone changes their own password** with `POST /api/auth/password` (`currentPassword`, `newPassword`). It sits with the other auth endpoints because it answers like a login: every refresh token of the user is revoked, and new tokens for this session are returned, so a session someone else holds does not outlive the change. Wrong guesses of the current password are throttled like logins.
- **Passwords set in the browser need at least 8 characters** (NIST SP 800-63B) and at most 1,024. The console is not held to this, so the development admin can stay `admin`/`admin`.

## Consequences

- An admin who forgets their password needs another admin, or the console. With a single admin that is the console, as before.
- User changes are not in an audit trail yet; CLAUDE.md asks for one on orders and stock only. If merchants ask who changed a role, that is a `user_event` table like the product audit trail (ADR-0013).
- An admin can change their own role while another active admin exists; the Settings page shows the old role until the next page load.
