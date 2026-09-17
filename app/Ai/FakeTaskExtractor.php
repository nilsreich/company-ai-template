<?php

namespace App\Ai;

final class FakeTaskExtractor implements TaskExtractor
{
    public function extract(TaskInput $input): TaskResult
    {
        $delay = config()->integer('ai.fake_delay');
        if ($delay > 0) {
            sleep($delay);
        }
        if ($input->scenario === 'timeout') {
            throw new TaskFailure('timeout', true);
        }
        if ($input->scenario === 'rate_limit' && $input->attempt === 1) {
            throw new TaskFailure('rate_limit', true);
        }
        if ($input->scenario === 'invalid') {
            return new TaskResult([]);
        }

        $normalized = preg_replace('/\s+/', ' ', $input->text) ?? '';
        $payload = [
            'summary' => 'Demo-Zusammenfassung',
            'excerpt' => mb_substr(trim($normalized), 0, 200),
            'language' => 'de',
        ];

        return new TaskResult($payload, ['input_tokens' => 100, 'output_tokens' => 40], array_fill_keys(array_keys($payload), 0.95));
    }
}
