<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentAttemptService
{
    private const AUTO_TYPES = ['single_choice', 'multiple_choice', 'true_false', 'fill_blank'];

    public function configureQuestions(int $assessmentId, array $itemIds, int $campusId): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if (!$itemIds) {
            throw ValidationException::withMessages(['question_bank_item_ids' => '至少要選一題。']);
        }
        if (DB::table('assessment_attempts')->where('assessment_id', $assessmentId)->exists()) {
            throw ValidationException::withMessages(['question_bank_item_ids' => '已有學生作答，題目配置不可再變更。']);
        }
        if (DB::table('assessment_question_snapshots')->where('assessment_id', $assessmentId)->exists()) {
            throw ValidationException::withMessages(['question_bank_item_ids' => '題目已配置；如需調整請建立新的檢測。']);
        }

        $items = DB::table('question_bank_items as item')
            ->join('question_banks as bank', 'bank.id', '=', 'item.question_bank_id')
            ->whereIn('item.id', $itemIds)
            ->where('item.status', 'approved')
            ->where('bank.campus_id', $campusId)
            ->select('item.*')
            ->get()
            ->keyBy('id');
        if ($items->count() !== count($itemIds)) {
            throw ValidationException::withMessages(['question_bank_item_ids' => '只能配置此分校已核准的題目。']);
        }

        return DB::transaction(function () use ($assessmentId, $itemIds, $items) {
            foreach ($itemIds as $position => $itemId) {
                $item = $items->get($itemId);
                DB::table('assessment_question_snapshots')->insert([
                    'assessment_id' => $assessmentId,
                    'question_bank_item_id' => $item->id,
                    'question_key' => $item->question_key,
                    'version_no' => $item->version_no,
                    'question_type' => $item->question_type,
                    'prompt' => $item->prompt,
                    'choices' => $item->choices,
                    'answer' => $item->answer,
                    'explanation' => $item->explanation,
                    'knowledge_tag' => $item->knowledge_tag,
                    'difficulty' => $item->difficulty,
                    'points' => 1,
                    'position' => $position + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return $this->questionPayloads($assessmentId);
        });
    }

    public function questionPayloads(int $assessmentId): array
    {
        return DB::table('assessment_question_snapshots')
            ->where('assessment_id', $assessmentId)
            ->orderBy('position')
            ->get()
            ->map(fn ($row) => $this->questionPayload($row))
            ->values()
            ->all();
    }

    public function listAttempts(int $assessmentId): array
    {
        return DB::table('assessment_attempts as attempt')
            ->leftJoin('Student as student', 'student.id', '=', 'attempt.student_id')
            ->where('attempt.assessment_id', $assessmentId)
            ->where('attempt.status', '!=', 'voided')
            ->orderBy('attempt.student_id')
            ->orderBy('attempt.attempt_no')
            ->select('attempt.*', 'student.name as student_name')
            ->get()
            ->map(fn ($row) => $this->attemptPayload($row))
            ->values()
            ->all();
    }

    public function createAttempt(int $assessmentId, int $studentId, ?int $studentClassId, int $maxScore, ?int $userId): array
    {
        return DB::transaction(function () use ($assessmentId, $studentId, $studentClassId, $maxScore, $userId) {
            if (!DB::table('assessment_question_snapshots')->where('assessment_id', $assessmentId)->exists()) {
                throw ValidationException::withMessages(['assessment_id' => '此檢測尚未配置題目。']);
            }
            $attemptNo = ((int) DB::table('assessment_attempts')->where('assessment_id', $assessmentId)
                ->where('student_id', $studentId)->lockForUpdate()->max('attempt_no')) + 1;
            $id = DB::table('assessment_attempts')->insertGetId([
                'assessment_id' => $assessmentId,
                'student_id' => $studentId,
                'student_class_id' => $studentClassId,
                'attempt_no' => $attemptNo,
                'status' => 'in_progress',
                'max_score_snapshot' => $maxScore,
                'recorded_by_user_id' => $userId,
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $this->getAttempt($id);
        });
    }

    public function getAttempt(int $attemptId): array
    {
        $attempt = DB::table('assessment_attempts as attempt')
            ->leftJoin('Student as student', 'student.id', '=', 'attempt.student_id')
            ->where('attempt.id', $attemptId)
            ->select('attempt.*', 'student.name as student_name')
            ->first();
        if (!$attempt) abort(404, 'Not found');

        $answers = DB::table('assessment_answers as answer')
            ->join('assessment_question_snapshots as question', 'question.id', '=', 'answer.assessment_question_snapshot_id')
            ->where('answer.assessment_attempt_id', $attemptId)
            ->select('answer.*', 'question.position', 'question.question_type', 'question.prompt', 'question.choices', 'question.knowledge_tag', 'question.difficulty')
            ->orderBy('question.position')
            ->get()
            ->map(fn ($answer) => [
                'id' => (int) $answer->id,
                'question_id' => (int) $answer->assessment_question_snapshot_id,
                'position' => (int) $answer->position,
                'question_type' => $answer->question_type,
                'prompt' => $answer->prompt,
                'choices' => $this->decode($answer->choices),
                'knowledge_tag' => $answer->knowledge_tag,
                'difficulty' => (int) $answer->difficulty,
                'answer' => $this->decode($answer->answer),
                'score' => $answer->score !== null ? (float) $answer->score : null,
                'max_score' => (float) $answer->max_score,
                'status' => $answer->status,
                'review_note' => $answer->review_note,
            ])->values()->all();

        $payload = $this->attemptPayload($attempt);
        $payload['questions'] = $this->questionPayloads((int) $attempt->assessment_id);
        $payload['answers'] = $answers;
        return $payload;
    }

    public function saveAnswers(int $attemptId, array $answers): array
    {
        return DB::transaction(function () use ($attemptId, $answers) {
            $attempt = $this->lockAttempt($attemptId);
            if ($attempt->status !== 'in_progress') {
                throw ValidationException::withMessages(['status' => '已送出的作答不可再修改。']);
            }
            $questions = DB::table('assessment_question_snapshots')->where('assessment_id', $attempt->assessment_id)->get()->keyBy('id');
            foreach ($answers as $entry) {
                $questionId = (int) ($entry['question_id'] ?? 0);
                if (!$questions->has($questionId)) {
                    throw ValidationException::withMessages(['answers' => '作答包含不屬於此檢測的題目。']);
                }
                DB::table('assessment_answers')->updateOrInsert(
                    ['assessment_attempt_id' => $attemptId, 'assessment_question_snapshot_id' => $questionId],
                    ['answer' => json_encode($entry['answer'] ?? null, JSON_UNESCAPED_UNICODE), 'status' => 'pending', 'updated_at' => now(), 'created_at' => now()]
                );
            }
            return $this->getAttempt($attemptId);
        });
    }

    public function submit(int $attemptId): array
    {
        return DB::transaction(function () use ($attemptId) {
            $attempt = $this->lockAttempt($attemptId);
            if ($attempt->status !== 'in_progress') return $this->getAttempt($attemptId);
            $questions = DB::table('assessment_question_snapshots')->where('assessment_id', $attempt->assessment_id)->orderBy('position')->get()->keyBy('id');
            $answers = DB::table('assessment_answers')->where('assessment_attempt_id', $attemptId)->get()->keyBy('assessment_question_snapshot_id');
            $autoRaw = 0.0; $manualCount = 0;
            foreach ($questions as $question) {
                $answer = $answers->get($question->id);
                $given = $answer ? $this->decode($answer->answer) : null;
                if (!$answer) {
                    DB::table('assessment_answers')->insert([
                        'assessment_attempt_id' => $attemptId, 'assessment_question_snapshot_id' => $question->id,
                        'answer' => null, 'score' => 0, 'max_score' => $question->points,
                        'status' => in_array($question->question_type, self::AUTO_TYPES, true) ? 'auto_marked' : 'needs_review',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (!in_array($question->question_type, self::AUTO_TYPES, true)) $manualCount++;
                    continue;
                }
                if (in_array($question->question_type, self::AUTO_TYPES, true)) {
                    $correct = $this->matches($question->question_type, $given, $this->decode($question->answer));
                    $score = $correct ? (float) $question->points : 0.0;
                    $autoRaw += $score;
                    DB::table('assessment_answers')->where('id', $answer->id)->update(['score' => $score, 'max_score' => $question->points, 'status' => 'auto_marked', 'updated_at' => now()]);
                } else {
                    $manualCount++;
                    DB::table('assessment_answers')->where('id', $answer->id)->update(['score' => null, 'max_score' => $question->points, 'status' => 'needs_review', 'updated_at' => now()]);
                }
            }
            $totalRaw = (float) $questions->sum('points');
            $autoScore = $totalRaw > 0 ? round($autoRaw / $totalRaw * (float) $attempt->max_score_snapshot, 2) : 0;
            DB::table('assessment_attempts')->where('id', $attemptId)->update([
                'status' => $manualCount > 0 ? 'submitted' : 'reviewed',
                'auto_score' => $autoScore, 'manual_score' => $manualCount > 0 ? null : 0,
                'score' => $autoScore, 'percent' => $this->percent($autoScore, (float) $attempt->max_score_snapshot),
                'submitted_at' => now(), 'reviewed_at' => $manualCount > 0 ? null : now(), 'updated_at' => now(),
            ]);
            return $this->getAttempt($attemptId);
        });
    }

    public function review(int $attemptId, array $reviews, int $userId): array
    {
        return DB::transaction(function () use ($attemptId, $reviews, $userId) {
            $attempt = $this->lockAttempt($attemptId);
            if (!in_array($attempt->status, ['submitted', 'reviewed'], true)) {
                throw ValidationException::withMessages(['status' => '只有已送出的作答可以複核。']);
            }
            foreach ($reviews as $review) {
                $answer = DB::table('assessment_answers as answer')->join('assessment_question_snapshots as question', 'question.id', '=', 'answer.assessment_question_snapshot_id')->where('answer.id', (int) ($review['answer_id'] ?? 0))->where('answer.assessment_attempt_id', $attemptId)->select('answer.*', 'question.points', 'question.question_type')->first();
                if (!$answer || $answer->question_type !== 'short_answer') throw ValidationException::withMessages(['reviews' => '只能複核此作答中的簡答題。']);
                $score = (float) ($review['score'] ?? -1);
                if ($score < 0 || $score > (float) $answer->points) throw ValidationException::withMessages(['reviews' => '簡答題分數超出題目配分。']);
                DB::table('assessment_answers')->where('id', $answer->id)->update(['score' => $score, 'status' => 'reviewed', 'review_note' => $review['review_note'] ?? null, 'reviewed_by_user_id' => $userId, 'reviewed_at' => now(), 'updated_at' => now()]);
            }
            $rows = DB::table('assessment_answers')->where('assessment_attempt_id', $attemptId)->get();
            $pending = $rows->where('status', 'needs_review')->count();
            $raw = (float) $rows->sum('score');
            $totalRaw = (float) DB::table('assessment_question_snapshots')->where('assessment_id', $attempt->assessment_id)->sum('points');
            $score = $totalRaw > 0 ? round($raw / $totalRaw * (float) $attempt->max_score_snapshot, 2) : 0;
            DB::table('assessment_attempts')->where('id', $attemptId)->update([
                'status' => $pending ? 'submitted' : 'reviewed', 'manual_score' => $pending ? null : round($score - (float) ($attempt->auto_score ?? 0), 2),
                'score' => $score, 'percent' => $this->percent($score, (float) $attempt->max_score_snapshot),
                'reviewed_by_user_id' => $pending ? null : $userId, 'reviewed_at' => $pending ? null : now(), 'updated_at' => now(),
            ]);
            return $this->getAttempt($attemptId);
        });
    }

    private function lockAttempt(int $id)
    {
        $attempt = DB::table('assessment_attempts')->where('id', $id)->lockForUpdate()->first();
        if (!$attempt) abort(404, 'Not found');
        return $attempt;
    }

    private function questionPayload($row): array
    {
        return ['id' => (int) $row->id, 'question_bank_item_id' => (int) $row->question_bank_item_id, 'question_key' => $row->question_key, 'version_no' => (int) $row->version_no, 'question_type' => $row->question_type, 'prompt' => $row->prompt, 'choices' => $this->decode($row->choices), 'explanation' => $row->explanation, 'knowledge_tag' => $row->knowledge_tag, 'difficulty' => (int) $row->difficulty, 'points' => (float) $row->points, 'position' => (int) $row->position];
    }

    private function attemptPayload($row): array
    {
        return ['id' => (int) $row->id, 'assessment_id' => (int) $row->assessment_id, 'student_id' => (int) $row->student_id, 'student_class_id' => $row->student_class_id !== null ? (int) $row->student_class_id : null, 'student_name' => $row->student_name ?? null, 'attempt_no' => (int) $row->attempt_no, 'status' => $row->status, 'auto_score' => $row->auto_score !== null ? (float) $row->auto_score : null, 'manual_score' => $row->manual_score !== null ? (float) $row->manual_score : null, 'score' => $row->score !== null ? (float) $row->score : null, 'max_score' => (float) $row->max_score_snapshot, 'percent' => $row->percent !== null ? (float) $row->percent : null, 'notes' => $row->notes, 'started_at' => $row->started_at, 'submitted_at' => $row->submitted_at, 'reviewed_at' => $row->reviewed_at];
    }

    private function decode($value)
    {
        if ($value === null || $value === '') return null;
        return is_array($value) ? $value : json_decode($value, true);
    }

    private function matches(string $type, $given, $correct): bool
    {
        if ($type === 'multiple_choice') return $this->normalSet($given) === $this->normalSet($correct);
        if ($type === 'fill_blank') {
            $accepted = is_array($correct) ? $correct : [$correct];
            return in_array($this->normalText($given), array_map(fn ($value) => $this->normalText($value), $accepted), true);
        }
        if (is_array($given)) $given = $given[0] ?? null;
        if (is_array($correct)) $correct = $correct[0] ?? null;
        return $this->normalText($given) === $this->normalText($correct);
    }

    private function normalSet($value): array
    {
        $values = is_array($value) ? $value : [$value];
        $values = array_map(fn ($item) => $this->normalText($item), $values);
        sort($values);
        return array_values(array_unique($values));
    }

    private function normalText($value): string
    {
        return mb_strtolower(trim((string) (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value)));
    }

    private function percent(float $score, float $max): float
    {
        return $max > 0 ? round($score / $max * 100, 2) : 0;
    }
}
