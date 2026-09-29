# ADR-0020: Frontend errors, session expiry, code splitting and live order pages

- Status: accepted
- Date: 2026-09-29

## Context

M1 lists five frontend gaps (ROADMAP.md, M1 Frontend). A page that threw while rendering fell through to TanStack Router's built-in boundary, which shows an unstyled English "Something went wrong!" with no way back; anything that threw outside a route blanked the whole app. When the refresh token was refused, `api/client.js` cleared the access token but nothing told the UI, which stayed signed in and showed 401s. The whole app and both languages shipped as one 833 kB bundle. Two English strings sat in UI primitives and one each in the order form and the API client. Only the dashboard refreshed on its own, so an operator's order list and order page went stale while the warehouse worked.

## Decision

**Errors stop at the smallest thing that failed.**
- A page: the root route's `errorComponent` (`RouteError`) shows a translated panel in place of the page, with "Reload the page", which renders it again in the same router.
- A workspace tab: an error boundary around each tab's router catches what gets past the router, including a router that cannot be created. Its reload creates the tab's router again at the tab's current address, as a browser reloads a page. The router's own global catch boundary is turned off (`disableGlobalCatchBoundary`), so nothing stops at its English fallback.
- The app: `AppErrorBoundary`, outside every provider. It needs no catalog and offers a browser reload; the open tabs survive that because the workspace is saved in the browser.
- The panels fold the error's own message under "Details" for whoever the problem is reported to.

**A refused refresh token ends the session, and only that ends it.**
- `api/client.js` calls `onSessionEnded` listeners once when a request's refresh is answered with anything but 408, 429 or 5xx. A refresh that gets no answer (`NetworkError`), or a busy or failing server, keeps the session: the refresh token may be good, and the next request tries again.
- `AuthProvider` goes to `anonymous`, remembers the email, and clears the query cache once the pages have unmounted. It also clears the cache on sign-out, so nothing one person loaded is shown to the next.
- Signing out goes by the refresh cookie alone, without the access token. The API refuses an expired Bearer token even on its public endpoints, so signing out after a long pause used to fail and leave the UI signed in.
- The login page says the session expired and fills in the email. The workspace is saved per user (app/workspace), and the address bar still shows the tab in front, so the same person signing in again gets every tab back at its address, with the one they were on in front. Unsaved input in an open dialog, and each tab's in-memory back history, are lost.
- Refreshes are single-flight within a window. A request whose token another request has already renewed retries with the new token instead of refreshing again. Across windows of the app, refreshes take turns under a Web Locks API lock (`kanso.auth.refresh`). The refresh token rotates on every use, and reuse detection revokes the whole family when a spent token is presented again (ADR-0019); two windows refreshing with the same cookie at once would otherwise sign each other out, as ADR-0019's consequences note.

**The main bundle holds the shell; pages and languages are downloaded when needed.**
- Every route's component is a `lazyRouteComponent` chunk, preloaded on link hover (`defaultPreload: 'intent'`); a spinner shows if one takes over a second. A chunk missing after a deploy makes the router reload the page once; after that, `RouteError` shows.
- Each language catalog is a module of its own (`lib/messages/sv.js`, `en.js`), fetched for the language in use and for the other only when someone switches. The switch completes when the catalog has arrived, and a failed download leaves the language as it was and says so. The few strings needed before or without a catalog (loading, the last-resort panel) are `essentials` in `lib/i18n.jsx`, in both languages; a test keeps each key in exactly one place and the key sets of both languages equal.
- `I18nProvider` renders nothing until its first catalog arrives, and throws to `AppErrorBoundary` if it cannot. `AuthProvider` sits outside it, so the session is restored while the catalog downloads.
- The Vitest setup loads both catalogs first, so components render synchronously in tests as before.

**The order list and order page keep themselves up to date.**
- `useLiveQuery` (lib/liveQuery.js) refetches every 30 seconds (the dashboard's interval), and on window focus and reconnect. It does this only while the page's tab is shown in its pane; a tab behind another stays still and catches up when it comes back into view with data older than one interval.
- A form that saves with a record's version must not have the record refreshed under it: the save would carry the new version and silently overwrite a change made elsewhere instead of being refused with 409. So an open `Dialog` holds every live query, since the modal makes everything behind it inert. Query data is shared between tabs, so a hold scoped to one tab would not be enough. Inline forms that send a version (correcting or voiding a shipment, a payment-status choice not yet saved) hold only their own order.
- Holds stop the interval, focus and reconnect refetches, not invalidation. A 409 still refetches the order, and the dialog shows what changed, as before.

**Error text goes through `t()`.** `lib/errorMessage.js` shows the API's problem detail or title when it sent one, and otherwise a translated message: "the request failed (status)", "could not reach the server", or a generic one. `ApiError.message` is for developers only.

## Consequences

- Main bundle: 833.07 kB (239.89 kB gzip) → 485.37 kB (150.05 kB gzip). A page not yet visited needs the network the first time it opens.
- Server-side problem details are still English; translating them needs error codes from the API, as violations already have.
- Each shown order list or order page adds one request per 30 seconds per operator. A dialog left open pauses those until it closes.
- A browser without the Web Locks API refreshes without the cross-window lock. Every browser Kanso supports has it.
- The frontend image's Nginx compresses the chunks (ADR-0018).
