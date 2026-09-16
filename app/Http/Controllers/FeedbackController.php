<?php

namespace App\Http\Controllers;

use App\Actions\SubmitFeedback;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedbackController extends Controller
{
    public function store(Request $request, SubmitFeedback $submit): JsonResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);
        $screenshot = $request->file('screenshot');
        abort_unless($screenshot === null || $screenshot instanceof UploadedFile, 422);
        $feedback = $submit->handle($actor, $request->only(['request_id', 'title', 'description', 'page', 'consent']), $screenshot);

        return response()->json([
            'id' => $feedback->id,
            'status' => $feedback->status,
            'issue_url' => $feedback->issue_number ? 'https://github.com/'.$feedback->repository.'/issues/'.$feedback->issue_number : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function screenshot(Feedback $feedback): StreamedResponse
    {
        Gate::authorize('screenshot', $feedback);
        abort_unless($feedback->screenshot_path && Storage::disk('private')->exists($feedback->screenshot_path), 404);

        return Storage::disk('private')->download($feedback->screenshot_path, 'feedback-'.$feedback->id.'.png', [
            'Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
