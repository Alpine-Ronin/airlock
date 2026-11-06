<?php

declare(strict_types=1);

namespace App\Tests\Doctor;

use App\Audit\AuditLog;
use App\Audit\OutputCheckTimeouts;
use App\Configuration\ConfigDirectory;
use App\Configuration\EffectiveModelSettings;
use App\Configuration\ModelSettingsFile;
use App\Doctor\Health;
use App\Doctor\ModelChecksCheck;
use App\Execution\ProjectDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;

final class ModelChecksCheckTest extends TestCase
{
    public function testALockThatCannotBeTakenLeavesTheTimeoutsUnknown(): void
    {
        $configDirectory = new ConfigDirectory('/nonexistent', sys_get_temp_dir().'/airlock-model-checks-test');
        $auditLog = new AuditLog($configDirectory, new LockFactory(new class implements PersistingStoreInterface {
            #[\Override]
            public function save(Key $key): void
            {
                throw new LockStorageException('The lock store is out of reach.');
            }

            #[\Override]
            public function delete(Key $key): void
            {
            }

            #[\Override]
            public function exists(Key $key): bool
            {
                return false;
            }

            #[\Override]
            public function putOffExpiration(Key $key, float $ttl): void
            {
            }
        }));
        $check = new ModelChecksCheck(
            new ModelSettingsFile($configDirectory),
            new EffectiveModelSettings('https://models.example.test', 'test-key', 'test/output-check', 'test/risk'),
            new OutputCheckTimeouts($auditLog),
            new ProjectDirectory(__DIR__),
        );

        $finding = $check->run();

        self::assertSame(Health::Warning, $finding->health);
        self::assertStringContainsString('Failed to acquire the "airlock-audit" lock. Whether the output check keeps timing out is unknown.', $finding->text);
    }
}
