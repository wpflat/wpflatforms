import { store } from './core';

/** Who may read the answers: the team members given Forms access, and administrators. */
export const canForm = () => !! ( store.caps.inbox || store.caps.admin || store.canManage );
