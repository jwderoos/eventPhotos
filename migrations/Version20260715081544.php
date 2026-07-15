<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715081544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#111 add per-event preview size/quality settings';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE events ADD preview_long_edge INT DEFAULT 1600 NOT NULL');
        $this->addSql('ALTER TABLE events ADD preview_quality INT DEFAULT 85 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE events DROP preview_long_edge');
        $this->addSql('ALTER TABLE events DROP preview_quality');
    }
}
