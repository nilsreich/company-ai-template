<?php

namespace Tests\Feature;

use App\Actions\UploadDocument;
use App\Enums\RunStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContainerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_queue_dispatches_after_commit_and_real_worker_executes(): void
    {
        config(['queue.default' => 'database']);
        Storage::fake('private');
        $user = User::factory()->create();
        DB::beginTransaction();
        $document = app(UploadDocument::class)->handle($user, UploadedFile::fake()->createWithContent('invoice.txt', 'Real queue fixture'));
        $this->assertSame(0, DB::table('jobs')->count());
        DB::commit();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(RunStatus::Succeeded, $document->runs()->sole()->status);
    }
}
