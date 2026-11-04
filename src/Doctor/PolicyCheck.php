<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Policy\InvalidPolicyException;
use App\Policy\PolicyFiles;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 40)]
final readonly class PolicyCheck implements Check
{
    private const string TOPIC = 'Policy';

    public function __construct(
        private PolicyFiles $policyFiles,
    ) {
    }

    #[\Override]
    public function run(): Finding
    {
        try {
            $refusals = $this->policyFiles->refusals();
        } catch (InvalidPolicyException $e) {
            return Finding::problem(self::TOPIC, $e->getMessage().' Airlock runs no command until it is fixed.');
        }

        if ([] === $refusals) {
            return Finding::ok(self::TOPIC, 'No policy file refuses a command: Airlock\'s own rules apply.');
        }

        return Finding::ok(self::TOPIC, \sprintf('Your policy files refuse %d %s, on top of Airlock\'s own rules.', \count($refusals), 1 === \count($refusals) ? 'command' : 'commands'));
    }
}
