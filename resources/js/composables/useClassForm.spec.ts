import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import { useClassForm } from '@/composables/useClassForm';
import type { ClassDetail } from '@/stores/classes';
import { useErrorStore } from '@/stores/errors';

const CTX = 'form:class';

const detail: ClassDetail = {
    id: 'c1',
    name: 'Fall Protection',
    scheduled_date: '2026-06-01',
    location: 'Yard 3',
    training_location: null,
    training_address: null,
    instructor: 'J. Cole',
    total_hours: '4.00',
    notes: 'bring harnesses',
    status: 'scheduled',
    completion_date: null,
    can_edit: true,
    trainings: [],
    enrollments: [],
};

describe('useClassForm', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('setFrom hydrates the editable fields from a class', () => {
        const { form, setFrom } = useClassForm(CTX);
        setFrom(detail);
        expect(form.value.name).toBe('Fall Protection');
        expect(form.value.scheduled_date).toBe('2026-06-01');
        expect(form.value.total_hours).toBe('4.00');
    });

    it('validate fails + reports field errors when name/date blank', () => {
        const { validate } = useClassForm(CTX);
        const errors = useErrorStore();

        expect(validate()).toBe(false);
        expect(errors.getFieldError(CTX, 'name')).toBeTruthy();
        expect(errors.getFieldError(CTX, 'scheduled_date')).toBeTruthy();
    });

    it('validate passes once required fields are present', () => {
        const { form, validate } = useClassForm(CTX);
        form.value.name = 'X';
        form.value.scheduled_date = '2026-06-01';
        expect(validate()).toBe(true);
    });

    it('payload coerces a numeric total_hours and blanks empties', () => {
        const { form, payload } = useClassForm(CTX);
        form.value.name = 'X';
        form.value.scheduled_date = '2026-06-01';
        form.value.total_hours = 4; // number, as a type=number v-model yields
        const p = payload();
        expect(p.total_hours).toBe(4);
        expect(p.location).toBeNull();
        expect(p.notes).toBeNull();
    });
});
