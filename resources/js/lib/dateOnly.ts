/**
 * Date-only arithmetic for `YYYY-MM-DD` strings (completion / expire dates
 * are calendar dates, never a moment in time). Routing a date-only string
 * through `new Date(iso)` parses it as UTC midnight, but naive local-time
 * math on the result (e.g. `.setDate(d.getDate() + n)`) drifts a day in
 * negative-UTC-offset zones once daylight saving shifts the local clock
 * across the addition. Every step here stays in UTC-normalized integer math
 * so there's no timezone to drift.
 */

interface DateOnlyParts {
    y: number;
    m: number;
    d: number;
}

/** Parse a `YYYY-MM-DD` string into its numeric parts. Throws on anything else. */
function parseDateOnly(date: string): DateOnlyParts {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);

    if (!match) {
        throw new Error(`Not a YYYY-MM-DD date: ${date}`);
    }

    return { y: Number(match[1]), m: Number(match[2]), d: Number(match[3]) };
}

function formatDateOnly(y: number, m: number, d: number): string {
    const mm = String(m).padStart(2, '0');
    const dd = String(d).padStart(2, '0');

    return `${y}-${mm}-${dd}`;
}

/**
 * `date + days` as a `YYYY-MM-DD` string, handling month/year/leap-year
 * boundaries correctly (Date.UTC normalizes overflow days/months itself).
 */
export function addDaysToDateOnly(date: string, days: number): string {
    const { y, m, d } = parseDateOnly(date);
    const utcMs = Date.UTC(y, m - 1, d) + days * 86_400_000;
    const shifted = new Date(utcMs);

    return formatDateOnly(
        shifted.getUTCFullYear(),
        shifted.getUTCMonth() + 1,
        shifted.getUTCDate(),
    );
}
