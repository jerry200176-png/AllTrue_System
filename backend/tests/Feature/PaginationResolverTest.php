<?php

namespace Tests\Feature;

use Illuminate\Pagination\Paginator;
use Tests\TestCase;

/**
 * Regression for the 2026-06-28 production incident: every paginated endpoint
 * silently ignored `?page=` and always returned page 1 (課程管理 showed only the
 * first 50 of 144 courses; "下一頁沒反應"; students/bugs identical).
 *
 * Root cause: `Illuminate\Pagination\PaginationServiceProvider` was missing from
 * `config/app.php` providers. Without it the paginator's current-page and
 * current-path resolvers are never registered, so `resolveCurrentPage()` falls
 * back to the hardcoded default of 1 and `resolveCurrentPath()` to '/'.
 */
class PaginationResolverTest extends TestCase
{
    public function test_paginator_resolves_page_from_request(): void
    {
        request()->merge(['page' => 3]);

        $this->assertSame(
            3,
            Paginator::resolveCurrentPage(),
            'Paginator must resolve ?page from the request — register PaginationServiceProvider in config/app.php.'
        );
    }

    public function test_paginator_resolves_current_path_not_bare_root(): void
    {
        // With the resolver registered, a real request resolves to its own path,
        // so generated next/prev URLs point at the actual endpoint (not "/").
        $this->get('/api/v1/health');

        $this->assertNotSame(
            '/',
            Paginator::resolveCurrentPath(),
            'Paginator current-path resolver must be registered (PaginationServiceProvider).'
        );
    }

    public function test_paginate_honors_page_across_pages(): void
    {
        // Build a paginator from an in-memory collection through the same resolver
        // the API uses; page 2 with perPage 2 must surface the 3rd/4th items.
        request()->merge(['page' => 2]);

        $items = collect(range(1, 6));
        $page = $items->forPage(Paginator::resolveCurrentPage(), 2)->values();

        $this->assertSame([3, 4], $page->all());
    }
}
