<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260614180716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sessions table for PdoSessionHandler. Hand-pasted from Symfony docs because there is no Doctrine entity to diff (#68).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                sess_id VARCHAR(128) NOT NULL PRIMARY KEY,
                sess_data BYTEA NOT NULL,
                sess_lifetime INTEGER NOT NULL,
                sess_time INTEGER NOT NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
    }
}
