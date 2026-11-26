<?php

namespace Tests\Concerns;

use App\Http\Middleware\BlockFrozenMerchantAdminRoutes;

/** Exercise the retained controllers and their real authorization beneath the phase-one route freeze. */
trait ExercisesArchivedMerchantAdmin
{
    protected function setUpExercisesArchivedMerchantAdmin(): void
    {
        $this->withoutMiddleware(BlockFrozenMerchantAdminRoutes::class);
    }
}
