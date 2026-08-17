<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Services\Ags;

/**
 * The `gradingProgress` field of an AGS score publish. A closed,
 * spec-defined set of five values that the tool itself chooses when
 * constructing an outbound Score — safe to enum, unlike claims parsed from
 * external platform input.
 */
enum GradingProgress: string
{
    case FullyGraded = 'FullyGraded';
    case Pending = 'Pending';
    case PendingManual = 'PendingManual';
    case Failed = 'Failed';
    case NotReady = 'NotReady';
}
