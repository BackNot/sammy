<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Activity;
use App\Entity\AnswerSet;
use App\Entity\BusinessFunction;
use App\Entity\MaturityLevel;
use App\Entity\Practice;
use App\Entity\PracticeLevel;
use App\Entity\Question;
use App\Entity\Stream;
use App\Repository\MetamodelRepository;
use App\Service\Processing\YamlModelsToDbRecordsSyncer;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sync-from-owasp-samm')]
class SyncFromOwaspSammYamlCommand extends Command
{
    protected static $defaultName = 'app:sync-from-owasp-samm';
    private const METAMODEL_COMMAND_PARAMETER_NAME = 'metamodelId';

    public function __construct(
        private readonly YamlModelsToDbRecordsSyncer $dbRecordsSyncer,
        private readonly EntityManagerInterface $entityManager,
        private readonly MetamodelRepository $metamodelRepository,
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument(self::METAMODEL_COMMAND_PARAMETER_NAME, InputArgument::OPTIONAL, 'To which metamodel should this model be linked?');
        $this->setDescription('');
    }

    /**
     * The order in which the steps are invoked should be right, otherwise it may fail for foreign key constraints.
     *
     * @throws \Throwable
     * @throws ConnectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $metamodelId = $input->getArgument(self::METAMODEL_COMMAND_PARAMETER_NAME);
        $metamodelId = $metamodelId ?? 1;
        $metamodelId = (int) $metamodelId;

        $metamodel = $this->metamodelRepository->find($metamodelId);
        $this->dbRecordsSyncer->setMetamodel($metamodel);

        $this->entityManager->getConnection()->beginTransaction();
        try {
            // changing the order may fail because of FK constraints
            [$addedBusinessFuncs, $modifiedBusinessFuncs] = $this->dbRecordsSyncer->syncBusinessFunctions();
            [$addedSecurityPractices, $modifiedSecurityPractices] = $this->dbRecordsSyncer->syncSecurityPractices();
            [$addedMaturityLevels, $modifiedMaturityLevels] = $this->dbRecordsSyncer->syncMaturityLevels();
            [$addedPracticeLevels, $modifiedPracticeLevels] = $this->dbRecordsSyncer->syncPracticeLevels();
            [$addedStreams, $modifiedStreams] = $this->dbRecordsSyncer->syncStreams();
            [$addedActivities, $modifiedActivities] = $this->dbRecordsSyncer->syncActivities();
            [$addedAnswerSets, $modifiedAnswerSets] = $this->dbRecordsSyncer->syncAnswerSets();
            [$addedQuestions, $modifiedQuestions] = $this->dbRecordsSyncer->syncQuestions();
            $this->entityManager->getConnection()->commit();
        } catch (\Throwable $t) {
            $this->entityManager->getConnection()->rollBack();
            $this->entityManager->clear();
            $output->writeln('Failed');
            throw $t;
        }

        $table = new Table($output);
        $table
            ->setHeaders(['Name', 'Added', 'Updated', 'Deleted'])
            ->setRows([
                [BusinessFunction::class, $addedBusinessFuncs, $modifiedBusinessFuncs],
                [Practice::class, $addedSecurityPractices, $modifiedSecurityPractices],
                [MaturityLevel::class, $addedMaturityLevels, $modifiedMaturityLevels],
                [PracticeLevel::class, $addedPracticeLevels, $modifiedPracticeLevels],
                [Stream::class, $addedStreams, $modifiedStreams],
                [Activity::class, $addedActivities, $modifiedActivities],
                [AnswerSet::class, $addedAnswerSets, $modifiedAnswerSets],
                [Question::class, $addedQuestions, $modifiedQuestions],
            ])->render();

        return Command::SUCCESS;
    }
}
