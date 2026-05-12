<?php

namespace Database\Factories;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Completion>
 */
class CompletionFactory extends Factory
{
    protected $model = Completion::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'user_id' => fn (array $attrs) => User::factory()->create(['org_id' => $attrs['org_id']])->id,
            // Nullable — completions stand alone per v14 spec.
            'rqmt_element_id' => null,
            // Default to a Training-typed module record.
            'module_type' => Training::class,
            'module_id' => fn (array $attrs) => Training::factory()->create(['org_id' => $attrs['org_id']])->id,
            'completion_date' => now()->toDateString(),
            'certification_date' => null,
            'expire_date' => null,
            'cert_ident' => null,
            'notes' => null,
        ];
    }
}
