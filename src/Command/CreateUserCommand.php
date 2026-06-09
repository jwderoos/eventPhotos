<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-user', description: 'Create a user')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $userPasswordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('displayName', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)
            ->addArgument('role', InputArgument::OPTIONAL, 'e.g. ROLE_ADMIN or ROLE_ORGANIZER', 'ROLE_ORGANIZER');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email       = $this->stringArgument($input, 'email');
        $displayName = $this->stringArgument($input, 'displayName');
        $password    = $this->stringArgument($input, 'password');
        $role        = $this->stringArgument($input, 'role');

        $user = new User($email, $displayName);
        $user->addRole($role);
        $user->setPassword($this->userPasswordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln('<info>User created.</info>');

        return Command::SUCCESS;
    }

    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Argument "%s" must be a non-empty string.', $name));
        }

        return $value;
    }
}
