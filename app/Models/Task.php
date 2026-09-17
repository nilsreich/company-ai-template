<?php

namespace App\Models;

use App\Contracts\ResultValidator;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $guarded = ['id'];

    public function originalExecution(): ?Execution
    {
        return $this->executions()->where('input_version', $this->input_version)->where('applied', true)->whereNotNull('result')->oldest('id')->first();
    }

    protected function casts(): array
    {
        return ['status' => TaskStatus::class, 'revision' => 'integer', 'input_version' => 'integer', 'payload' => 'array', 'approved_at' => 'immutable_datetime'];
    }

    /** @return HasMany<Execution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /** @return HasMany<TaskChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(TaskChunk::class);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $payload = $this->getAttribute('payload');

        return is_array($payload) ? $payload : [];
    }

    /** @return array<string, mixed> */
    public function validatedPayload(): array
    {
        return app(ResultValidator::class)->handle($this->payload());
    }
}
