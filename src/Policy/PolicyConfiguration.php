<?php

declare(strict_types=1);

namespace App\Policy;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * A policy file: the command lines it refuses and the hosts it keeps commands from, and nothing else, so a policy can
 * only ever forbid.
 */
final class PolicyConfiguration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('airlock');
        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('refuse')
                    ->info('Command lines that never run: a part of a line starting with the same program and arguments.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('refuse_hosts')
                    ->info('Host names a sandboxed command never reaches, on any port, package registries included.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
