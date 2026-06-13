<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613192859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop events.default_window_minutes; photo match window is now hardcoded asymmetric (10 min before / 5 min after).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE events DROP default_window_minutes');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE events ADD default_window_minutes INT DEFAULT NULL');
    }
}
