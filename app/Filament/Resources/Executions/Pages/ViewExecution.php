<?php

namespace App\Filament\Resources\Executions\Pages;

use App\Filament\Resources\Executions\ExecutionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewExecution extends ViewRecord
{
    protected static string $resource = ExecutionResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
