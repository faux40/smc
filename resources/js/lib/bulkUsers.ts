/*
 * Pure helpers for the BULK USER ADD grid — paste parsing + client-side
 * pre-validation (required names, email shape, duplicate detection within
 * the batch and against existing users). The server (POST /users/bulk) is
 * authoritative and best-effort; this just gives live in-grid feedback so
 * dupes are caught before submit.
 */

export interface GridRow {
    f_name: string;
    m_name: string;
    l_name: string;
    email: string;
    role: string;
    employee_number: string;
    job_title: string;
    department: string;
    location: string;
    supervisor_id: string;
    start_date: string;
    end_date: string;
}

/** Column order = paste-fill order (left→right matches a typical export). */
export const GRID_COLUMNS: Array<keyof GridRow> = [
    'f_name',
    'm_name',
    'l_name',
    'email',
    'role',
    'employee_number',
    'job_title',
    'department',
    'location',
    'supervisor_id',
    'start_date',
    'end_date',
];

export function emptyRow(): GridRow {
    return {
        f_name: '',
        m_name: '',
        l_name: '',
        email: '',
        role: 'None',
        employee_number: '',
        job_title: '',
        department: '',
        location: '',
        supervisor_id: '',
        start_date: '',
        end_date: '',
    };
}

/** A row the user hasn't meaningfully touched (role default doesn't count). */
export function isRowEmpty(row: GridRow): boolean {
    return GRID_COLUMNS.filter((c) => c !== 'role').every(
        (c) => row[c].trim() === '',
    );
}

/**
 * Parse clipboard text (Excel / Sheets paste) into a grid of cells.
 * Rows split on newlines, cells on tabs; a trailing newline is ignored.
 */
export function parsePastedGrid(text: string): string[][] {
    const normalized = text.replace(/\r\n?/g, '\n').replace(/\n+$/, '');

    if (normalized === '') {
        return [];
    }

    return normalized.split('\n').map((line) => line.split('\t'));
}

/**
 * Map a parsed paste block onto rows starting at (startRow, startCol),
 * returning a fresh rows array. Grows the row list as needed; cells past
 * the last column are dropped.
 */
export function applyPaste(
    rows: GridRow[],
    grid: string[][],
    startRow: number,
    startCol: number,
): GridRow[] {
    const next = rows.map((r) => ({ ...r }));

    grid.forEach((cells, r) => {
        const target = startRow + r;

        while (next.length <= target) {
            next.push(emptyRow());
        }

        cells.forEach((value, c) => {
            const col = GRID_COLUMNS[startCol + c];

            if (col) {
                next[target][col] = value.trim();
            }
        });
    });

    return next;
}

const EMAIL_RE = /^\S+@\S+\.\S+$/;

/**
 * Per-row client-side errors keyed by row index (blank rows are skipped).
 * `existingEmails` must be lowercased.
 */
export function validateGrid(
    rows: GridRow[],
    existingEmails: Set<string>,
): Record<number, Partial<Record<keyof GridRow, string>>> {
    const batchCounts = new Map<string, number>();

    for (const row of rows) {
        const email = row.email.trim().toLowerCase();

        if (email !== '') {
            batchCounts.set(email, (batchCounts.get(email) ?? 0) + 1);
        }
    }

    const errors: Record<number, Partial<Record<keyof GridRow, string>>> = {};

    rows.forEach((row, i) => {
        if (isRowEmpty(row)) {
            return;
        }

        const rowErrors: Partial<Record<keyof GridRow, string>> = {};

        if (row.f_name.trim() === '') {
            rowErrors.f_name = 'First name required';
        }

        if (row.l_name.trim() === '') {
            rowErrors.l_name = 'Last name required';
        }

        const email = row.email.trim();

        if (email !== '') {
            const key = email.toLowerCase();

            if (!EMAIL_RE.test(email)) {
                rowErrors.email = 'Invalid email';
            } else if (existingEmails.has(key)) {
                rowErrors.email = 'Already in use';
            } else if ((batchCounts.get(key) ?? 0) > 1) {
                rowErrors.email = 'Duplicate in this batch';
            }
        }

        if (Object.keys(rowErrors).length > 0) {
            errors[i] = rowErrors;
        }
    });

    return errors;
}
