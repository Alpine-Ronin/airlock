<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `airlock` command line: it says what Airlock is and lists Airlock's own commands. The framework's commands
 * (cache:*, debug:*, assets:install...) are hidden from the list, and still run when named.
 */
final class AirlockApplication extends Application
{
    /** Airlock's commands, and the console's own ones a user needs. */
    public const array LISTED_COMMANDS = ['configure', 'connect', 'doctor', 'grants', 'log', 'mcp:server', 'help', 'list', 'completion'];

    private bool $areOtherCommandsHidden = false;

    #[\Override]
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $namesNoCommand = null === $this->getCommandName($input);
        $asksForHelp = $input->hasParameterOption(['--help', '-h'], true);
        $asksForTheVersion = $input->hasParameterOption(['--version', '-V'], true);
        if ($namesNoCommand && $asksForHelp && !$asksForTheVersion) {
            return parent::doRun(new ArrayInput([]), $output);
        }

        return parent::doRun($input, $output);
    }

    #[\Override]
    public function getHelp(): string
    {
        return $this->getLongVersion()."\n\n".<<<'HELP'
            <info>Airlock</info>: every agent command passes through it.
            An MCP server that lets any agent run shell commands on your machine, without trusting the agent with your
            machine: each command is read, weighed, asked to you in your client, run in a sandbox, and its output checked.

            <comment>Examples:</comment>
              airlock connect claude-code   Connect Airlock to Claude Code in this project (also codex, vscode, cursor, goose)
              airlock configure             Set up the model checks; --fastest keeps the fastest model that passes
              airlock doctor                Check that Airlock can protect this project
              airlock log                   Show the last decisions Airlock made in this project
              airlock grants                List the commands allowed always here; --revoke <number> takes one back
              airlock grants --preset php   Allow a project's tests, linters and analysis always (also node, python)
              airlock help <command>        Show every option of a command
            HELP;
    }

    #[\Override]
    protected function registerCommands(): void
    {
        parent::registerCommands();
        if ($this->areOtherCommandsHidden) {
            return;
        }
        $this->areOtherCommandsHidden = true;
        foreach (parent::all() as $name => $command) {
            if (!\in_array($name, self::LISTED_COMMANDS, true)) {
                $command->setHidden();
            }
        }
    }
}
