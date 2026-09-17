<?php

namespace Tests\Feature;

use App\Actions\RetrieveChunks;
use App\Actions\UploadTask;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskRetrievalTest extends TestCase
{
    use RefreshDatabase;

    public function test_txt_upload_creates_overlapping_chunks(): void
    {
        config(['rag.chunk_size' => 100, 'rag.chunk_overlap' => 10, 'rag.max_chunks' => 50]);
        $task = $this->upload(User::factory()->create(), str_repeat('Aufgabe Betrag 123,45 EUR. ', 20));

        $chunks = $task->chunks()->orderBy('chunk_index')->get();
        $this->assertGreaterThan(1, $chunks->count());
        $this->assertSame(0, $chunks->first()->chunk_index);
        $this->assertStringStartsWith('Aufgabe', $chunks->first()->content);
        $this->assertNull($chunks->first()->embedding);
    }

    public function test_pdf_without_text_step_creates_no_chunks(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
        $user = User::factory()->create();
        Storage::fake('private');
        Queue::fake();
        $task = app(UploadTask::class)->handle(
            $user, UploadedFile::fake()->createWithContent('scan.pdf', $pdf)
        );

        $this->assertSame('application/pdf', $task->mime_type);
        $this->assertSame(0, $task->chunks()->count());
    }

    public function test_retrieval_checks_permission_before_search(): void
    {
        $ownerId = $this->upload(User::factory()->create(), 'Aufgabe Lieferant Musterstadt Betrag 50 EUR')->uploaded_by;
        $actor = User::find($ownerId);
        $hits = app(RetrieveChunks::class)->handle($actor, 'Musterstadt');

        $this->assertCount(1, $hits);
        $this->assertStringContainsString('Musterstadt', $hits[0]['content']);
        $this->assertSame([], app(RetrieveChunks::class)->handle($actor, 'unbekannteswortxyz'));
    }

    public function test_inactive_users_retrieve_nothing(): void
    {
        $owner = User::factory()->create();
        $this->upload($owner, 'Aufgabe Lieferant Musterstadt');
        $inactive = User::factory()->create(['active' => false]);

        try {
            app(RetrieveChunks::class)->handle($inactive, 'Musterstadt');
            $this->fail('Expected authorization failure');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }
}
