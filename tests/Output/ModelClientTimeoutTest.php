<?php

declare(strict_types=1);

namespace App\Tests\Output;

use App\Configuration\ModelApi;
use App\Output\ModelInjectionClassifier;
use App\Output\NotCheckedReason;
use App\Output\OutputChunker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The model client as configured, over a real connection to a model that answers too late: the output is reported as
 * not checked because the model did not answer in time, not as a model that could not be reached.
 */
final class ModelClientTimeoutTest extends TestCase
{
    private const string CONFIG = __DIR__.'/../../config/packages/ai_generic_platform.yaml';

    private const string DIRECTORY = __DIR__.'/../../var/test-model-timeout';

    private const string TIMEOUT_SETTING = '%env(float:AIRLOCK_AI_TIMEOUT)%';

    private const float TIMEOUT_SECONDS = 1.0;

    private const string SLOW_MODEL = <<<'PHP'
        <?php
        sleep(5);
        header('Content-Type: application/json');
        echo '{}';
        PHP;

    private Filesystem $filesystem;

    private ?Process $server = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::DIRECTORY);
        $this->filesystem->dumpFile(self::DIRECTORY.'/slow-model.php', self::SLOW_MODEL);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop(0);
        $this->filesystem->remove(self::DIRECTORY);
    }

    public function testAModelThatAnswersTooLateDidNotAnswerInTime(): void
    {
        $baseUrl = $this->startSlowModel();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());
        $platform = Factory::createPlatform($baseUrl, 'test-key', HttpClient::create($this->configuredOptions()), eventDispatcher: $dispatcher, supportsEmbeddings: false, name: 'models');
        $classifier = new ModelInjectionClassifier($platform, new OutputChunker(), new NullLogger(), new ModelApi($baseUrl, 'test-key'), 'test/output-check');

        $check = $classifier->check("Build finished.\n");

        self::assertSame(NotCheckedReason::Timeout, $check->reason);
    }

    /**
     * The options of the model client in the configuration, its timeout setting made short.
     *
     * @return array<string, mixed>
     */
    private function configuredOptions(): array
    {
        $client = Yaml::parseFile(self::CONFIG);
        foreach (['framework', 'http_client', 'scoped_clients', 'models.client'] as $key) {
            self::assertIsArray($client);
            $client = $client[$key] ?? null;
        }
        self::assertIsArray($client);
        unset($client['scope']);

        $options = [];
        foreach ($client as $name => $value) {
            self::assertIsString($name);
            $options[$name] = self::TIMEOUT_SETTING === $value ? self::TIMEOUT_SECONDS : $value;
        }

        return $options;
    }

    private function startSlowModel(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);

        $this->server = new Process([\PHP_BINARY, '-S', $address, self::DIRECTORY.'/slow-model.php'], self::DIRECTORY);
        $this->server->start();
        $this->server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'started'));

        return 'http://'.$address;
    }
}
