<?php

namespace App\Actions;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SubmitFeedback
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, array $input, ?UploadedFile $screenshot): Feedback
    {
        Gate::forUser($actor)->authorize('create', Feedback::class);
        $data = Validator::make($input + ['screenshot' => $screenshot], [
            'request_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:10000'],
            'page' => ['required', 'string', Rule::in(array_keys($this->pages()))],
            'consent' => ['required', 'accepted'],
            'screenshot' => ['nullable', 'file', 'mimes:png', 'mimetypes:image/png', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
        ])->validate();

        $requestId = (string) $data['request_id'];
        $existing = Feedback::find($requestId);
        if ($existing) {
            Gate::forUser($actor)->authorize('view', $existing);

            return $existing;
        }

        $repository = config()->string('feedback.repository');
        $token = config()->string('feedback.token');
        abort_unless(preg_match('~^[A-Za-z0-9-]+/[A-Za-z0-9_.-]+$~D', $repository) && $token !== '', 503, 'Der GitHub-Versand ist noch nicht eingerichtet.');

        $client = Http::baseUrl('https://api.github.com')
            ->withToken($token)->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2026-03-10'])
            ->connectTimeout(5)->timeout(15)->withoutRedirecting();

        // Check visibility on every submission, before transmitting feedback content.
        try {
            $repo = $client->get('/repos/'.$repository);
        } catch (ConnectionException) {
            abort(503, 'GitHub ist momentan nicht erreichbar. Es wurde nichts übertragen.');
        }
        abort_unless($repo->ok() && $repo->json('private') === true && $repo->json('has_issues') === true && $repo->json('archived') === false, 503, 'Feedback benötigt ein erreichbares privates GitHub-Repository mit aktivierten Issues.');

        $path = $screenshot?->store('feedback', 'private');
        $metadata = [
            'reporter' => ['fullName' => $actor->name, 'email' => $actor->email],
            'environment' => app()->environment(),
            'build' => config()->string('feedback.build'),
            'plan' => config()->string('feedback.plan'),
            'page' => $this->pages()[$data['page']],
        ];
        try {
            $feedback = Feedback::create([
                'id' => $requestId, 'user_id' => $actor->id,
                'title' => $data['title'], 'description' => $data['description'],
                'metadata' => $metadata, 'screenshot_path' => $path,
                'repository' => $repository, 'status' => 'sending',
            ]);
        } catch (UniqueConstraintViolationException) {
            if (is_string($path)) {
                Storage::disk('private')->delete($path);
            }
            $feedback = Feedback::findOrFail($requestId);
            Gate::forUser($actor)->authorize('view', $feedback);

            return $feedback;
        } catch (\Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }

        // Never retry this POST automatically: a timeout can occur after issue creation.
        try {
            $response = $client->post('/repos/'.$repository.'/issues', [
                'title' => $feedback->title,
                'body' => $this->body($feedback),
            ]);
        } catch (ConnectionException) {
            $feedback->update(['status' => 'uncertain']);

            return $feedback;
        }

        if ($response->status() === 201 && is_int($response->json('number')) && $response->json('number') > 0) {
            $feedback->update(['status' => 'sent', 'issue_number' => $response->json('number')]);
        } else {
            // Provider response bodies and credentials are never stored or reported.
            $feedback->update(['status' => $response->clientError() ? 'failed' : 'uncertain']);
        }

        return $feedback;
    }

    /** @return array<string, string> */
    public function pages(): array
    {
        return ['dashboard' => 'Dashboard', 'documents' => 'Dokumente', 'ai-runs' => 'KI-Läufe', 'users' => 'Benutzerverwaltung', 'other' => 'Andere Seite'];
    }

    private function body(Feedback $feedback): string
    {
        $body = "## Feedback\n\n".$this->plainText($feedback->description);
        $body .= "\n\n## Kontext\n\n".$this->plainText(json_encode($feedback->metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if ($feedback->screenshot_path) {
            $url = rtrim(config()->string('app.url'), '/').'/feedback/'.$feedback->id.'/screenshot';
            $body .= "\n\n[Screenshot intern öffnen (Anmeldung als Admin erforderlich)](".$url.')';
        }

        return $body."\n\nReferenz: ".$feedback->id;
    }

    private function plainText(string $text): string
    {
        preg_match_all('/`+/', $text, $matches);
        $lengths = array_map(strlen(...), $matches[0]);
        $fence = str_repeat('`', max(3, ($lengths === [] ? 0 : max($lengths)) + 1));

        return $fence."text\n".$text."\n".$fence;
    }
}
