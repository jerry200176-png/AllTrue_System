<?php

namespace App\Operations\Strategies;

/** Closed allowlist for the #4455 shared package ledger repair (read-only scan in GitHub #4455). Ids only. */
final class PackageLedgerNetRepairManifest
{
    /**
     * Class A, missing debit (15): package id => class session ids, one -1 row each. 28718 (package 160) and 36716
     * (package 176) were dropped after the production dry-run blocked them with a_session_net (the slot nets 0 over a
     * debit already on the lesson); they go to the #4470 follow-up repair.
     */
    private const MISSING_DEBIT = [
        61 => [11611], 122 => [37034], 123 => [30244], 124 => [30359], 134 => [27764, 35603], 137 => [30969],
        143 => [30516], 156 => [30981, 31146, 31208], 160 => [30934, 28710], 167 => [30135, 31012],
    ];

    /** Class A packages reconciled per lesson (one_on_one); the other class A packages are group types, reconciled per slot. */
    private const PER_LESSON_PACKAGES = [123];


    /** Class B, double debit `attended` + `attendance` (37 with package 227's 43180-43203): one +1 row each. */
    private const DOUBLE_DEBIT = [
        37 => [7123, 7127, 7129], 39 => [7140, 7142], 52 => [8395, 8396, 8397, 8398, 8399, 8400], 58 => [10682, 10688],
    ];

    /**
     * Round 2, class R (#4455 re-check 2026-10-28, read-only probe runs 40735822124..40736361927): package id => [class
     * session id, status as found (`deleted`, `cancelled`, or `attended` for a group slot's second debit), slot
     * `Y-m-d H:i`, package net as found]; one row of minus that net each, so the lesson nets 0. 37 extra debits: package
     * creation seeds whose lesson was deleted (re-created and debited again that day, or gone), cancelled seeds and one
     * attended seed sharing a debited slot; plus +1 credits left by the duplicate cleanup on 36788 (package 176) and 30936
     * (package 160), which held the slots of the blocked class A rows 36716 and 28718 at net 0. Packages 195 and 173's
     * 36236/36237 wait for the Founder (uncharged completed lessons, class D).
     */
    /**
     * Round 3, class R (Founder 2026-10-29 option A: used down to taught + absent, never below; post-round-2 probe runs
     * 40825430575 and 40825468531): 195 is 4 over, 173 1 over. Only deleted 16:00 creation seeds with no lesson that day
     * qualify (9 in 195, 2 in 173); the slot's second debit 38727 first (38696 holds 2026-07-12 16:00), then the latest
     * dates. The probe cannot tell which deleted date an uncharged `completed` lesson replaced, so which seeds keep their
     * debit is a choice; the balances are the same. Package 58 (10678) needs its course charge reversed first (rule 1).
     */
    private const OVER_DEBIT_R3 = [
        173 => [[36237, 'deleted', '2026-09-09 16:00', -1]],
        195 => [
            [38727, 'deleted', '2026-07-12 16:00', -1], [38704, 'deleted', '2026-08-11 16:00', -1],
            [38706, 'deleted', '2026-08-18 16:00', -1], [38708, 'deleted', '2026-08-25 16:00', -1],
        ],
    ];

    private const OVER_DEBIT = [
        133 => [
            [26750, 'deleted', '2026-06-30 16:00', -1], [26751, 'deleted', '2026-07-14 16:00', -1], [26752, 'deleted', '2026-07-21 16:00', -1],
            [26753, 'deleted', '2026-07-28 16:00', -1],
        ],
        140 => [
            [26838, 'deleted', '2026-06-22 16:00', -1], [26839, 'deleted', '2026-06-24 16:00', -1],
        ],
        155 => [
            [28613, 'cancelled', '2026-05-09 16:00', -1], [28614, 'cancelled', '2026-05-16 16:00', -1], [28615, 'cancelled', '2026-06-13 16:00', -1],
        ],
        160 => [[30936, 'cancelled', '2026-08-27 16:00', 1]],
        173 => [
            [36238, 'deleted', '2026-09-12 16:00', -1],
        ],
        175 => [
            [36669, 'deleted', '2026-08-28 16:00', -1], [36670, 'deleted', '2026-09-04 16:00', -1], [36671, 'deleted', '2026-09-11 16:00', -1],
        ],
        176 => [
            [36693, 'deleted', '2026-07-28 16:00', -1], [36694, 'deleted', '2026-07-29 16:00', -1], [36695, 'deleted', '2026-08-04 16:00', -1],
            [36696, 'deleted', '2026-08-05 16:00', -1], [36697, 'deleted', '2026-08-11 16:00', -1], [36698, 'deleted', '2026-08-25 16:00', -1],
            [36699, 'deleted', '2026-09-01 16:00', -1], [36700, 'deleted', '2026-09-08 16:00', -1], [36701, 'deleted', '2026-09-15 16:00', -1],
            [36702, 'deleted', '2026-07-25 16:00', -1], [36703, 'deleted', '2026-08-01 16:00', -1], [36704, 'deleted', '2026-08-08 16:00', -1],
            [36705, 'deleted', '2026-08-22 16:00', -1], [36706, 'deleted', '2026-08-29 16:00', -1], [36707, 'deleted', '2026-09-05 16:00', -1],
            [36708, 'deleted', '2026-09-12 16:00', -1], [36709, 'deleted', '2026-07-26 16:00', -1], [36710, 'deleted', '2026-08-02 16:00', -1],
            [36711, 'deleted', '2026-08-09 16:00', -1], [36712, 'deleted', '2026-08-23 16:00', -1], [36713, 'deleted', '2026-08-30 16:00', -1],
            [36714, 'deleted', '2026-09-06 16:00', -1], [36715, 'deleted', '2026-09-10 16:00', -1], [36788, 'cancelled', '2026-07-24 15:00', 1],
        ],
        207 => [[40256, 'attended', '2026-09-19 10:00', -1]],
    ];

    /** @var array<int, list<array<string, mixed>>> test-only override per round */
    private static array $override = [];

    /** @param list<array<string, mixed>>|null $rows */
    public static function useRowsForTesting(?array $rows, int $round = 1): void
    {
        if ($rows === null) {
            unset(self::$override[$round]);
        } else {
            self::$override[$round] = $rows;
        }
    }

    /** @return list<array<string, mixed>> class, package_id, class_session_id, scope (slot or session); class R also status, slot, net */
    public static function rows(int $round = 1): array
    {
        if (isset(self::$override[$round])) {
            return self::$override[$round];
        }
        if ($round >= 2) {
            $rows = [];
            foreach ($round === 2 ? self::OVER_DEBIT : self::OVER_DEBIT_R3 as $packageId => $lessons) {
                foreach ($lessons as [$id, $status, $slot, $net]) { // every round 2/3 package is a group type, reconciled per slot
                    $rows[] = ['class' => 'R', 'package_id' => $packageId, 'class_session_id' => $id, 'scope' => 'slot', 'status' => $status,
                        'slot' => $slot, 'net' => $net];
                }
            }

            return $rows;
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
