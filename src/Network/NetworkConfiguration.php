<?php

declare(strict_types=1);

namespace App\Network;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * A network file, the user's own: hosts a sandboxed command may reach besides the package registries, and ports on
 * this machine it may reach, for a database its tests use.
 */
final class NetworkConfiguration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('airlock');
        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('allow')
                    ->info('Hosts a command may reach: a host name, port 443, or host:port. No wildcard.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('local')
                    ->info('Ports on 127.0.0.1 a command may reach, as a database its tests use.')
                    ->integerPrototype()->min(1)->max(65535)->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
