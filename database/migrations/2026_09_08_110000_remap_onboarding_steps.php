<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(function (array $completed, bool $finished): array {
            if ($finished) {
                return ['basics', 'business', 'services', 'staff', 'link'];
            }

            if (in_array('hours', $completed, true)) {
                $completed[] = 'basics';
            }

            return array_values(array_diff(array_unique($completed), ['hours']));
        });
    }

    public function down(): void
    {
        $this->rewrite(function (array $completed, bool $finished): array {
            if ($finished) {
                return ['business', 'services', 'staff', 'hours'];
            }

            if (in_array('basics', $completed, true)) {
                $completed[] = 'hours';
            }

            return array_values(array_diff(array_unique($completed), ['basics', 'link']));
        });
    }

    /** @param  callable(list<string>, bool): list<string>  $map */
    private function rewrite(callable $map): void
    {
        DB::table('tenants')
            ->select(['id', 'settings', 'onboarding_completed_at'])
            ->orderBy('id')
            ->chunkById(100, function ($tenants) use ($map): void {
                foreach ($tenants as $tenant) {
                    $settings = json_decode((string) $tenant->settings, true) ?: [];
                    $completed = $settings['onboarding']['completed_steps'] ?? null;

                    if (! is_array($completed)) {
                        continue;
                    }

                    $settings['onboarding']['completed_steps'] = $map(
                        array_values(array_filter($completed, 'is_string')),
                        $tenant->onboarding_completed_at !== null,
                    );

                    DB::table('tenants')
                        ->where('id', $tenant->id)
                        ->update(['settings' => json_encode($settings)]);
                }
            });
    }
};
