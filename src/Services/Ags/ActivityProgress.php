<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Services\Ags;

/**
 * The `activityProgress` field of an AGS score publish. A closed,
 * spec-defined set of five values that the tool itself chooses when
 * constructing an outbound Score — safe to enum, unlike claims parsed from
 * external platform input.
 */
enum ActivityProgress: string
{
    case Initialized = 'Initialized';
    case Started = 'Started';
    case InProgress = 'InProgress';
    case Submitted = 'Submitted';
    case Completed = 'Completed';
}
