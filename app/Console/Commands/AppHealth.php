<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AppHealth extends Command
{
    protected $signature = 'app:health';

    protected $description = 'Check database and private storage availability';

    public function handle(): int
    {
        DB::select('SELECT 1');

        return is_writable(storage_path('app/private')) ? self::SUCCESS : self::FAILURE;
    }
}
