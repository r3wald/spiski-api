<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initiales Schema: Nutzer-Anker, Listen und Artikel inklusive der Sync-Felder
 * server_version und last_client_id (CLAUDE.md 3).
 */
final class Version20260821212956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initiales Schema fuer Nutzer, Listen und Artikel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, version_counter BIGINT DEFAULT 0 NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, last_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE shopping_item (id UUID NOT NULL, name VARCHAR(200) NOT NULL, quantity DOUBLE PRECISION DEFAULT NULL, unit VARCHAR(20) DEFAULT NULL, is_checked BOOLEAN NOT NULL, note VARCHAR(500) DEFAULT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, deleted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, moved_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, server_version BIGINT DEFAULT 0 NOT NULL, last_client_id UUID NOT NULL, user_id UUID NOT NULL, list_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6612795FA76ED395 ON shopping_item (user_id)');
        $this->addSql('CREATE INDEX IDX_6612795F3DAE168B ON shopping_item (list_id)');
        $this->addSql('CREATE INDEX idx_item_user_version ON shopping_item (user_id, server_version)');
        $this->addSql('CREATE TABLE shopping_list (id UUID NOT NULL, name VARCHAR(100) NOT NULL, color BIGINT DEFAULT 0 NOT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, deleted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, server_version BIGINT DEFAULT 0 NOT NULL, last_client_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3DC1A459A76ED395 ON shopping_list (user_id)');
        $this->addSql('CREATE INDEX idx_list_user_version ON shopping_list (user_id, server_version)');
        $this->addSql('ALTER TABLE shopping_item ADD CONSTRAINT FK_6612795FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE shopping_item ADD CONSTRAINT FK_6612795F3DAE168B FOREIGN KEY (list_id) REFERENCES shopping_list (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE shopping_list ADD CONSTRAINT FK_3DC1A459A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_item DROP CONSTRAINT FK_6612795FA76ED395');
        $this->addSql('ALTER TABLE shopping_item DROP CONSTRAINT FK_6612795F3DAE168B');
        $this->addSql('ALTER TABLE shopping_list DROP CONSTRAINT FK_3DC1A459A76ED395');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE shopping_item');
        $this->addSql('DROP TABLE shopping_list');
    }
}
