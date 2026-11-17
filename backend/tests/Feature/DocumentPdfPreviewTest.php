<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Site;
use App\Models\User;
use App\Services\DocumentLibrary;
use Aws\Result;
use Aws\S3\S3Client;
use Database\Seeders\AccessProfileSeeder;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DocumentPdfPreviewTest extends TestCase
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

    private function upload(User $user, string $bytes, array $data = []): Document
    {
        return app(DocumentLibrary::class)->upload($user, ['title' => 'Private PDF fixture', ...$data], UploadedFile::fake()->createWithContent('report.pdf', $bytes));
    }

    public function test_pdf_ranges_are_exact_on_each_disk_and_original_downloads_remain_complete(): void
    {
        $user = User::factory()->create();
        $bytes = "%PDF-1.4\n".str_repeat('Fictional page data.', 100);
        $size = strlen($bytes);
        foreach (['documents', 'documents_s3'] as $disk) {
            $pdf = $this->upload($user, $bytes, ['disk' => $disk]);
            $url = '/api/v1/documents/'.$pdf->id.'/preview';
            foreach (['bytes=0-0' => [0, 0], 'bytes=100-199' => [100, 199], 'bytes=100-' => [100, $size - 1], 'bytes=-20' => [$size - 20, $size - 1], 'bytes=10-999999999999999999999' => [10, $size - 1]] as $header => [$start, $end]) {
                $response = $this->actingAs($user)->withHeader('Range', $header)->get($url)->assertStatus(206)
                    ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Accept-Ranges', 'bytes')
                    ->assertHeader('Content-Range', 'bytes '.$start.'-'.$end.'/'.$size)
                    ->assertHeader('Content-Length', (string) ($end - $start + 1))
                    ->assertHeader('ETag', '"'.$pdf->sha256.'"')->assertHeader('Content-Disposition', 'inline')
                    ->assertHeader('X-Content-Type-Options', 'nosniff');
                $this->assertSame(substr($bytes, $start, $end - $start + 1), $response->streamedContent());
            }
            foreach (['bytes='.$size.'-', 'bytes=-0', 'bytes=9-4'] as $range) {
                $this->withHeader('Range', $range)->get($url)->assertStatus(416)->assertHeader('Content-Range', 'bytes */'.$size)->assertContent('');
            }
            foreach (['bytes=bogus', 'items=0-1', 'bytes=0-1,4-5'] as $range) {
                $response = $this->withHeader('Range', $range)->get($url)->assertOk()->assertHeader('Content-Length', (string) $size);
                $this->assertSame($bytes, $response->streamedContent());
            }
            $this->withHeader('Range', 'bytes=0-0')->head($url)->assertOk()->assertHeader('Content-Length', (string) $size)->assertContent('');
            $response = $this->withHeaders(['Range' => 'bytes=0-0', 'If-Range' => '"outdated"'])->get($url)->assertOk();
            $this->assertSame($bytes, $response->streamedContent());
            $this->withHeader('If-Range', '"'.$pdf->sha256.'"')->get($url)->assertStatus(206);
            $this->flushHeaders();
            $response = $this->get('/api/v1/documents/'.$pdf->id.'/download')->assertOk();
            $this->assertSame($bytes, $response->streamedContent());
        }
    }

    public function test_pdf_probe_is_bounded_and_ranges_preserve_current_access_and_missing_file_rules(): void
    {
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $site = Site::create(['name' => 'Private PDF site']);
        $other = Site::create(['name' => 'Other PDF site']);
        $bytes = str_pad("%PDF-1.4\n", 8 * 1024 * 1024, ' ');
        $pdf = $this->upload($admin, $bytes, ['subject' => ['type' => 'site', 'id' => $site->id]]);
        $url = '/api/v1/documents/'.$pdf->id.'/preview';
        $this->withHeader('Range', 'bytes=0-0')->getJson($url)->assertUnauthorized();
        $response = $this->actingAs($admin)->get($url)->assertStatus(206)->assertHeader('Content-Length', '1');
        $this->assertSame('%', $response->streamedContent());
        $reader = User::factory()->create(['location_access' => [['type' => 'site', 'id' => $other->id]]]);
        $this->actingAs($reader)->getJson($url)->assertNotFound();
        $this->actingAs($admin);
        $pdf->forceFill(['media_type' => 'text/html'])->save();
        $this->getJson($url)->assertNotFound();
        $pdf->forceFill(['media_type' => 'application/pdf'])->save();
        Storage::disk('documents')->delete($pdf->path);
        $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'documents.errors.fileUnavailable');
        $this->actingAs(User::factory()->create(['is_active' => false]))->getJson($url)->assertUnauthorized();
    }

    public function test_s3_ignoring_a_range_does_not_send_incorrect_partial_bytes(): void
    {
        $user = User::factory()->create();
        $pdf = $this->upload($user, "%PDF-1.4\nFictional PDF contents");
        $pdf->forceFill(['disk' => 'documents_s3'])->save();
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('getObject')->once()->andReturn(new Result(['Body' => Utils::streamFor('Wrong full response')]));
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('getConfig')->andReturn(['bucket' => 'fictional-bucket']);
        $disk->shouldReceive('path')->andReturn($pdf->path);
        $disk->shouldReceive('getClient')->andReturn($client);
        Storage::shouldReceive('disk')->with('documents_s3')->andReturn($disk);
        $this->actingAs($user)->withHeader('Range', 'bytes=9-17')->getJson('/api/v1/documents/'.$pdf->id.'/preview')
            ->assertStatus(503)->assertJsonPath('error.code', 'documents.errors.storageUnavailable');
    }

    public function test_s3_ranges_are_forwarded_upstream_with_the_configured_bucket_and_key_prefix(): void
    {
        $user = User::factory()->create();
        $pdf = $this->upload($user, "%PDF-1.4\nFictional PDF contents");
        $pdf->forceFill(['disk' => 'documents_s3'])->save();
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('getObject')->once()->with([
            'Bucket' => 'fictional-bucket', 'Key' => 'prefix/'.$pdf->path,
            'Range' => 'bytes=9-17', '@http' => ['stream' => true],
        ])->andReturn(new Result(['Body' => Utils::streamFor('Fictional'), 'ContentRange' => 'bytes 9-17/'.$pdf->size_bytes]));
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('exists')->with($pdf->path)->andReturn(true);
        $disk->shouldReceive('getConfig')->andReturn(['bucket' => 'fictional-bucket']);
        $disk->shouldReceive('path')->with($pdf->path)->andReturn('prefix/'.$pdf->path);
        $disk->shouldReceive('getClient')->andReturn($client);
        Storage::shouldReceive('disk')->with('documents_s3')->andReturn($disk);
        $response = $this->actingAs($user)->withHeader('Range', 'bytes=9-17')->get('/api/v1/documents/'.$pdf->id.'/preview')->assertStatus(206);
        $this->assertSame('Fictional', $response->streamedContent());
    }
}
