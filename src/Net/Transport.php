<?php

namespace Phame\Net;

/**
 * How a View sends bytes to viewers: named channels, published to and read by whatever pub/sub the deployment uses.
 * The engine depends on this, not on Swerve directly - a `SwerveTransport` (see docs/REMOTE-VIEW.md) adapts it.
 */
interface Transport
{
    /** Publishes $payload (JSON, or the binary description-batch format) on $channel to whoever is listening. */
    public function publish(string $channel, string $payload): void;
}
