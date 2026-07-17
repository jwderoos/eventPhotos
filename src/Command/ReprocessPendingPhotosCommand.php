<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Message\ProcessPhoto;
use App\Repository\PhotoRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Re-dispatch ProcessPhoto for photos stranded in Pending (e.g. by the
 * historical dispatch-before-flush race, #109, or a worker that died mid-flight
 * without leaving a message). The rows are already committed Pending, so this
 * re-dispatch resets no status and cannot itself race. Fresh ingest
 * (reingest: false) so the ingest window guard applies.
 */
#[AsCommand(
    name: 'app:photo:reprocess-pending',
    description: 'Re-dispatch ProcessPhoto for photos stuck in Pending.',
)]
final class ReprocessPendingPhotosCommand extends Command
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'Restrict to a single event id.')
            ->addOption(
                'min-age',
                null,
                InputOption::VALUE_REQUIRED,
                'Only photos not updated within this many minutes (avoids racing in-flight uploads).',
                '5',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be dispatched; dispatch nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $minAgeOption = $input->getOption('min-age');
        if (!is_numeric($minAgeOption) || (int) $minAgeOption < 0) {
            $io->error('--min-age must be zero or a positive number of minutes.');

            return Command::INVALID;
        }

        $minAge = (int) $minAgeOption;

        $cutoff = new DateTimeImmutable()->modify(sprintf('-%d minutes', $minAge));

        $event = null;
        $eventOption = $input->getOption('event');
        if ($eventOption !== null) {
            if (!is_numeric($eventOption)) {
                $io->error('--event must be a numeric event id.');

                return Command::INVALID;
            }

            $eventId = (int) $eventOption;
            $event   = $this->em->find(Event::class, $eventId);
            if (!$event instanceof Event) {
                $io->error(sprintf('Event %d not found.', $eventId));

                return Command::FAILURE;
            }
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $stuck  = $this->photos->findStalePending($cutoff, $event);

        if ($stuck === []) {
            $io->success('No stuck Pending photos to reprocess.');

            return Command::SUCCESS;
        }

        foreach ($stuck as $photo) {
            $id      = (int) $photo->getId();
            $eventId = (int) $photo->getEvent()->getId();
            if ($dryRun) {
                $io->writeln(sprintf('  would dispatch ProcessPhoto(%d) [event %d]', $id, $eventId));
                continue;
            }

            $this->bus->dispatch(new ProcessPhoto($id));
        }

        $io->success(sprintf(
            '%s %d stuck Pending photo(s).',
            $dryRun ? 'Would reprocess' : 'Re-dispatched',
            count($stuck),
        ));

        return Command::SUCCESS;
    }
}
