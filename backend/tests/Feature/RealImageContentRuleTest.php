<?php

namespace Tests\Feature;

use App\Rules\RealImageContent;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * #977 / CVE-2025-27515 class: the content-type guard must accept a genuine image
 * but reject a script disguised with an image extension (the bypass).
 *
 * Note: Laravel's UploadedFile::fake() guesses the mime from the EXTENSION, so it
 * cannot exercise content-based detection. These tests build REAL temp files
 * (actual bytes) wrapped in a test-mode UploadedFile, so getMimeType() runs finfo
 * on real content — exactly what happens for a real upload in production.
 */
class RealImageContentRuleTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    public function test_accepts_a_genuine_image(): void
    {
        $rule = new RealImageContent(['image/jpeg', 'image/png', 'image/webp']);
        $file = $this->realPng('photo.png');
        $this->assertTrue($rule->passes('avatar', $file), 'a real PNG must pass');
    }

    public function test_rejects_script_disguised_as_image(): void
    {
        $rule = new RealImageContent();
        $file = $this->realFile('photo.jpg', "<?php echo 'pwned'; ?>\n");
        $this->assertFalse($rule->passes('attachments.0', $file), 'PHP content under a .jpg name must be rejected');
    }

    public function test_rejects_html_disguised_as_image(): void
    {
        $rule = new RealImageContent();
        $file = $this->realFile('x.png', "<html><script>alert(1)</script></html>");
        $this->assertFalse($rule->passes('avatar', $file));
    }

    public function test_rejects_non_file_value(): void
    {
        $rule = new RealImageContent();
        $this->assertFalse($rule->passes('avatar', 'not-a-file'));
        $this->assertIsString($rule->message());
    }

    private function realPng(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $img = imagecreatetruecolor(16, 16);
        imagepng($img, $path);
        imagedestroy($img);
        $this->tmp[] = $path;
        return new UploadedFile($path, $name, null, null, true);
    }

    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evil');
        file_put_contents($path, $content);
        $this->tmp[] = $path;
        return new UploadedFile($path, $name, null, null, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }
}
