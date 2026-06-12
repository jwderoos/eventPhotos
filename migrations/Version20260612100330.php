<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612100330 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill events.starts_at/ends_at from date in event timezone; make NOT NULL; drop date column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE events
            SET starts_at = (date::timestamp AT TIME ZONE timezone) AT TIME ZONE 'UTC'
            WHERE starts_at IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE events
            SET ends_at = ((date + INTERVAL '1 day' - INTERVAL '1 minute')::timestamp AT TIME ZONE timezone) AT TIME ZONE 'UTC'
            WHERE ends_at IS NULL
            SQL);

        $this->addSql('ALTER TABLE events ALTER starts_at SET NOT NULL');
        $this->addSql('ALTER TABLE events ALTER ends_at SET NOT NULL');
        $this->addSql('ALTER TABLE events DROP date');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD date DATE NOT NULL');
        $this->addSql('ALTER TABLE events ALTER starts_at DROP NOT NULL');
        $this->addSql('ALTER TABLE events ALTER ends_at DROP NOT NULL');
    }
}
