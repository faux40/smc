import { addDaysToDateOnly } from '@/lib/dateOnly';

/**
 * The client-side mirror of `App\Support\ExpiryCalculator::fromRepeatDays` —
 * completion date + the repeat interval when a training genuinely repeats,
 * else null (it never expires).
 *
 * Exists so a form can show what close-out *would* stamp before it stamps it:
 * the per-topic expiry on the class detail page and the close-out dialog both
 * offer a derived default the manager can accept or overrule. The server
 * remains the authority — this only ever fills in a field the user can see and
 * change, never a value that is written unseen.
 *
 * Returns null rather than throwing on unparseable input: every caller is a
 * computed property feeding a form, and a half-typed date must not take the
 * page down.
 */
export function derivedExpiry(
    completionDate: string,
    repeating: boolean,
    repeatDays: number | null,
): string | null {
    if (!repeating || !repeatDays) {
        return null;
    }

    try {
        return addDaysToDateOnly(completionDate, repeatDays);
    } catch {
        // Not a YYYY-MM-DD date — an empty or partly-typed field.
        return null;
    }
}
