<?php

declare(strict_types=1);

namespace App\Update;

use App\Execution\CommandRunner;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Checks a binary's attestation with the user's GitHub CLI, as install.sh does: when the GitHub CLI is installed and
 * logged in, a binary it finds no attestation for is refused; without it, the binary is not checked.
 */
#[AsAlias(AttestationCheck::class)]
final readonly class GitHubCliAttestationCheck implements AttestationCheck
{
    private const string RELEASE_WORKFLOW = 'https://github.com/'.Releases::REPOSITORY.'/.github/workflows/binaries.yaml';

    /**
     * Releases up to 0.6.1 were built when the repository was hbenabdallah/airlock: their attestations name that
     * workflow and stayed with that account when the repository moved to its organisation.
     */
    private const string FORMER_OWNER = 'hbenabdallah';

    private const string FORMER_RELEASE_WORKFLOW = 'https://github.com/'.self::FORMER_OWNER.'/airlock/.github/workflows/binaries.yaml';

    /** The first release whose binaries are attested. */
    private const string FIRST_ATTESTED_VERSION = '0.3.4';

    public function __construct(
        private CommandRunner $runner,
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
    ) {
    }

    #[\Override]
    public function check(string $binaryPath, string $tag): Attestation
    {
        if (version_compare(ltrim($tag, 'v'), self::FIRST_ATTESTED_VERSION, '<') || !$this->hasGitHubCliLogin()) {
            return Attestation::NotChecked;
        }
        $this->assertActiveLoginWorks();

        $isAttested = $this->isAttested($binaryPath, ['--repo', Releases::REPOSITORY], self::RELEASE_WORKFLOW, $tag)
            || $this->isAttested($binaryPath, ['--owner', self::FORMER_OWNER], self::FORMER_RELEASE_WORKFLOW, $tag);
        if (!$isAttested) {
            throw new UpdateFailedException(\sprintf('%s has no attestation that the release workflow of %s built it, from %s: not installed.', basename($binaryPath), Releases::REPOSITORY, $tag));
        }

        return Attestation::Verified;
    }

    /**
     * @param list<string> $where the gh flag and value that say where the attestation is stored
     */
    private function isAttested(string $binaryPath, array $where, string $releaseWorkflow, string $tag): bool
    {
        $identity = \sprintf('^%s@refs/tags/%s$', preg_quote($releaseWorkflow), preg_quote($tag));

        return $this->runner->runGitHubCli(['attestation', 'verify', $binaryPath, '--hostname', 'github.com', ...$where, '--cert-identity-regex', $identity])->isSuccessful();
    }

    private function hasGitHubCliLogin(): bool
    {
        return null !== $this->executableFinder->find('gh')
            && $this->runner->runGitHubCli(['auth', 'token', '--hostname', 'github.com'])->isSuccessful();
    }

    /**
     * Only the active account: gh auth status without --active fails when any other account of github.com has a
     * problem, which must not pass for "not logged in" and skip the check. A GitHub CLI too old for --active (before
     * 2.57.0) fails here too, and the update stops, as install.sh stops.
     */
    private function assertActiveLoginWorks(): void
    {
        if (!$this->runner->runGitHubCli(['auth', 'status', '--hostname', 'github.com', '--active'])->isSuccessful()) {
            throw new UpdateFailedException('The GitHub CLI cannot check where the binary was built: its login to github.com does not work, or it is older than 2.57.0. Run gh auth login, or update gh, then run airlock update again.');
        }
    }
}
