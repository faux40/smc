/*
 * F10 — shared "Remind" action wiring. Wraps the trainingAssignments store's
 * remind / remindBulk calls with the toast copy every surface (compliance
 * detail, dashboard widget) shows, so the messaging — including the
 * supervisor-CC note — lives in one place.
 */
import { toast } from 'vue-sonner';
import {
    useTrainingAssignmentsStore,
    type RemindBulkResult,
} from '@/stores/trainingAssignments';

function plural(n: number, one: string, many: string): string {
    return n === 1 ? one : many;
}

export function useRemind() {
    const store = useTrainingAssignmentsStore();

    /** Remind about a single assignment, toasting the outcome. */
    async function remindOne(taId: string): Promise<void> {
        try {
            const res = await store.remind(taId);
            toast.success(
                res.supervisor_notified
                    ? 'Reminder sent (supervisor CC’d).'
                    : 'Reminder sent.',
            );
        } catch (e) {
            // 422 = the assignment is current / as-needed — nothing to remind.
            const status = (e as { response?: { status?: number } }).response
                ?.status;
            if (status === 422) {
                toast.info('Nothing to remind — this assignment is up to date.');
            } else {
                toast.error('Could not send the reminder.');
            }
        }
    }

    /**
     * Remind about a selection. Toasts the reminded count, the supervisor-CC
     * count, and any skips. Returns true when the call succeeded so callers can
     * clear their selection.
     */
    async function remindMany(taIds: string[]): Promise<boolean> {
        if (taIds.length === 0) {
            return false;
        }

        try {
            const res: RemindBulkResult = await store.remindBulk(taIds);
            const people = `${res.reminded_count} ${plural(res.reminded_count, 'person', 'people')}`;
            const sup =
                res.supervisors_notified_count > 0
                    ? ` (${res.supervisors_notified_count} ${plural(res.supervisors_notified_count, 'supervisor', 'supervisors')} CC’d)`
                    : '';
            const skipped =
                res.skipped_count > 0 ? ` · ${res.skipped_count} skipped` : '';
            toast.success(`Reminder sent to ${people}${sup}${skipped}.`);

            return true;
        } catch {
            toast.error('Could not send reminders.');

            return false;
        }
    }

    return { remindOne, remindMany };
}
