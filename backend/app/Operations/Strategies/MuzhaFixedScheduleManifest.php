<?php

namespace App\Operations\Strategies;

/** Immutable, production-observed 2026-10-01 preconditions for four course IDs. */
final class MuzhaFixedScheduleManifest
{
    /** The 10/3 math exceptions remain at 17:00 because Chinese is booked at 15:00. */
    public static function preservedConflictSessionIds(): array
    {
        return [19267, 19324];
    }

    public static function cases(): array
    {
        $cases = [
            3428 => [
                'student' => 373, 'name' => '張正樂', 'old' => '19:30', 'new' => '10:00',
                'count' => 8, 'used' => 4, 'remaining' => 4, 'start' => '2026-08-29', 'end' => '2026-10-24',
                'rows' => [
                    33781 => ['2026-10-03', '10:00', 'scheduled', 1],
                    33782 => ['2026-10-03', '19:30', 'cancelled', 0],
                    33783 => ['2026-10-10', '19:30', 'scheduled', 0],
                    33784 => ['2026-10-15', '19:30', 'cancelled', 0],
                    42412 => ['2026-10-17', '19:30', 'scheduled', 0],
                    42472 => ['2026-10-24', '19:30', 'scheduled', 0],
                ],
            ],
            3429 => [
                'student' => 374, 'name' => '張正甯', 'old' => '19:00', 'new' => '10:00',
                'count' => 8, 'used' => 4, 'remaining' => 4, 'start' => '2026-08-29', 'end' => null,
                'rows' => [
                    32870 => ['2026-10-03', '10:00', 'scheduled', 1],
                    32871 => ['2026-10-10', '19:00', 'scheduled', 0],
                    32872 => ['2026-10-17', '19:00', 'scheduled', 0],
                    32881 => ['2026-10-22', '19:00', 'cancelled', 0],
                    41621 => ['2026-10-24', '19:00', 'scheduled', 0],
                ],
            ],
            2332 => [
                'student' => 155, 'name' => '吳宏逸', 'old' => '15:00', 'new' => '15:00',
                'count' => 16, 'used' => 4, 'remaining' => 12, 'start' => '2026-08-15', 'end' => '2026-12-26',
                'rows' => [
                    19267 => ['2026-10-03', '17:00', 'scheduled', 1],
                    19268 => ['2026-10-10', '10:00', 'scheduled', 0],
                    19269 => ['2026-10-17', '10:00', 'scheduled', 0],
                    19270 => ['2026-10-24', '10:00', 'scheduled', 0],
                    19271 => ['2026-10-31', '10:00', 'scheduled', 0],
                    19272 => ['2026-11-07', '10:00', 'scheduled', 0],
                    19273 => ['2026-11-14', '10:00', 'scheduled', 0],
                    19274 => ['2026-11-21', '10:00', 'scheduled', 0],
                    19275 => ['2026-11-28', '10:00', 'scheduled', 0],
                    32735 => ['2026-12-05', '10:00', 'scheduled', 0],
                    33815 => ['2026-12-12', '10:00', 'scheduled', 0],
                    35545 => ['2026-12-19', '10:00', 'scheduled', 0],
                ],
            ],
            2335 => [
                'student' => 156, 'name' => '吳宛庭', 'old' => '15:00', 'new' => '15:00',
                'count' => 16, 'used' => 13, 'remaining' => 3, 'start' => '2026-08-08', 'end' => '2026-12-05',
                'rows' => [
                    19324 => ['2026-10-03', '17:00', 'scheduled', 1],
                    19325 => ['2026-10-10', '15:00', 'scheduled', 1],
                    19326 => ['2026-10-17', '15:00', 'scheduled', 1],
                    19328 => ['2026-10-31', '10:00', 'cancelled', 0],
                    19329 => ['2026-11-07', '10:00', 'cancelled', 0],
                    19320 => ['2026-11-14', '10:00', 'cancelled', 0],
                    19321 => ['2026-11-21', '10:00', 'cancelled', 0],
                    19322 => ['2026-11-28', '10:00', 'cancelled', 0],
                    33814 => ['2026-12-05', '10:00', 'scheduled', 0],
                ],
            ],
        ];

        return $cases;
    }
}
