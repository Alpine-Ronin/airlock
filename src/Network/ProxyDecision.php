<?php

declare(strict_types=1);

namespace App\Network;

/**
 * What the proxy did with a connection a command asked for.
 */
enum ProxyDecision: string
{
    case Allowed = 'allowed';
    /** The host, or its port, is not on the list. */
    case NotListed = 'not_listed';
    /** The host is listed, but its name points to this machine or a private network. */
    case PrivateAddress = 'private_address';
    case Unresolved = 'unresolved';
    case Unreachable = 'unreachable';

    /**
     * The status the proxy answers with.
     */
    public function status(): int
    {
        return match ($this) {
            self::Allowed => 200,
            self::NotListed, self::PrivateAddress => 403,
            self::Unresolved, self::Unreachable => 502,
        };
    }

    public function reasonPhrase(): string
    {
        return match ($this->status()) {
            200 => 'Connection established',
            403 => 'Forbidden',
            default => 'Bad Gateway',
        };
    }

    public function explain(ProxyTarget $target): string
    {
        return match ($this) {
            self::Allowed => \sprintf('Airlock: connected to %s.', $target->authority()),
            self::NotListed => \sprintf('Airlock: blocked a connection to %s, which is not a package registry.', $target->authority()),
            self::PrivateAddress => \sprintf('Airlock: blocked a connection to %s, whose name points to this machine or a private network.', $target->authority()),
            self::Unresolved => \sprintf('Airlock: %s has no address.', $target->host),
            self::Unreachable => \sprintf('Airlock: could not reach %s.', $target->authority()),
        };
    }
}
