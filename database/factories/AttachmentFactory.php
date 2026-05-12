<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'uploaded_by_user_id' => fn (array $attrs) => User::factory()->create(['org_id' => $attrs['org_id']])->id,
            'filename' => fake()->word().'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 1024 * 1024),
            'disk' => 'linode',
            'path' => 'attachments/'.fake()->uuid().'.pdf',
        ];
    }
}
