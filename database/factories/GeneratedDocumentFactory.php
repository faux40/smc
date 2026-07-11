<?php

namespace Database\Factories;

use App\Models\GeneratedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    protected $model = GeneratedDocument::class;

    public function definition(): array
    {
        return [
            'location' => '',
            'department' => '',
            'status' => 'queued',
            'filename' => 'Org_Template_20260711',
        ];
    }
}
