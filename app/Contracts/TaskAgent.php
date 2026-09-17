<?php

namespace App\Contracts;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * A structured-output agent bound to one prompt version.
 *
 * The live extractor instantiates the configured class per execution,
 * so historic prompt versions can be resolved to archived prompts.
 */
interface TaskAgent extends Agent, HasStructuredOutput
{
    public function __construct(string $promptVersion);
}
