import { describe, expect, it } from 'vitest';
import {
    blankTrainingForm,
    trainingFormPayload,
    trainingToForm,
} from '@/lib/trainingForm';
import type { TrainingFormSource } from '@/lib/trainingForm';

function source(
    overrides: Partial<TrainingFormSource> = {},
): TrainingFormSource {
    return {
        name: 'First Aid / CPR',
        nickname: null,
        description: null,
        default_hours: null,
        initial_only: false,
        repeating: false,
        std_freq_id: null,
        as_needed: true,
        cert_title: null,
        cert_text: null,
        cert_code: null,
        card_template_id: null,
        card_stock_id: null,
        default_trainer: null,
        default_location: null,
        default_address: null,
        satisfied_by_ids: [],
        ...overrides,
    };
}

describe('trainingForm — custom card template', () => {
    it('starts with no card template', () => {
        // Most trainings print the built-in SMC certificate and no card.
        expect(blankTrainingForm().card_template_id).toBeNull();
    });

    it('round-trips an assigned template through the form', () => {
        const form = trainingToForm(source({ card_template_id: 'tpl-1' }));

        expect(form.card_template_id).toBe('tpl-1');
        expect(trainingFormPayload(form).card_template_id).toBe('tpl-1');
    });

    it('sends null when the card template is cleared', () => {
        // The select's "no card" option must actually detach it, not send
        // an empty string the API would reject.
        const form = trainingToForm(source({ card_template_id: 'tpl-1' }));
        form.card_template_id = null;

        expect(trainingFormPayload(form).card_template_id).toBeNull();
    });
});

describe('trainingForm — default card stock', () => {
    it('starts with no card stock', () => {
        expect(blankTrainingForm().card_stock_id).toBeNull();
    });

    it('round-trips an assigned stock through the form', () => {
        const form = trainingToForm(source({ card_stock_id: 'stk-1' }));

        expect(form.card_stock_id).toBe('stk-1');
        expect(trainingFormPayload(form).card_stock_id).toBe('stk-1');
    });

    it('sends null when the card stock is cleared', () => {
        const form = trainingToForm(source({ card_stock_id: 'stk-1' }));
        form.card_stock_id = null;

        expect(trainingFormPayload(form).card_stock_id).toBeNull();
    });
});

describe('trainingForm — hierarchy satisfiers', () => {
    it('starts with no higher trainings', () => {
        expect(blankTrainingForm().satisfied_by_ids).toEqual([]);
    });

    it('round-trips the satisfier set through the form', () => {
        const form = trainingToForm(
            source({ satisfied_by_ids: ['tr-9', 'tr-10'] }),
        );

        expect(form.satisfied_by_ids).toEqual(['tr-9', 'tr-10']);
        expect(trainingFormPayload(form).satisfied_by_ids).toEqual([
            'tr-9',
            'tr-10',
        ]);
    });

    it('hydrates a COPY — form edits must not mutate the source row', () => {
        const src = source({ satisfied_by_ids: ['tr-9'] });
        const form = trainingToForm(src);
        form.satisfied_by_ids.push('tr-10');

        expect(src.satisfied_by_ids).toEqual(['tr-9']);
    });

    it('sends an empty array when the set is cleared', () => {
        const form = trainingToForm(source({ satisfied_by_ids: ['tr-9'] }));
        form.satisfied_by_ids = [];

        expect(trainingFormPayload(form).satisfied_by_ids).toEqual([]);
    });
});
