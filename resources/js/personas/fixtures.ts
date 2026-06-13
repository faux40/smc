/*
 * Shared fixtures for the persona specs (Phase P). One coherent little
 * org — the same people and trainings appear across the persona suites
 * so the specs read like scenes from one workplace.
 */

export const summaryPayload = {
    counts: {
        overdue: 2,
        due_soon: 1,
        not_started: 3,
        current: 5,
        as_needed: 1,
    },
    total_assignments: 12,
    total_users: 8,
    users_with_overdue: 2,
};

export const needsActionRows = [
    {
        id: 'ta1',
        user_id: 'u-olive',
        user_name: 'Olive Overdue',
        training_id: 't1',
        training_name: 'Fall Protection',
        status: 'overdue',
        expires_at: '2026-05-01',
        days_until_due: -42,
        sources: [{ type: 'requirement', id: 'r1', name: 'OSHA General' }],
    },
    {
        id: 'ta2',
        user_id: 'u-dana',
        user_name: 'Dana Duesoon',
        training_id: 't2',
        training_name: 'Forklift',
        status: 'due_soon',
        expires_at: '2026-07-02',
        days_until_due: 20,
        sources: [{ type: 'direct', id: null, name: null }],
    },
];

export const usersComplianceRows = [
    {
        user_id: 'u-olive',
        name: 'Olive Overdue',
        email: 'olive@demo.local',
        counts: {
            overdue: 2,
            due_soon: 0,
            current: 1,
            not_started: 0,
            as_needed: 0,
        },
        overall_status: 'overdue',
        tag_ids: [],
    },
    {
        user_id: 'u-carl',
        name: 'Carl Current',
        email: 'carl@demo.local',
        counts: {
            overdue: 0,
            due_soon: 0,
            current: 3,
            not_started: 0,
            as_needed: 0,
        },
        overall_status: 'current',
        tag_ids: [],
    },
];

export const recentCompletionRows = [
    {
        id: 'c1',
        user_id: 'u-carl',
        user_name: 'Carl Current',
        module_label: 'First Aid',
        completion_date: '2026-06-10',
        expire_date: '2027-06-10',
        credits_count: 2,
    },
];

/** Mock every endpoint the four dashboard widgets touch. */
export function dashboardEndpoints(url: string) {
    switch (url) {
        case '/api/dashboard/summary':
            return Promise.resolve({ data: summaryPayload });
        case '/api/dashboard/needs-action':
            return Promise.resolve({ data: needsActionRows });
        case '/api/dashboard/users-compliance':
            return Promise.resolve({ data: usersComplianceRows });
        case '/api/dashboard/recent-completions':
            return Promise.resolve({ data: recentCompletionRows });
        case '/api/tags':
            return Promise.resolve({ data: [] });
        default:
            return Promise.reject(new Error(`unexpected GET ${url}`));
    }
}
