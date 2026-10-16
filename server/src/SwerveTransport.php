<?php

namespace PhameServer;

use Phame\Net\Transport;
use Swerve\Swerve;

/** Adapts Swerve's publish/subscribe to Net\Transport, so the engine itself never mentions Swerve\*. */
final class SwerveTransport implements Transport
{
    public function publish(string $channel, string $payload): void
    {
        Swerve::publishRaw($channel, $payload);
    }
}
