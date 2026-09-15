<?php

namespace App\Models;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRun extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => RunStatus::class, 'result' => 'array', 'usage' => 'array', 'attempts' => 'integer', 'input_version' => 'integer', 'document_revision' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'available_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'applied' => 'boolean'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
