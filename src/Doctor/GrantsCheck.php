<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Grant\InvalidGrantsException;
use App\Grant\ProjectGrants;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 30)]
final readonly class GrantsCheck implements Check
{
    private const string TOPIC = 'Grants';

    public function __construct(
        private ProjectGrants $grants,
    ) {
    }

    #[\Override]
    public function run(): Finding
    {
        try {
            $grants = $this->grants->all();
        } catch (InvalidGrantsException $e) {
            return Finding::problem(self::TOPIC, $e->getMessage().' Airlock runs no command until it is fixed.');
        }

        if ([] === $grants) {
            return Finding::ok(self::TOPIC, 'No command is allowed always in this project.');
        }

        return Finding::ok(self::TOPIC, \sprintf('%d %s allowed always in this project: airlock grants lists them.', \count($grants), 1 === \count($grants) ? 'command is' : 'commands are'));
    }
}
