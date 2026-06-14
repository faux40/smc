<?php

namespace App\Support;

use App\Actions\RecalculateTrainingStatus;
use App\Models\Attachment;
use App\Models\ClassEnrollment;
use App\Models\Comment;
use App\Models\Completion;
use App\Models\NotificationPreference;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Combine-users (de-duplication) tool.
 *
 * Folds a `duplicate` user into a `survivor`: every record the duplicate
 * owns or authored is reassigned to the survivor, conflicting profile fields
 * are resolved per the caller's per-field choices (the discarded side is
 * appended to the survivor's notes as an audit block rather than lost), and
 * the duplicate is finally soft-deleted with its email cleared so it stops
 * occupying the unique index.
 *
 * The whole thing runs in one transaction — a partial merge would leave
 * orphaned compliance data, so it's all-or-nothing.
 */
class UserMerge
{
    public function __construct(private RecalculateTrainingStatus $recalc) {}

    /**
     * Profile fields offered for conflict resolution, in display order.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'f_name' => 'First name',
        'm_name' => 'Middle name',
        'l_name' => 'Last name',
        'prefix_name' => 'Prefix',
        'suffix_name' => 'Suffix',
        'email' => 'Email',
        'department' => 'Department',
        'location' => 'Location',
        'job_title' => 'Job title',
        'employee_number' => 'Employee #',
        'supervisor_id' => 'Supervisor',
        'start_date' => 'Start date',
        'end_date' => 'End date',
        'status' => 'Status',
    ];

    /**
     * Build the side-by-side preview the modal renders: each profile field
     * with both values and whether they differ, the (read-only) role on each
     * side, and counts of the records that would move.
     *
     * @return array<string, mixed>
     */
    public function preview(User $survivor, User $duplicate): array
    {
        $fields = [];

        foreach (self::FIELDS as $key => $label) {
            $s = $this->displayValue($survivor, $key);
            $d = $this->displayValue($duplicate, $key);

            $fields[] = [
                'key' => $key,
                'label' => $label,
                'survivor' => $s,
                'duplicate' => $d,
                'differs' => $s !== $d,
                // Default the choice to whichever side has a value (survivor
                // wins when both do) so a blank field doesn't overwrite data.
                'default' => ($s === null && $d !== null) ? 'duplicate' : 'survivor',
            ];
        }

        return [
            'survivor' => $this->identity($survivor),
            'duplicate' => $this->identity($duplicate),
            'fields' => $fields,
            'role' => [
                'survivor' => $survivor->roles->first()?->name,
                'duplicate' => $duplicate->roles->first()?->name,
            ],
            'counts' => $this->counts($duplicate),
        ];
    }

    /**
     * Execute the merge. `$choices` maps a field key to 'survivor' or
     * 'duplicate'; absent keys keep the survivor's value. Returns the fresh
     * survivor.
     *
     * @param  array<string, string>  $choices
     */
    public function merge(User $survivor, User $duplicate, array $choices): User
    {
        return DB::transaction(function () use ($survivor, $duplicate, $choices) {
            // Snapshot identity before we clear the duplicate's email below.
            $dupEmail = $duplicate->email;
            $discarded = $this->applyChoices($survivor, $duplicate, $choices);

            // Free the unique email index before anything else touches the
            // survivor's email (it may be adopting the duplicate's address).
            $duplicate->forceFill(['email' => null])->save();

            $affectedTrainingIds = $this->reassignRecords($survivor, $duplicate);

            $survivor->notes = $this->appendAuditBlock(
                $survivor->notes,
                $duplicate,
                $dupEmail,
                $discarded,
            );
            $survivor->save();

            // Completions + assignments moved; recompute the survivor's
            // denormalized expiry/last-completed for every touched training.
            foreach ($affectedTrainingIds as $trainingId) {
                $this->recalc->handle($survivor->id, $trainingId);
            }

            $duplicate->delete();

            return $survivor->fresh()->load('roles:id,name');
        });
    }

