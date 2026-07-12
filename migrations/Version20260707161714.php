<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707161714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add banner_filename and banner_updated_at to events (#93)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE events ADD banner_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE events ADD banner_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE events DROP banner_filename');
        $this->addSql('ALTER TABLE events DROP banner_updated_at');
    }
}
