<?php

namespace Tests;

use App\Actions\UploadTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ai.fake_delay' => 0]);
    }

    protected function upload(User $actor, string $text = 'Aufgabe 123'): Task
    {
        Storage::fake('private');
        Queue::fake();

        return app(UploadTask::class)->handle($actor, UploadedFile::fake()->createWithContent('task.txt', $text));
    }

    /** @return array<string, mixed> */
    protected function taskPayload(): array
    {
        return ['summary' => 'Korrigierte Zusammenfassung', 'excerpt' => 'Korrigierter Auszug', 'language' => 'de'];
    }
}
