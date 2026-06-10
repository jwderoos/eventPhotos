<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610124103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add timezone column to events (IANA), backfilled to Europe/Amsterdam';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE events ADD timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Amsterdam'");
        $this->addSql('ALTER TABLE events ALTER COLUMN timezone DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP timezone');
    }
}
