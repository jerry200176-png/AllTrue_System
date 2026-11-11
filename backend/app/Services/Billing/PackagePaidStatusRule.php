<?php

namespace App\Services\Billing;

use Illuminate\Support\Collection;

/**
 * Whether a monthly package counts as settled, from its members' billing statuses (#3985, Founder 2026-10-29):
 * any member still owing (unpaid / partial / unbilled) => owes; all members paid or free => settled;
 * no usable member verdict => the package flag. Used by 當月學收 package rows; AlertController::tuition keeps its
 * own copy of the same expression (Y11-protected) and a parity test pins the two together.
 */
final class PackagePaidStatusRule
{
    /** @param Collection<int, string> $memberStatuses */
    public static function settled(Collection $memberStatuses, bool $packageFlag): bool
    {
        if ($memberStatuses->contains(fn ($s) => in_array($s, ['unpaid', 'partial', 'unbilled'], true))) {
            return false;
        }

        return $memberStatuses->isNotEmpty() && $memberStatuses->every(fn ($s) => in_array($s, ['paid', 'free'], true))
            ? true
            : $packageFlag;
    }
}
