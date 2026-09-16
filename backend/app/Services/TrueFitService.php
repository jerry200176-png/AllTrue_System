<?php

namespace App\Services;

final class TrueFitService
{
    public static function enabled(): bool
    {
        return (bool) config('perfflags.truefit_v1', false);
    }
}
