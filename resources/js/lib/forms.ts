/**
 * Form-input coercion helpers shared across modals.
 */

/**
 * Coerce an optional numeric form field to `number | null`.
 *
 * A `<input type="number">` bound with Vue's `v-model` produces a **number**
 * (Vue coerces number inputs at runtime), while an empty field is `''`. Naively
 * calling `.trim()` on the value throws `TypeError: trim is not a function`
 * once it's a number — so always route number-input values through this.
 */
export function optionalNumber(
    value: string | number | null | undefined,
): number | null {
    if (value === null || value === undefined) {
        return null;
    }

    const trimmed = String(value).trim();

    return trimmed === '' ? null : Number(trimmed);
}
