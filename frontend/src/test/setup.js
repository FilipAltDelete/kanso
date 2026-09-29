import { loadMessages } from '../lib/i18n.jsx';

// Both catalogs up front, so a component under test renders in either
// language on its first render, as it does in the app once the catalog is in.
await Promise.all([loadMessages('en'), loadMessages('sv')]);
