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
    start_time: '08:00',
    end_time: '12:00',
    location: 'Yard 3',
    address: null,
    instructor: 'J. Cole',
    show_signature: true,
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
        expect(form.value.start_time).toBe('08:00');
        expect(form.value.end_time).toBe('12:00');
        expect(form.value.instructor).toBe('J. Cole');
        expect(form.value.show_signature).toBe(true);
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

    it('payload blanks empty optional fields', () => {
        const { form, payload } = useClassForm(CTX);
        form.value.name = 'X';
        form.value.scheduled_date = '2026-06-01';
        const p = payload();
        expect(p.name).toBe('X');
        expect(p.location).toBeNull();
        expect(p.address).toBeNull();
        expect(p.start_time).toBeNull();
        expect(p.end_time).toBeNull();
        expect(p.notes).toBeNull();
        // Boolean passes through as-is (never blanked).
        expect(p.show_signature).toBe(false);
    });

    it('payload carries times, address, and the signature flag', () => {
        const { form, payload } = useClassForm(CTX);
        form.value.name = 'X';
        form.value.scheduled_date = '2026-06-01';
        form.value.start_time = '08:00';
        form.value.end_time = '12:30';
        form.value.address = '450 Ryder St';
        form.value.show_signature = true;
        const p = payload();
        expect(p.start_time).toBe('08:00');
        expect(p.end_time).toBe('12:30');
        expect(p.address).toBe('450 Ryder St');
        expect(p.show_signature).toBe(true);
    });
});
