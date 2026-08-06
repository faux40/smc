/*
 * Shared training-form shape + (de)serialization, used by both the create
 * modal (TrainingFormModal) and the detail-page editor (trainings/Show) via
 * the TrainingFields component — so the form lives in exactly one place.
 */

import { optionalNumber } from '@/lib/forms';
import type { TrainingFormPayload } from '@/stores/trainings';

/**
 * The training fields the form reads from / writes back. A superset-free view
 * that both the list row (TrainingRow) and the detail-page Inertia prop
 * satisfy structurally — so neither has to carry list-only flags like
 * can_edit / can_delete to be usable here.
 */
export interface TrainingFormSource {
    name: string;
    nickname: string | null;
    description: string | null;
    default_hours: string | null;
    initial_only: boolean;
    repeating: boolean;
    std_freq_id: string | null;
    as_needed: boolean;
    cert_title: string | null;
    cert_text: string | null;
    cert_code: string | null;
    /** The custom card design printed for this training; null = none. */
    card_template_id: string | null;
    /** The sheet those cards print onto by default; overridable per run. */
    card_stock_id: string | null;
    default_trainer: string | null;
    default_location: string | null;
    default_address: string | null;
    /** The higher training whose credential satisfies this one; null = none. */
    superseded_by_id: string | null;
}

export interface TrainingFormState {
    name: string;
    nickname: string;
    description: string;
    default_hours: string | number;
    initial_only: boolean;
    repeating: boolean;
    std_freq_id: string | null;
    as_needed: boolean;
    cert_title: string;
    cert_text: string;
    cert_code: string;
    // Not blanked to '' like the text fields: the API wants a uuid or null.
    card_template_id: string | null;
    /** The sheet those cards print onto by default; overridable per run. */
    card_stock_id: string | null;
    default_trainer: string;
    default_location: string;
    default_address: string;
    /** The higher training whose credential satisfies this one; null = none. */
    superseded_by_id: string | null;
}

export function blankTrainingForm(): TrainingFormState {
    return {
        name: '',
        nickname: '',
        description: '',
        default_hours: '',
        initial_only: false,
        repeating: false,
        std_freq_id: null,
        as_needed: false,
        cert_title: '',
        cert_text: '',
        cert_code: '',
        card_template_id: null,
        card_stock_id: null,
        default_trainer: '',
        default_location: '',
        default_address: '',
        superseded_by_id: null,
    };
}

/** Hydrate the form from a training (nulls → empty strings for inputs). */
export function trainingToForm(t: TrainingFormSource): TrainingFormState {
    return {
        name: t.name,
        nickname: t.nickname ?? '',
        description: t.description ?? '',
        default_hours: t.default_hours ?? '',
        initial_only: t.initial_only,
        repeating: t.repeating,
        std_freq_id: t.std_freq_id,
        as_needed: t.as_needed,
        cert_title: t.cert_title ?? '',
        cert_text: t.cert_text ?? '',
        cert_code: t.cert_code ?? '',
        card_template_id: t.card_template_id,
        card_stock_id: t.card_stock_id,
        default_trainer: t.default_trainer ?? '',
        default_location: t.default_location ?? '',
        default_address: t.default_address ?? '',
        superseded_by_id: t.superseded_by_id,
    };
}

/** Build the API payload (empty strings → null; repeating drives std_freq_id). */
export function trainingFormPayload(
    form: TrainingFormState,
): TrainingFormPayload {
    const blank = (v: string) => (v.trim() === '' ? null : v);

    return {
        name: form.name,
        nickname: blank(form.nickname),
        description: blank(form.description),
        default_hours: optionalNumber(form.default_hours),
        initial_only: form.initial_only,
        repeating: form.repeating,
        std_freq_id: form.repeating ? form.std_freq_id : null,
        as_needed: form.as_needed,
        cert_title: blank(form.cert_title),
        cert_text: blank(form.cert_text),
        cert_code: blank(form.cert_code),
        card_template_id: form.card_template_id,
        card_stock_id: form.card_stock_id,
        default_trainer: blank(form.default_trainer),
        default_location: blank(form.default_location),
        default_address: blank(form.default_address),
        superseded_by_id: form.superseded_by_id,
    };
}
