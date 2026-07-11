<?php

namespace Database\Factories;

use App\Models\DocTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocTemplate>
 */
class DocTemplateFactory extends Factory
{
    protected $model = DocTemplate::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'description' => null,
            'original_filename' => str_replace(' ', '_', $name).'.docx',
            'extension' => 'docx',
            'path' => 'doc-templates/'.fake()->uuid().'.docx',
            'size' => fake()->numberBetween(10_000, 500_000),
            'placeholders' => ['agency', 'doc_date'],
            'version' => 1,
        ];
    }

    /** System scope: the universal template library. */
    public function system(): static
    {
        return $this->state(fn () => ['org_id' => null]);
    }
}