    /**
     * Apply the per-field choices to the survivor and return the values that
     * were thrown away (non-empty discarded sides only), keyed by label.
     *
     * @param  array<string, string>  $choices
     * @return array<string, string>
     */
    private function applyChoices(User $survivor, User $duplicate, array $choices): array
    {
        $discarded = [];

        foreach (self::FIELDS as $key => $label) {
            $s = $this->displayValue($survivor, $key);
            $d = $this->displayValue($duplicate, $key);

            if ($s === $d) {
                continue;
            }

            $takeDuplicate = ($choices[$key] ?? 'survivor') === 'duplicate';

            if ($takeDuplicate) {
                $survivor->{$key} = $duplicate->{$key};
            }

            // Record whichever non-empty value lost.
            $lost = $takeDuplicate ? $s : $d;
            if ($lost !== null) {
                $discarded[$label] = $lost;
            }
        }

        // A user can't supervise themselves; if the chosen supervisor is the
        // survivor (or the now-removed duplicate), drop it.
        if (in_array($survivor->supervisor_id, [$survivor->id, $duplicate->id], true)) {
            $survivor->supervisor_id = null;
        }

        return $discarded;
    }

    /**
     * Move every record owned/authored by the duplicate onto the survivor.
     * Returns the distinct training ids whose status needs recomputing.
     *
     * @return array<int, string>
     */
    private function reassignRecords(User $survivor, User $duplicate): array
    {
        $trainingIds = [];

        // Completions — no unique constraint, the full history just moves.
        Completion::where('user_id', $duplicate->id)
            ->where('module_type', Training::class)
            ->pluck('module_id')
            ->each(function (string $id) use (&$trainingIds) {
                $trainingIds[$id] = true;
            });
        Completion::where('user_id', $duplicate->id)
            ->update(['user_id' => $survivor->id]);

        // Training assignments — unique on (user_id, training_id). Where the
        // survivor already has one for the same training, fold the duplicate's
        // sources in and drop its row; otherwise reassign in place.
        foreach (TrainingAssignment::where('user_id', $duplicate->id)->get() as $dupTa) {
            $trainingIds[$dupTa->training_id] = true;

            $survTa = TrainingAssignment::where('user_id', $survivor->id)
                ->where('training_id', $dupTa->training_id)
                ->first();

            if ($survTa === null) {
                $dupTa->update(['user_id' => $survivor->id]);

                continue;
            }

            $existing = $survTa->sources()
                ->get(['sourceable_type', 'sourceable_id'])
                ->map(fn ($s) => $s->sourceable_type.'|'.$s->sourceable_id)
                ->all();

            foreach ($dupTa->sources as $source) {
                $sig = $source->sourceable_type.'|'.$source->sourceable_id;
                if (in_array($sig, $existing, true)) {
                    continue;
                }
                $source->update(['training_assignment_id' => $survTa->id]);
                $existing[] = $sig;
            }

            $dupTa->delete();
        }

        // Class enrollments — unique on (class_id, user_id); skip dup rows the
        // survivor already has, reassign the rest.
        $survClassIds = ClassEnrollment::where('user_id', $survivor->id)
            ->pluck('class_id')
            ->all();
        foreach (ClassEnrollment::where('user_id', $duplicate->id)->get() as $enrollment) {
            if (in_array($enrollment->class_id, $survClassIds, true)) {
                $enrollment->delete();

                continue;
            }
            $enrollment->update(['user_id' => $survivor->id]);
            $survClassIds[] = $enrollment->class_id;
        }

        // Notification preferences are per-user settings, not data — the
        // survivor keeps theirs; drop the duplicate's.
        NotificationPreference::where('user_id', $duplicate->id)->delete();

        // Authored/uploaded records (restrictOnDelete would otherwise block
        // the soft-delete) — hand authorship to the survivor.
        Comment::where('author_id', $duplicate->id)
            ->update(['author_id' => $survivor->id]);
        Attachment::where('uploaded_by_user_id', $duplicate->id)
            ->update(['uploaded_by_user_id' => $survivor->id]);

        // Polymorphic rows ABOUT the duplicate (tags / comments / attachments
        // on its own profile) — repoint to the survivor.
        $this->reassignTags($survivor, $duplicate);
        $this->repointMorph('comments', 'commentable', $survivor, $duplicate);
        $this->repointMorph('attachments', 'attachable', $survivor, $duplicate);

        // People who reported to the duplicate now report to the survivor.
        User::where('supervisor_id', $duplicate->id)
            ->update(['supervisor_id' => $survivor->id]);

        return array_keys($trainingIds);
    }

