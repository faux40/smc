import { ref } from 'vue';
import { optionalNumber } from '@/lib/forms';
import type { ClassDetail, ClassFormPayload } from '@/stores/classes';
import { useErrorStore } from '@/stores/errors';

/**
 * Editable shape of a class's core fields. `total_hours` is `string | number`
 * because a `<input type="number">` v-model yields a number once filled but
 * starts as '' — never call `.trim()` on it (see `optionalNumber`).
 */
export interface ClassFormFields {
    name: string;
    scheduled_date: string;
    location: string;
    training_location: string;
    training_address: string;
    instructor: string;
    total_hours: string | number;
    notes: string;
}

function emptyFields(): ClassFormFields {
    return {
        name: '',
        scheduled_date: '',
        location: '',
        training_location: '',
        training_address: '',
        instructor: '',
        total_hours: '',
        notes: '',
    };
}

/**
 * Shared state + validation + payload-building for the class create (modal)
 * and edit (inline on the detail page) forms. Keeping this in one place means
 * the required-field rules and the numeric coercion can't drift between the
 * two entry points.
 */
export function useClassForm(context: string) {
    const errorStore = useErrorStore();
    const form = ref<ClassFormFields>(emptyFields());

    /** Populate from an existing class (edit), or reset to blank (create). */
    function setFrom(target?: ClassDetail | null): void {
        form.value = {
            name: target?.name ?? '',
            scheduled_date: target?.scheduled_date ?? '',
            location: target?.location ?? '',
            training_location: target?.training_location ?? '',
            training_address: target?.training_address ?? '',
            instructor: target?.instructor ?? '',
            total_hours: target?.total_hours ?? '',
            notes: target?.notes ?? '',
        };
    }

    /**
     * Client-side guard for the required fields, reported as visible field
     * errors. We validate in JS (forms are `novalidate`) rather than relying
     * on native `required` popups, which silently block submission with no
     * feedback when a control can't be focused (e.g. Safari's date input).
     */
    function validate(): boolean {
        const fieldErrors: Record<string, string[]> = {};

        if (form.value.name.trim() === '') {
            fieldErrors.name = ['Please enter a class name.'];
        }

        if (String(form.value.scheduled_date).trim() === '') {
            fieldErrors.scheduled_date = ['Please choose a scheduled date.'];
        }

        if (Object.keys(fieldErrors).length > 0) {
            errorStore.report({
                context,
                message: 'Please complete the required fields.',
                fieldErrors,
                surface: 'banner',
            });

            return false;
        }

        return true;
    }

    function payload(): ClassFormPayload {
        const blank = (v: string) => (v.trim() === '' ? null : v);

        return {
            name: form.value.name,
            scheduled_date: form.value.scheduled_date,
            location: blank(form.value.location),
            training_location: blank(form.value.training_location),
            training_address: blank(form.value.training_address),
            instructor: blank(form.value.instructor),
            total_hours: optionalNumber(form.value.total_hours),
            notes: blank(form.value.notes),
        };
    }

    return { form, setFrom, validate, payload };
}
