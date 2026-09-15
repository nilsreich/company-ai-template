<?php

namespace App\Ai;

interface DocumentExtractor
{
    public function extract(ExtractionInput $input): ExtractionResult;
}
