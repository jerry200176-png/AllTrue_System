<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillingDataQuality;

/** Founder 2026-11-16 (帳務中心重整 G2): the billing data-quality scoreboard, counts per campus. Super admin, read-only. */
class BillingDataQualityController extends Controller
{
    public function index(BillingDataQuality $quality)
    {
        return response()->json(['data' => $quality->run()]);
    }
}
