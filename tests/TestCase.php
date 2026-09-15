<?php

namespace Tests;

use App\Actions\UploadDocument;
use App\Models\Document;
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

    protected function upload(User $actor, string $text = 'Rechnung 123'): Document
    {
        Storage::fake('private');
        Queue::fake();

        return app(UploadDocument::class)->handle($actor, UploadedFile::fake()->createWithContent('invoice.txt', $text));
    }

    /** @return array<string, string> */
    protected function fields(): array
    {
        return ['supplier' => 'Korrigiert GmbH', 'invoice_number' => 'R-123', 'invoice_date' => '2026-09-01', 'total_amount' => '99.95', 'currency' => 'EUR'];
    }
}
