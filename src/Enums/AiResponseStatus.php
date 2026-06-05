<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Enums;

enum AiResponseStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failure = 'failure';
}
