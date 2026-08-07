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
    /**
     * The day the class finishes — known in advance for a multi-day class,
     * and the default close-out offers. Blank until someone says otherwise.
     */
    completion_date: string;
    start_time: string;
    end_time: string;
    location: string;
    address: string;
    instructor: string;
    show_signature: boolean;
    requires_prior_completion: boolean;
    total_hours: string | number;
    min_students: string | number;
    max_students: string | number;
    notes: string;
}

function emptyFields(): ClassFormFields {
    return {
        name: '',
        scheduled_date: '',
        completion_date: '',
        start_time: '',
        end_time: '',
        location: '',
        address: '',
        instructor: '',
        show_signature: false,
        requires_prior_completion: false,
        total_hours: '',
        min_students: '',
        max_students: '',
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
            completion_date: target?.completion_date ?? '',
            start_time: target?.start_time ?? '',
            end_time: target?.end_time ?? '',
            location: target?.location ?? '',
            address: target?.address ?? '',
            instructor: target?.instructor ?? '',
            show_signature: target?.show_signature ?? false,
            requires_prior_completion:
                target?.requires_prior_completion ?? false,
            total_hours: target?.total_hours ?? '',
            min_students: target?.min_students ?? '',
            max_students: target?.max_students ?? '',
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
            completion_date: blank(form.value.completion_date),
            start_time: blank(form.value.start_time),
            end_time: blank(form.value.end_time),
            location: blank(form.value.location),
            address: blank(form.value.address),
            instructor: blank(form.value.instructor),
            show_signature: form.value.show_signature,
            requires_prior_completion: form.value.requires_prior_completion,
            total_hours: optionalNumber(String(form.value.total_hours)),
            min_students: optionalNumber(String(form.value.min_students)),
            max_students: optionalNumber(String(form.value.max_students)),
            notes: blank(form.value.notes),
        };
    }

    return { form, setFrom, validate, payload };
}
