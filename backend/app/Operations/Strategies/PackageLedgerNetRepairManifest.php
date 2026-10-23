<?php

namespace App\Operations\Strategies;

/** Closed allowlist for the #4455 shared package ledger repair (read-only scan in GitHub #4455). Ids only. */
final class PackageLedgerNetRepairManifest
{
    /** Class A, missing debit (17): package id => class session ids, one -1 row each. */
    private const MISSING_DEBIT = [
        61 => [11611], 122 => [37034], 123 => [30244], 124 => [30359], 134 => [27764, 35603], 137 => [30969],
        143 => [30516], 156 => [30981, 31146, 31208], 160 => [30934, 28710, 28718], 167 => [30135, 31012], 176 => [36716],
    ];

    /** Class A packages reconciled per lesson (one_on_one); the other class A packages are group types, reconciled per slot. */
    private const PER_LESSON_PACKAGES = [123];

    /** Class B, double debit `attended` + `attendance` (37 with package 227's 43180-43203): one +1 row each. */
    private const DOUBLE_DEBIT = [
        37 => [7123, 7127, 7129], 39 => [7140, 7142], 52 => [8395, 8396, 8397, 8398, 8399, 8400], 58 => [10682, 10688],
    ];

    /** @var list<array{class:string,package_id:int,class_session_id:int,scope:string}>|null test-only override */
    private static ?array $override = null;

    /** @param list<array{class:string,package_id:int,class_session_id:int,scope:string}>|null $rows */
    public static function useRowsForTesting(?array $rows): void
    {
        self::$override = $rows;
    }

    /** @return list<array{class:string,package_id:int,class_session_id:int,scope:string}> scope: slot or session */
    public static function rows(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $rows = [];
        foreach (self::MISSING_DEBIT as $packageId => $ids) {
            $scope = in_array($packageId, self::PER_LESSON_PACKAGES, true) ? 'session' : 'slot';
            foreach ($ids as $id) {
                $rows[] = ['class' => 'A', 'package_id' => $packageId, 'class_session_id' => $id, 'scope' => $scope];
            }
        }
        foreach (self::DOUBLE_DEBIT + [227 => range(43180, 43203)] as $packageId => $ids) {
            foreach ($ids as $id) {
                $rows[] = ['class' => 'B', 'package_id' => $packageId, 'class_session_id' => $id, 'scope' => 'session'];
            }
        }

        return $rows;
    }
}
