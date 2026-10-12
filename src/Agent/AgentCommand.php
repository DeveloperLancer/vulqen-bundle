<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Foreground process. One envelope every 15 seconds on the same DSN.
 * An empty server_name stops the command before anything is sent.
 */
#[AsCommand(name: 'vulqen:agent', description: 'Sends host, FPM and OPcache metrics every 15 seconds.')]
final class AgentCommand extends Command
{
    public function __construct(
        private readonly AgentCycle $cycle,
        private readonly string $serverName,
        private readonly int $iterations = \PHP_INT_MAX,
        private readonly int $pauseSeconds = 15,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (trim($this->serverName) === '') {
            $output->writeln('server_name is empty.');

            return Command::FAILURE;
        }

        for ($i = 0; $i < $this->iterations; ++$i) {
            $this->cycle->capture();
            if ($this->pauseSeconds > 0 && $i + 1 < $this->iterations) {
                sleep($this->pauseSeconds);
            }
        }

        return Command::SUCCESS;
    }
}
