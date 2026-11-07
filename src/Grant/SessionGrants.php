<?php

declare(strict_types=1);

namespace App\Grant;

/**
 * Grants for as long as this Airlock runs: a client starts its own Airlock, so this is its session.
 */
final class SessionGrants
{
    /** @var list<Grant> */
    private array $grants = [];

    /**
     * A grant given again replaces the one it equals: it holds the sources as they are now.
     *
     * @param list<Grant> $grants
     */
    public function add(array $grants): void
    {
        $this->grants = GrantList::with($this->grants, $grants);
    }

    /**
     * @return list<Grant>
     */
    public function all(): array
    {
        return $this->grants;
    }
}
