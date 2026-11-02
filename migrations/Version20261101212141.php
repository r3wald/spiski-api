<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Geteilte Listen (CLAUDE.md 12): Mitglieder, Teilen-Code und die Sicht je
 * Konto (sync_view), aus der der Pull liest.
 */
final class Version20261101212141 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Geteilte Listen: list_member, sync_view, shopping_list.share_code.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE list_member (joined_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, list_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (list_id, user_id))');
        $this->addSql('CREATE INDEX IDX_E93438563DAE168B ON list_member (list_id)');
        $this->addSql('CREATE INDEX idx_list_member_user ON list_member (user_id)');
        $this->addSql('CREATE TABLE sync_view (kind VARCHAR(16) NOT NULL, row_id UUID NOT NULL, version BIGINT NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id, kind, row_id))');
        $this->addSql('CREATE INDEX IDX_AC2B07C1A76ED395 ON sync_view (user_id)');
        $this->addSql('CREATE INDEX idx_sync_view_user_version ON sync_view (user_id, version)');
        $this->addSql('ALTER TABLE list_member ADD CONSTRAINT FK_E93438563DAE168B FOREIGN KEY (list_id) REFERENCES shopping_list (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE list_member ADD CONSTRAINT FK_E9343856A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sync_view ADD CONSTRAINT FK_AC2B07C1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE shopping_list ADD share_code VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3DC1A459CAD23D01 ON shopping_list (share_code)');

        // Bestand: Bisher sah jedes Konto genau seine eigenen Zeilen, mit der
        // serverVersion aus seinem eigenen Zaehler. Die Cursor der Clients
        // bleiben damit gueltig, niemand muss neu synchronisieren.
        $this->addSql("INSERT INTO sync_view (user_id, kind, row_id, version) SELECT user_id, 'list', id, server_version FROM shopping_list");
        $this->addSql("INSERT INTO sync_view (user_id, kind, row_id, version) SELECT user_id, 'item', id, server_version FROM shopping_item");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE list_member DROP CONSTRAINT FK_E93438563DAE168B');
        $this->addSql('ALTER TABLE list_member DROP CONSTRAINT FK_E9343856A76ED395');
        $this->addSql('ALTER TABLE sync_view DROP CONSTRAINT FK_AC2B07C1A76ED395');
        $this->addSql('DROP TABLE list_member');
        $this->addSql('DROP TABLE sync_view');
        $this->addSql('DROP INDEX UNIQ_3DC1A459CAD23D01');
        $this->addSql('ALTER TABLE shopping_list DROP share_code');
    }
}
