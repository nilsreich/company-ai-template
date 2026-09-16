<?php

namespace App\Console\Commands;

use App\Ai\ExtractionInput;
use App\Ai\FakeDocumentExtractor;
use App\Ai\OpenAiDocumentExtractor;
use App\Ai\ValidateExtraction;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class EvaluateAi extends Command
{
    protected $signature = 'ai:eval {--dataset= : Internal fixture directory} {--driver=fake : fake or live} {--allow-external : Explicitly permit transmitting originals to the configured AI provider} {--model=} {--prompt-version=} {--min-accuracy=1} {--report= : New JSON report file, without document content}';

    protected $description = 'Evaluate extraction against approved Golden Dataset PDFs; nonzero exit on regression';

    public function handle(): int
    {
        $driver = $this->option('driver');
        if (! in_array($driver, ['fake', 'live'], true) || ($driver === 'live' && ! $this->option('allow-external'))) {
            $this->error('Live-Evaluation benötigt --driver=live --allow-external. Standard ist der lokale Fake.');

            return self::FAILURE;
        }
        $threshold = filter_var($this->option('min-accuracy'), FILTER_VALIDATE_FLOAT);
        if ($threshold === false || $threshold < 0 || $threshold > 1) {
            $this->error('min-accuracy muss zwischen 0 und 1 liegen.');

            return self::FAILURE;
        }
        $root = $this->option('dataset') ?: config()->string('golden.path');
        $manifests = glob($root.'/document-*-r*/expected.json') ?: [];
        if ($manifests === []) {
            $this->error('Keine Golden-Dataset-Fixtures vorhanden.');

            return self::FAILURE;
        }
        $extractor = $driver === 'fake' ? app(FakeDocumentExtractor::class) : app(OpenAiDocumentExtractor::class);
        $model = $this->option('model') ?: ($driver === 'fake' ? 'deterministic-v1' : config()->string('ai.model'));
        $prompt = $this->option('prompt-version') ?: config()->string('ai.prompt_version');
        $counts = array_fill_keys(Document::FIELDS, 0);
        $complete = 0;
        $failures = 0;
        foreach ($manifests as $path) {
            try {
                $fixture = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
                $pdf = File::get(dirname($path).'/original.pdf');
                if (($fixture['schema_version'] ?? null) !== 1 || ($fixture['mime_type'] ?? null) !== 'application/pdf' || ! hash_equals($fixture['source_sha256'] ?? '', hash('sha256', $pdf))) {
                    throw new \RuntimeException;
                }
                $expected = app(ValidateExtraction::class)->handle($fixture['fields'] ?? null);
                $result = $extractor->extract(new ExtractionInput($pdf, 1, $model, $prompt, mimeType: 'application/pdf'));
                $actual = app(ValidateExtraction::class)->handle($result->fields);
                $matched = 0;
                foreach (Document::FIELDS as $field) {
                    $a = $actual[$field] ?? null;
                    $b = $expected[$field] ?? null;
                    if (in_array($field, ['total_amount', 'net_amount', 'tax_amount'], true) && is_string($a) && is_string($b) && is_numeric($a) && is_numeric($b)) {
                        /** @var numeric-string $aNum */
                        $aNum = $a;
                        /** @var numeric-string $bNum */
                        $bNum = $b;
                        $same = bccomp($aNum, $bNum, 4) === 0;
                    } else {
                        $same = $a === $b;
                    }
                    if ($same) {
                        $counts[$field]++;
                        $matched++;
                    }
                }
                $complete += $matched === count(Document::FIELDS) ? 1 : 0;
            } catch (\Throwable) {
                $failures++;
                $this->warn('Fixture nicht auswertbar: '.basename(dirname($path)).' (keine Inhaltsausgabe).');
            }
        }
        $accuracy = array_sum($counts) / (count($manifests) * count(Document::FIELDS));
        $report = ['driver' => $driver, 'model' => $model, 'prompt_version' => $prompt, 'fixtures' => count($manifests), 'exact_documents' => $complete, 'errors' => $failures, 'field_matches' => $counts, 'field_accuracy' => $accuracy];
        $this->table(['Feld', 'Korrekt', 'Fälle'], array_map(fn (string $field): array => [$field, $counts[$field], count($manifests)], Document::FIELDS));
        $this->info(sprintf('Feldgenauigkeit: %.2f%%; vollständige Belege: %d/%d; Fehler: %d', $accuracy * 100, $complete, count($manifests), $failures));
        if ($path = $this->option('report')) {
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                $this->error('Reportdatei muss neu und schreibbar sein.');

                return self::FAILURE;
            }
            chmod($path, 0600);
            fwrite($handle, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            fclose($handle);
        }

        return $failures === 0 && $accuracy >= $threshold ? self::SUCCESS : self::FAILURE;
    }
}
