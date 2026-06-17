<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617165029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds user_sessions table + cascade-delete trigger on sessions (issue #76).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_sessions (sess_id VARCHAR(128) NOT NULL, ip VARCHAR(45) NOT NULL, user_agent TEXT NOT NULL, user_agent_display VARCHAR(128) DEFAULT NULL, country_code VARCHAR(2) DEFAULT NULL, label VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, PRIMARY KEY (sess_id))');
        $this->addSql('CREATE INDEX idx_user_sessions_user_id ON user_sessions (user_id)');
        $this->addSql('ALTER TABLE user_sessions ADD CONSTRAINT FK_7AED7913A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION user_sessions_cascade_delete() RETURNS trigger AS $$
            BEGIN
              DELETE FROM user_sessions WHERE sess_id = OLD.sess_id;
              RETURN OLD;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER on_sessions_delete
              AFTER DELETE ON sessions
              FOR EACH ROW EXECUTE FUNCTION user_sessions_cascade_delete()
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TRIGGER IF EXISTS on_sessions_delete ON sessions');
        $this->addSql('DROP FUNCTION IF EXISTS user_sessions_cascade_delete()');
        $this->addSql('ALTER TABLE user_sessions DROP CONSTRAINT FK_7AED7913A76ED395');
        $this->addSql('DROP TABLE user_sessions');
    }
}
