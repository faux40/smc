<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 *
 * Caller must set `commentable_type` + `commentable_id` — leaving them
 * unset would produce an orphaned comment, which is never a useful
 * factory output. The smoke-test path is `$target->comments()->create(...)`
 * which is the natural API.
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'author_id' => fn (array $attrs) => User::factory()->create(['org_id' => $attrs['org_id']])->id,
            'body' => fake()->sentence(),
        ];
    }
}
