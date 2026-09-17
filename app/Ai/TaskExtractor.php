<?php

namespace App\Ai;

interface TaskExtractor
{
    public function extract(TaskInput $input): TaskResult;
}
