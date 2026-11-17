<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Site;
use App\Models\User;
use App\Services\DocumentLibrary;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('documents');
        Storage::fake('documents_s3');
        config(['documents.disk' => 'documents', 'filesystems.disks.documents_s3.key' => 'fictional-key',
            'filesystems.disks.documents_s3.secret' => 'fictional-secret', 'filesystems.disks.documents_s3.bucket' => 'fictional-bucket']);
    }

    private function image(int $width = 1200, int $height = 800): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 8) {
            for ($x = 0; $x < $width; $x += 8) {
                imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, (($x * 19391) ^ ($y * 7919)) & 0xFFFFFF);
            }
        }
        ob_start();
        imagejpeg($image, null, 95);

        return ob_get_clean();
    }

    private function upload(User $user, string $bytes, array $data = []): Document
    {
        return app(DocumentLibrary::class)->upload($user, ['title' => 'Thumbnail fixture', ...$data], UploadedFile::fake()->createWithContent('photo.jpg', $bytes));
    }

    public function test_derivatives_reduce_payload_on_both_disks_preserve_originals_and_reuse_private_storage(): void
    {
        $user = User::factory()->create();
        $original = $this->image(2400, 1600);
        foreach (['documents', 'documents_s3'] as $disk) {
            $photo = $this->upload($user, $original, ['disk' => $disk]);
            $response = $this->actingAs($user)->get('/api/v1/documents/'.$photo->id.'/thumbnail')
                ->assertOk()->assertHeader('Content-Type', 'image/webp')
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
            $bytes = $response->getContent();
            $this->assertSame([640, 427], array_slice(getimagesizefromstring($bytes), 0, 2));
            $this->assertLessThan(strlen($original), strlen($bytes));
            $response->assertHeader('Content-Length', (string) strlen($bytes));
            $files = array_values(array_filter(Storage::disk($disk)->files($photo->id), fn ($path) => $path !== $photo->path));
            $this->assertCount(1, $files);
            $this->assertSame('private', Storage::disk($disk)->getVisibility($files[0]));
            $modified = Storage::disk($disk)->lastModified($files[0]);
            $this->travel(10)->seconds();
            $again = $this->get('/api/v1/documents/'.$photo->id.'/thumbnail')->assertOk();
            $this->assertSame($bytes, $again->getContent());
            $this->assertSame($modified, Storage::disk($disk)->lastModified($files[0]));
            $this->assertSame($original, $this->get('/api/v1/documents/'.$photo->id.'/preview')->assertOk()->streamedContent());
            $this->assertSame($original, $this->get('/api/v1/documents/'.$photo->id.'/download')->assertOk()->streamedContent());
        }
    }

    public function test_cached_derivatives_require_current_access_original_bytes_and_safe_image_mime(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Private thumbnail site']);
        $other = Site::create(['name' => 'Other thumbnail site']);
        $photo = $this->upload($admin, $this->image(), ['subject' => ['type' => 'site', 'id' => $site->id]]);
        $url = '/api/v1/documents/'.$photo->id.'/thumbnail';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($admin)->get($url)->assertOk();
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $other->id]]]);
        $this->actingAs($reader)->getJson($url)->assertNotFound();
        $this->actingAs($admin);
        $photo->forceFill(['media_type' => 'text/html'])->save();
        $this->getJson($url)->assertNotFound();
        $photo->forceFill(['media_type' => 'image/jpeg'])->save();
        Storage::disk('documents')->delete($photo->path);
        $this->getJson($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['is_active' => false]))->getJson($url)->assertUnauthorized();
    }

    public function test_screen_previews_are_private_bounded_and_do_not_replace_originals(): void
    {
        $user = User::factory()->create();
        $site = Site::create(['name' => 'Screen preview site']);
        $other = Site::create(['name' => 'Other preview site']);
        $original = $this->image(2400, 1600);
        foreach (['documents', 'documents_s3'] as $disk) {
            $photo = $this->upload($user, $original, ['disk' => $disk, 'subject' => ['type' => 'site', 'id' => $site->id]]);
            $url = '/api/v1/documents/'.$photo->id.'/image-preview';
            $this->getJson($url)->assertUnauthorized();
            $response = $this->actingAs($user)->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
            $bytes = $response->getContent();
            $this->assertSame([1600, 1067], array_slice(getimagesizefromstring($bytes), 0, 2));
            $this->assertLessThan(strlen($original), strlen($bytes));
            $path = $photo->id.'/screen-v1-'.$photo->sha256.'.webp';
            $this->assertSame('private', Storage::disk($disk)->getVisibility($path));
            $this->assertSame($bytes, $this->get($url)->assertOk()->getContent());
            $this->assertSame($original, $this->get('/api/v1/documents/'.$photo->id.'/download')->assertOk()->streamedContent());
            $this->actingAs(User::factory()->create(['location_access' => [['type' => 'site', 'id' => $other->id]]]))->getJson($url)->assertNotFound();
            $this->actingAs($user);
            $photo->forceFill(['media_type' => 'text/html'])->save();
            $this->getJson($url)->assertNotFound();
            $photo->forceFill(['media_type' => 'image/jpeg'])->save();
            Storage::disk($disk)->delete($photo->path);
            $this->getJson($url)->assertNotFound();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_transparency_and_pixel_guard_preserve_available_originals(): void
    {
        $user = User::factory()->create();
        $image = imagecreatetruecolor(4, 4);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        $photo = app(DocumentLibrary::class)->upload($user, ['title' => 'Transparent image'], UploadedFile::fake()->createWithContent('image.png', $png));
        $response = $this->actingAs($user)->get('/api/v1/documents/'.$photo->id.'/thumbnail')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $result = imagecreatefromstring($response->getContent());
        $this->assertSame(127, imagecolorsforindex($result, imagecolorat($result, 0, 0))['alpha']);
        $this->assertSame([4, 4], [imagesx($result), imagesy($result)]);
        // Reject excessive decoded dimensions before attempting to allocate pixels.
        $oversized = substr_replace($png, pack('NN', 20000, 20000), 16, 8);
        $photo = app(DocumentLibrary::class)->upload($user, ['title' => 'Oversized dimensions'], UploadedFile::fake()->createWithContent('large.png', $oversized));
        $response = $this->get('/api/v1/documents/'.$photo->id.'/thumbnail')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($oversized, $response->streamedContent());
        $this->assertSame([$photo->path], Storage::disk('documents')->files($photo->id));
    }

    public function test_jpeg_exif_orientation_is_preserved_without_upscaling(): void
    {
        $user = User::factory()->create();
        $image = imagecreatetruecolor(80, 40);
        foreach ([[0, 0, 0xFF0000], [40, 0, 0x00FF00], [0, 20, 0x0000FF], [40, 20, 0xFFFF00]] as [$x, $y, $color]) {
            imagefilledrectangle($image, $x, $y, $x + 39, $y + 19, $color);
        }
        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = ob_get_clean();
        // Corner identities after all eight EXIF transforms (red, green, blue, yellow).
        $corners = [[0, 1, 2, 3], [1, 0, 3, 2], [3, 2, 1, 0], [2, 3, 0, 1], [0, 2, 1, 3], [2, 0, 3, 1], [3, 1, 2, 0], [1, 3, 0, 2]];
        foreach ($corners as $index => $expected) {
            $orientation = $index + 1;
            $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x0112, 3, 1, $orientation)."\0\0".pack('V', 0);
            $bytes = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
            $photo = $this->upload($user, $bytes);
            $response = $this->actingAs($user)->get('/api/v1/documents/'.$photo->id.'/thumbnail')->assertOk()->assertHeader('Content-Type', 'image/webp');
            $result = imagecreatefromstring($response->getContent());
            [$width, $height] = $orientation < 5 ? [80, 40] : [40, 80];
            $this->assertSame([$width, $height], [imagesx($result), imagesy($result)]);
            foreach ([[5, 5], [$width - 6, 5], [5, $height - 6], [$width - 6, $height - 6]] as $corner => [$x, $y]) {
                $rgb = imagecolorsforindex($result, imagecolorat($result, $x, $y));
                $identity = $rgb['blue'] > 160 ? 2 : ($rgb['red'] > 160 ? ($rgb['green'] > 160 ? 3 : 0) : 1);
                $this->assertSame($expected[$corner], $identity, 'EXIF orientation '.$orientation);
            }
        }
    }
}
