<?php

use App\Actions\RejectAuditCleanup;
use App\Models\AuditEntry;
use Spatie\Activitylog\Actions\LogActivityAction;

return [
    'enabled' => true,
    'default_log_name' => 'tasks',
    'default_auth_driver' => null,
    'include_soft_deleted_subjects' => false,
    'activity_model' => AuditEntry::class,
    'default_except_attributes' => [],
    'buffer' => ['enabled' => false],
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => RejectAuditCleanup::class,
    ],
];
