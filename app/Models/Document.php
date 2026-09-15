<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => DocumentStatus::class, 'revision' => 'integer', 'input_version' => 'integer', 'total_amount' => 'decimal:4', 'approved_at' => 'immutable_datetime'];
    }

    /** @return HasMany<AiRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /** @return array<string, mixed> */
    public function extractionFields(): array
    {
        return $this->only(['supplier', 'invoice_number', 'invoice_date', 'total_amount', 'currency']);
    }
}
