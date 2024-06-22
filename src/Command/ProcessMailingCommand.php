<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MailingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ProcessMailingCommand extends Command
{
    protected static $defaultName = 'app:process-mailing';

    public function __construct(private MailingService $mailingService, private ParameterBagInterface $parameterBag)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Process mailing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $host = $this->parameterBag->get('phpmailer.smtp.host');
        $port = (int) $this->parameterBag->get('phpmailer.smtp.port');
        $username = $this->parameterBag->get('phpmailer.smtp.username');
        $password = $this->parameterBag->get('phpmailer.smtp.password');
        if ($host === '' || $port === 0 || $username === '' || $password === '') {
            $io->text('[' . date('c') . '] Mail cron - Missing mail configuration');

            return Command::SUCCESS;
        }
        $this->mailingService->processMailing();
        $io->text('[' . date('c') . '] Mail cron');

        return Command::SUCCESS;
    }
}
