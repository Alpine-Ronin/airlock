<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Configuration\ConfigDirectory;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Airlock's directory holds the API key, the grants, the policy and the audit log: whoever else can read or write any
 * of it can read the key or let commands run without asking.
 */
#[AsTaggedItem(priority: 20)]
final readonly class PrivateFilesCheck implements Check
{
    private const string TOPIC = 'Airlock\'s files';

    private const int SHOWN = 3;

    public function __construct(
        private ConfigDirectory $configDirectory,
    ) {
    }

    #[\Override]
    public function run(): Finding
    {
        $directory = $this->configDirectory->path;
        if (!is_dir($directory)) {
            return Finding::ok(self::TOPIC, \sprintf('%s does not exist yet: Airlock creates it, open to you only, when it first writes there.', $directory));
        }

        try {
            $open = $this->openToOthers($directory);
        } catch (\UnexpectedValueException) {
            return Finding::problem(self::TOPIC, \sprintf('Airlock cannot read all of %s: it, and everything in it, should be yours. Check with: ls -laR %s', $directory, escapeshellarg($directory)));
        }
        if ([] === $open) {
            return Finding::ok(self::TOPIC, \sprintf('%s is open to you only.', $directory));
        }

        $shown = implode(', ', \array_slice($open, 0, self::SHOWN));
        $more = \count($open) > self::SHOWN ? \sprintf(' and %d more', \count($open) - self::SHOWN) : '';

        return Finding::problem(self::TOPIC, \sprintf('Others can open %s%s. Close them with: chmod -R go= %s', $shown, $more, escapeshellarg($directory)));
    }

    /**
     * @return list<string>
     */
    private function openToOthers(string $directory): array
    {
        $open = $this->isOpenToOthers($directory) ? [$directory] : [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo && !$entry->isLink() && $this->isOpenToOthers($entry->getPathname())) {
                $open[] = $entry->getPathname();
            }
        }

        return $open;
    }

    private function isOpenToOthers(string $path): bool
    {
        $permissions = fileperms($path);

        return false !== $permissions && 0 !== ($permissions & 0o077);
    }
}
