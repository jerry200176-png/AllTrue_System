<?php

namespace Tests\Feature;

use App\Http\Controllers\ScheduleDiscrepancyController;
use App\Models\ScheduleDiscrepancy;
use App\Services\ScheduleDiscrepancyNotifier;
use ReflectionMethod;
use Tests\TestCase;

class FrameworkDiscrepancyLabel2833Test extends TestCase
{
    public function test_actual_dto_and_message_producers_preserve_literal_unknown_and_missing_types(): void
    {
        $controller = app(ScheduleDiscrepancyController::class);
        $one = new ReflectionMethod($controller, 'formatOne');
        $list = new ReflectionMethod($controller, 'formatListRow');
        $message = new ReflectionMethod(ScheduleDiscrepancyNotifier::class, 'buildMessage');

        // Unsaved legacy-shaped models exercise the fallback contract without
        // claiming production has invalid types or sending any notification.
        foreach (['wrong_time', 'legacy.literal', '', null] as $type) {
            $expected = ScheduleDiscrepancy::TYPE_LABELS[$type ?? ''] ?? $type;
            $model = (new ScheduleDiscrepancy())->forceFill(['discrepancy_type' => $type]);
            $before = $model->getAttributes();
            $dto = $one->invoke($controller, $model);
            $row = $list->invoke($controller, ['discrepancy_type' => $type]);
            $text = $message->invoke(null, 'Isolated campus', 'Isolated reporter', $model);

            $this->assertSame($type, $dto['discrepancy_type']);
            $this->assertSame($expected, $dto['discrepancy_type_label']);
            $this->assertSame($expected ?? '其他', $row['discrepancy_type_label']);
            $this->assertStringContainsString("出入類型：" . $expected . "\n", $text);
            $this->assertSame($before, $model->getAttributes());
            $this->assertFalse($model->exists);
        }
        $this->assertSame('其他', $list->invoke($controller, [])['discrepancy_type_label']);
        $this->assertNull($one->invoke($controller, null));
    }
}
