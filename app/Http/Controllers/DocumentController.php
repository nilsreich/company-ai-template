<?php

namespace App\Http\Controllers;

use App\Actions\ExportDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function download(Document $document): StreamedResponse
    {
        Gate::authorize('download', $document);

        return Storage::disk('private')->download($document->path, 'document-'.$document->id.'.txt', ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function export(Request $request, Document $document, ExportDocument $export): Response
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return response($export->handle($actor, $document))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="document-'.$document->id.'.csv"')->header('Cache-Control', 'private, no-store');
    }

    public function status(Document $document): JsonResponse
    {
        Gate::authorize('view', $document);
        $run = $document->runs()->latest('id')->first();

        return response()->json(['status' => $document->status->value, 'run_status' => $run?->status->value])->header('Cache-Control', 'private, no-store');
    }
}
