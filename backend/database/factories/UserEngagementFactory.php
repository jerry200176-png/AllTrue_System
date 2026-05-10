<?php

namespace Database\Factories;

use App\Models\UserEngagement;
use App\Support\UserEngagementPresenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserEngagement>
 */
class UserEngagementFactory extends Factory
{
    protected $model = UserEngagement::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'role_track' => 'teacher',
            'rank_key' => UserEngagementPresenter::DEFAULT_RANK_KEY,
            'xp_total' => 0,
            'rank_display_opt_out' => false,
        ];
    }
}
