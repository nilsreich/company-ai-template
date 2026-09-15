<?php

use App\Actions\UploadDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('ai.driver') !== 'fake') {
    throw new RuntimeException('Only local fake installations may run this fixture.');
}
if (($argv[1] ?? '') === 'create') {
    $user = User::where('is_demo', true)->where('active', true)->firstOrFail();
    $tmp = tempnam(sys_get_temp_dir(), 'invoice-');
    file_put_contents($tmp, 'Container lifecycle invoice');
    $file = new UploadedFile($tmp, 'lifecycle.txt', 'text/plain', null, true);
    $document = app(UploadDocument::class)->handle($user, $file);
    unlink($tmp);
} else {
    $document = Document::findOrFail((int) ($argv[2] ?? 0));
}
$run = $document->runs()->latest('id')->firstOrFail();
echo json_encode(['id' => $document->id, 'path' => $document->path, 'sha256' => $document->sha256, 'revision' => $document->revision, 'run_id' => $run->id, 'status' => $run->status->value, 'attempts' => $run->attempts], JSON_THROW_ON_ERROR).PHP_EOL;
