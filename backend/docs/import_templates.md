# Excel Import Templates

## Students Import
Required headers:
- `name`, `campus_id`, `class_id`

Optional headers:
- `school_name`, `phone`, `rfid`, `line_id`, `telegram_id`, `telegram_id1`, `telegram_id2`, `enable`, `notify_token`

## Student Classes Import
Required headers:
- `student_id`, `grade_id`, `subject_id`, `teacher_id`, `by1`, `start_date`, `room_id`, `schedule_mode`

Optional headers:
- `end_date`, `period`, `total_hours`, `memo`, `charge`, `pay`, `pay_date`, `paid`,
  `discount`, `rate`, `learn_time_id`, `session_count`, `remaining_sessions`, `session_duration`,
  `class_type`

Schedule slots (up to 3):
- `slot1_weekday`, `slot1_time`
- `slot2_weekday`, `slot2_time`
- `slot3_weekday`, `slot3_time`
