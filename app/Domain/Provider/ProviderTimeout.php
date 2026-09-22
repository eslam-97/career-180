<?php

declare(strict_types=1);

namespace App\Domain\Provider;

use RuntimeException;

/**
 * §11: "a timeout is not a failure". This exception carries no information about
 * whether money moved, because the caller genuinely does not have that
 * information — which is the entire reason 'unknown' exists as a state.
 *
 * Anything that catches this and records 'failed' is the double-payment bug in
 * §11's opening paragraph.
 */
final class ProviderTimeout extends RuntimeException {}
