<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $result
 * @property array<string, float|int>|null $confidence
 * @property array<string, int>|null $usage
 */
class Execution extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => ExecutionStatus::class, 'result' => 'array', 'confidence' => 'array', 'usage' => 'array', 'attempts' => 'integer', 'input_version' => 'integer', 'task_revision' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'available_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'applied' => 'boolean'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
