/*
 * Copy text to the clipboard, and say whether it worked.
 *
 * `navigator.clipboard` only exists in a secure context. Dev is served over
 * plain HTTP on a hostname (http://smc.dv), which is not one — so on the
 * machine where this feature gets used most, the modern API simply isn't
 * there. Hence the execCommand fallback, and hence the boolean: a copy button
 * that silently does nothing is worse than one that admits it, because the
 * user stands there clicking it.
 */

/** @returns true when the text is on the clipboard. */
export async function copyText(text: string): Promise<boolean> {
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // Permission denied, or an API that exists but is blocked — fall
            // through rather than give up.
        }
    }

    return legacyCopy(text);
}

/**
 * The pre-Clipboard-API route: put the text in an offscreen textarea, select
 * it, and let the browser copy the selection. Deprecated, still the only thing
 * that works outside a secure context.
 */
function legacyCopy(text: string): boolean {
    const scratch = document.createElement('textarea');

    scratch.value = text;
    // Offscreen rather than hidden: a display:none field cannot be selected.
    scratch.setAttribute('readonly', '');
    scratch.style.position = 'fixed';
    scratch.style.top = '-1000px';
    scratch.style.opacity = '0';

    document.body.appendChild(scratch);

    try {
        scratch.select();
        scratch.setSelectionRange(0, text.length);

        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        scratch.remove();
    }
}
