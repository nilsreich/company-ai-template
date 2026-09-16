<?php

namespace App\Actions;

use Spatie\Activitylog\Actions\CleanActivityLogAction;

final class RejectAuditCleanup extends CleanActivityLogAction
{
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        throw new \LogicException('Audit-Einträge dürfen nicht bereinigt werden.');
    }
}
