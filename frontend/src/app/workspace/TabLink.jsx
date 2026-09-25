import { Link, useRouter } from '@tanstack/react-router';
import { useOptionalWorkspace } from './WorkspaceProvider.jsx';

/**
 * A link that opens its page in a new tab of the workspace, in front, and
 * leaves the page it is on as it was: for a page that sends you elsewhere,
 * such as the dashboard, which you come back to. Takes what a router `Link`
 * takes. A modified click (ctrl, cmd, shift, middle) is left to the
 * workspace's own handling, and outside a workspace it is a plain link.
 */
export function TabLink({ to, params, search, hash, onClick, ...props }) {
  const router = useRouter();
  const workspace = useOptionalWorkspace();

  return (
    <Link
      to={to}
      params={params}
      search={search}
      hash={hash}
      {...props}
      onClick={(event) => {
        onClick?.(event);
        if (!workspace || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        workspace.open(router.buildLocation({ to, params, search, hash }).href, { newTab: true });
      }}
    />
  );
}
