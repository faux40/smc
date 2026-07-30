<?php

namespace App\Actions;

use App\Models\CardField;
use App\Models\Training;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Apply a whole set of custom card-field definitions to a training: the
 * payload IS the definition, so rows absent from it are deleted (taking their
 * class answers with them) and `seq` follows the payload's order.
 *
 * Stated as one operation rather than add/remove/reorder endpoints because
 * ordering and membership are properties of the set, not of a row.
 */
class SyncTrainingCardFields
{
    /**
     * @param  list<array<string, mixed>>  $fields  validated rows, in display order
     * @return Collection<int, CardField>
     */
    public function handle(Training $training, array $fields): Collection
    {
        DB::transaction(function () use ($training, $fields): void {
            $keepIds = array_values(array_filter(array_column($fields, 'id')));

            // Deletes first: a key freed by a removed row must be available to
            // a row that's taking it over in the same request.
            $training->cardFields()->whereNotIn('id', $keepIds ?: ['-'])->delete();

            $this->parkChangedKeys($training, $fields);

            foreach ($fields as $seq => $row) {
                $attributes = [
                    'key' => $row['key'],
                    'label' => $row['label'],
                    'type' => $row['type'],
                    'default_value' => $row['default_value'] ?? null,
                    'seq' => $seq,
                ];

                if (($row['id'] ?? null) !== null) {
                    // Update in place: the answers classes already recorded
                    // hang off this id, so a rename must not become a
                    // delete-and-recreate.
                    $training->cardFields()->whereKey($row['id'])->update($attributes);

                    continue;
                }

                $training->cardFields()->create([
                    'org_id' => $training->org_id,
                    ...$attributes,
                ]);
            }
        });

        // withCount so the editor can say how many answers a field would
        // discard if it were removed.
        return $training->cardFields()->withCount('values')->get();
    }

    /**
     * Move every changed key out of the way before writing the final ones.
     *
     * Two fields swapping keys is a legitimate edit, but the unique
     * (training, key) index sees the intermediate state where both hold the
     * same value. Parking the changed rows on throwaway keys first makes any
     * permutation safe without a deferrable constraint. The park value starts
     * with `_`, which the key grammar forbids, so it can never collide with a
     * real key.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private function parkChangedKeys(Training $training, array $fields): void
    {
        $existing = $training->cardFields()->get()->keyBy('id');

        foreach ($fields as $row) {
            $id = $row['id'] ?? null;

            if ($id === null || ! $existing->has($id)) {
                continue;
            }

            if ($existing[$id]->key === $row['key']) {
                continue;
            }

            $training->cardFields()->whereKey($id)->update([
                'key' => '_'.Str::uuid()->toString(),
            ]);
        }
    }
}
