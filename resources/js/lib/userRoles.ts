// Roles assignable to newly added users. Owner is intentionally excluded — it
// is set only through the (planned) ownership-transfer flow. `None` is first so
// it reads as the default in the bulk-add grid's role dropdown.
export const ASSIGNABLE_ROLES = [
    'None',
    'SelfView',
    'SelfEdit',
    'Manager',
    'Admin',
    'SuperAdmin',
] as const;
