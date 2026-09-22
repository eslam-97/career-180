<?php

declare(strict_types=1);

namespace App\Domain\Provider;

/**
 * §11: the three answers a payment provider can give, and the third one is the
 * point. "A timeout is not a failure" — Unknown means "I cannot tell you",
 * which is a different fact from "it did not happen" and drives a different
 * transition (§11.1).
 */
enum OutcomeStatus: string
{
    case Success = 'success';

    case Failure = 'failure';

    /**
     * §16.3: status() must itself be able to return this. Without it the
     * polling-exhaustion path into needs_review is untestable, and so is the
     * unresolved -> succeeded transition in §11.2.
     */
    case Unknown = 'unknown';
}
