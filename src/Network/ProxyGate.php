<?php

declare(strict_types=1);

namespace App\Network;

/**
 * Decides whether a command reaches a host, and opens the connection when it does: the host must be listed, and every
 * address its name stands for must be public, so that a listed name cannot be pointed at this machine's own services
 * or a cloud's metadata endpoint. Each decision is logged. `localhost` on a listed local port is this machine's
 * service the user opened, as a database the tests use.
 */
final readonly class ProxyGate
{
    private const string LOCAL_HOST = 'localhost';

    private const string LOOPBACK = '127.0.0.1';

    /**
     * @param list<int> $localPorts
     */
    public function __construct(
        private AllowedHosts $allowedHosts,
        private Resolver $resolver,
        private Dialer $dialer,
        private DecisionLog $decisionLog,
        private array $localPorts = [],
    ) {
    }

    /**
     * @return resource
     *
     * @throws ProxyRefusedException
     */
    public function open(ProxyTarget $target)
    {
        if (self::LOCAL_HOST === $target->host && \in_array($target->port, $this->localPorts, true)) {
            return $this->openLocal($target);
        }
        if (!$this->allowedHosts->allows($target)) {
            throw $this->refuse($target, ProxyDecision::NotListed);
        }
        $addresses = $this->resolver->addressesOf($target->host);
        if ([] === $addresses) {
            throw $this->refuse($target, ProxyDecision::Unresolved);
        }
        foreach ($addresses as $address) {
            if (!$this->isPublic($address)) {
                throw $this->refuse($target, ProxyDecision::PrivateAddress);
            }
        }
        $connection = $this->dialAny($addresses, $target->port);
        if (null === $connection) {
            throw $this->refuse($target, ProxyDecision::Unreachable);
        }
        $this->decisionLog->record($target, ProxyDecision::Allowed);

        return $connection;
    }

    /**
     * @return resource
     *
     * @throws ProxyRefusedException
     */
    private function openLocal(ProxyTarget $target)
    {
        $connection = $this->dialAny([self::LOOPBACK], $target->port);
        if (null === $connection) {
            throw $this->refuse($target, ProxyDecision::Unreachable);
        }
        $this->decisionLog->record($target, ProxyDecision::Allowed);

        return $connection;
    }

    /**
     * The first of the addresses that answers, or null when none does.
     *
     * @param list<string> $addresses
     *
     * @return resource|null
     */
    private function dialAny(array $addresses, int $port)
    {
        foreach ($addresses as $address) {
            try {
                return $this->dialer->open($address, $port);
            } catch (NetworkStreamException) {
                // The next address may answer: the host is unreachable only once none does.
            }
        }

        return null;
    }

    private function isPublic(string $address): bool
    {
        return false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE);
    }

    private function refuse(ProxyTarget $target, ProxyDecision $decision): ProxyRefusedException
    {
        $this->decisionLog->record($target, $decision);

        return new ProxyRefusedException($target, $decision);
    }
}
