<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Guard: lazy loading must throw outside production so N+1 regressions fail CI. */
class LazyLoadingGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_lazy_loading_is_prevented_outside_production(): void
    {
        $this->assertTrue(\Illuminate\Database\Eloquent\Model::preventsLazyLoading());
    }
}
