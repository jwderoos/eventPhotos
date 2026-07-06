<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706095800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-organizer brand fields (brand_label, brand_logo_filename, brand_logo_updated_at, brand_url) to organizer_profiles';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organizer_profiles ADD brand_label VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE organizer_profiles ADD brand_logo_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE organizer_profiles ADD brand_logo_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE organizer_profiles ADD brand_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organizer_profiles DROP brand_label');
        $this->addSql('ALTER TABLE organizer_profiles DROP brand_logo_filename');
        $this->addSql('ALTER TABLE organizer_profiles DROP brand_logo_updated_at');
        $this->addSql('ALTER TABLE organizer_profiles DROP brand_url');
    }
}