    /**
     * Move the duplicate's tag attachments to the survivor, skipping any tag
     * the survivor already carries (the taggables pivot is keyed by the morph
     * + tag, so a blind reassign would collide).
     */
    private function reassignTags(User $survivor, User $duplicate): void
    {
        $survivorTagIds = DB::table('taggables')
            ->where('taggable_type', User::class)
            ->where('taggable_id', $survivor->id)
            ->pluck('tag_id')
            ->all();

        DB::table('taggables')
            ->where('taggable_type', User::class)
            ->where('taggable_id', $duplicate->id)
            ->whereIn('tag_id', $survivorTagIds)
            ->delete();

        DB::table('taggables')
            ->where('taggable_type', User::class)
            ->where('taggable_id', $duplicate->id)
            ->update(['taggable_id' => $survivor->id]);
    }

    /**
     * Repoint a morphMany subject column (commentable_id / attachable_id)
     * from the duplicate to the survivor.
     */
    private function repointMorph(string $table, string $morph, User $survivor, User $duplicate): void
    {
        DB::table($table)
            ->where($morph.'_type', User::class)
            ->where($morph.'_id', $duplicate->id)
            ->update([$morph.'_id' => $survivor->id]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(User $duplicate): array
    {
        return [
            'completions' => Completion::where('user_id', $duplicate->id)->count(),
            'training_assignments' => TrainingAssignment::where('user_id', $duplicate->id)->count(),
            'class_enrollments' => ClassEnrollment::where('user_id', $duplicate->id)->count(),
            'comments_authored' => Comment::where('author_id', $duplicate->id)->count(),
            'attachments_uploaded' => Attachment::where('uploaded_by_user_id', $duplicate->id)->count(),
            'reports' => User::where('supervisor_id', $duplicate->id)->count(),
        ];
    }

    /**
     * @return array<string, ?string>
     */
    private function identity(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email];
    }

    /**
     * Render a field as the comparable/display string used in the preview and
     * the audit block. Dates collapse to Y-m-d; blanks to null.
     */
    private function displayValue(User $u, string $key): ?string
    {
        $value = $u->{$key};

        if ($value === null || $value === '') {
            return null;
        }

        if ($key === 'supervisor_id') {
            return $u->supervisor?->name ?? null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return (string) $value;
    }

    /**
     * Append a dated audit block recording the merge and any discarded values
     * (including the duplicate's role, which never transfers — the survivor
     * keeps their own per the one-role invariant).
     *
     * @param  array<string, string>  $discarded
     */
    private function appendAuditBlock(?string $existing, User $duplicate, ?string $email, array $discarded): string
    {
        $date = Carbon::now()->toDateString();

        $lines = [];
        $lines[] = "[Merged {$date}] Combined duplicate record \"{$duplicate->name}\""
            .($email ? " ({$email})" : '').' into this user.';

        $role = $duplicate->roles->first()?->name;
        if ($role !== null) {
            $discarded['Role'] = $role;
        }

        if ($discarded !== []) {
            $lines[] = 'Discarded values:';
            foreach ($discarded as $label => $value) {
                $lines[] = "- {$label}: \"{$value}\"";
            }
        }

        $block = implode("\n", $lines);

        return $existing ? trim($existing)."\n\n".$block : $block;
    }
}
