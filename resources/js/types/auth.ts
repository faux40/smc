export type User = {
    // UUID primary key (HasUuids), not an auto-increment integer.
    id: string;
    // Tenancy owner — present on every shared user via $user->toArray().
    org_id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    // Role flags injected by HandleInertiaRequests::share (optional because
    // they're absent on raw user payloads that don't go through that path).
    isOwner?: boolean;
    isSuperAdmin?: boolean;
    isAdmin?: boolean;
    isManager?: boolean;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
