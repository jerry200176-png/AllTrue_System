<?php

namespace App\Services\Line;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentSignIn;
use App\Support\LineNotifySettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 刷卡機照片 → 家長 LINE：分校「刷卡通知」開關、Flex 卡（照片＋文字）或文字＋圖片 fallback、GD 縮圖。
 * 照片存檔與簽章網址仍在 SwipeRfidController（儲存／HTTP 邊界）。
 */
class SwipePhotoDelivery
{
    private const FLEX_IMAGE_MAX_PX = 1024;
    private const FLEX_RESIZE_MAX_PIXELS = 12_000_000; // 4000×3000 ≈ 48MB 解碼後
    private const PHOTO_TEXT_WINDOW_SECONDS = 120;

    public function pushToParents(Student $student, Campus $campus, string $imageUrl, ?string $flexRatio): int
    {
        $token = (string) ($campus->messaging_channel_token ?? '');
        if ($token === '' || !LineNotifySettings::enabled((int) $campus->getKey(), 'swipe')) {
            return 0;
        }
        $parents = app(ParentLinePush::class);
        $text = $this->swipePhotoText($student);

        return $parents->deliver(
            $parents->bindings((int) $student->getKey(), (int) $campus->getKey()),
            (int) $student->getKey(),
            (int) $campus->getKey(),
            'swipe_photo',
            function ($binding) use ($token, $text, $imageUrl, $flexRatio) {
                try {
                    // 照片＋文字做成 1 張 Flex 卡＝聊天室 1 則；altText 是通知列看到的字。
                    // 照片超過 Flex 上限又縮不了 → 退回文字＋圖片 2 則，家長至少收得到。
                    return app(LinePush::class)->send($token, $binding->line_user_id, $flexRatio !== null
                        ? [$this->swipePhotoFlex($text, $imageUrl, $flexRatio)]
                        : [
                            ['type' => 'text', 'text' => $text],
                            ['type' => 'image', 'originalContentUrl' => $imageUrl, 'previewImageUrl' => $imageUrl],
                        ], 5)->successful();
                } catch (\Throwable $e) {
                    Log::warning('swipe_photo_line_push_failed: ' . $e->getMessage());

                    return false;
                }
            }
        );
    }

    /**
     * LINE Flex 圖片上限 1024×1024。超過就用 GD 等比縮到 1024 並覆寫上傳暫存檔。
     * 回傳卡片用的長寬比 "w:h"；null = 超過又縮不了（沒有 GD、圖太大或讀不了），呼叫端改推一般圖片訊息。
     */
    public function fitForFlex(string $path): ?string
    {
        [$w, $h, $type] = @getimagesize($path) ?: [0, 0, 0];
        if ($w <= 0) {
            return null;
        }
        // 手機直拍的 JPEG 靠 EXIF 轉向：6/8 = 轉 90°，顯示的寬高對調。
        $orientation = $type === IMAGETYPE_JPEG && function_exists('exif_read_data')
            ? (int) (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $turned = in_array($orientation, [6, 8], true);
        if ($w <= self::FLEX_IMAGE_MAX_PX && $h <= self::FLEX_IMAGE_MAX_PX) {
            return $turned ? "{$h}:{$w}" : "{$w}:{$h}";
        }
        // 1MB 的檔案可以宣稱 20000×20000；解碼前先擋，避免 GD 吃光記憶體。
        if ($w * $h > self::FLEX_RESIZE_MAX_PIXELS || !function_exists('imagescale')) {
            return null;
        }
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if ($src === false) {
            return null;
        }
        // 重新編碼會丟掉 EXIF，所以先把像素轉正。ponytail: 鏡像（2/4/5/7）不處理，讀卡機相機不會出現。
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $src = imagerotate($src, $angle, 0);
            [$w, $h] = [imagesx($src), imagesy($src)];
        }
        $scale = self::FLEX_IMAGE_MAX_PX / max($w, $h);
        [$nw, $nh] = [max(1, (int) floor($w * $scale)), max(1, (int) floor($h * $scale))];
        $dst = imagescale($src, $nw, $nh);
        if ($dst === false) {
            return null;
        }
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        $ok = $type === IMAGETYPE_PNG ? imagepng($dst, $path) : imagejpeg($dst, $path, 85);

        return $ok ? "{$nw}:{$nh}" : null;
    }

    /** @return array<string,mixed> LINE Flex bubble：上面照片，下面文字。 */
    private function swipePhotoFlex(string $text, string $imageUrl, string $aspectRatio): array
    {
        return [
            'type' => 'flex',
            'altText' => $text,
            'contents' => [
                'type' => 'bubble',
                'hero' => [
                    'type' => 'image', 'url' => $imageUrl, 'size' => 'full',
                    // 用照片自己的比例，不裁切。不加點擊動作：簽章網址 7 天就失效。
                    'aspectRatio' => $aspectRatio, 'aspectMode' => 'fit',
                ],
                'body' => [
                    'type' => 'box', 'layout' => 'vertical',
                    'contents' => [['type' => 'text', 'text' => $text, 'weight' => 'bold', 'wrap' => true]],
                ],
            ],
        ];
    }

    /**
     * 照片配的文字。讀卡機不知道到班/離班，由 swipe-rfid 剛寫的今日刷卡紀錄判斷。
     * 只認 2 分鐘內的簽到/簽退；照片比刷卡先到或找不到紀錄 → 不寫到班/離班，避免講錯。
     */
    private function swipePhotoText(Student $student): string
    {
        $now = now();
        $latest = StudentSignIn::query()
            ->where('StudentID', $student->getKey())
            ->whereDate('SignInDT', $now->toDateString())
            // 只看刷卡寫的列；簽退後補的 presence-window、人工補登不算。
            ->whereIn('Memo', ['swipe-rfid', 'self_study'])
            ->orderByDesc('id')
            ->first();

        $recent = fn ($dt) => $dt && Carbon::parse($dt)->diffInSeconds($now, true) <= self::PHOTO_TEXT_WINDOW_SECONDS;
        $label = '刷卡';
        $at = $now;
        if ($latest && $recent($latest->getAttribute('SignOutDT'))) {
            $label = '離班';
            $at = Carbon::parse($latest->getAttribute('SignOutDT'));
        } elseif ($latest && !$latest->getAttribute('SignOutDT') && $recent($latest->getAttribute('SignInDT'))) {
            $label = '到班';
            $at = Carbon::parse($latest->getAttribute('SignInDT'));
        }

        return "{$student->name} 已於 {$at->format('H:i')} {$label}";
    }
}
