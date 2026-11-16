<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ende-zu-Ende-Verschluesselung (CLAUDE.md 14): Zustand je Konto, Chiffretext
 * (`enc`) je Zeile, verpackte Listenschluessel fuer Besitzer und Mitglieder.
 *
 * Bestehende Konten starten als `plaintext`, bestehende Zeilen behalten ihren
 * Klartext (`enc` NULL). Fuer alte Apps aendert sich nichts.
 */
final class Version20261116094740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verschluesselung: app_user.encryption_state/key_check, enc und wrapped_key an Listen, Artikeln und Mitgliedschaften.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD encryption_state VARCHAR(16) DEFAULT \'plaintext\' NOT NULL');
        $this->addSql('ALTER TABLE app_user ADD encryption_changed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD migration_client_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD key_check VARCHAR(256) DEFAULT NULL');
        $this->addSql('ALTER TABLE list_member ADD wrapped_key VARCHAR(256) DEFAULT NULL');
        $this->addSql('ALTER TABLE shopping_item ADD enc TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE shopping_list ADD enc TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE shopping_list ADD wrapped_key VARCHAR(256) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP encryption_state');
        $this->addSql('ALTER TABLE app_user DROP encryption_changed_at');
        $this->addSql('ALTER TABLE app_user DROP migration_client_id');
        $this->addSql('ALTER TABLE app_user DROP key_check');
        $this->addSql('ALTER TABLE list_member DROP wrapped_key');
        $this->addSql('ALTER TABLE shopping_item DROP enc');
        $this->addSql('ALTER TABLE shopping_list DROP enc');
        $this->addSql('ALTER TABLE shopping_list DROP wrapped_key');
    }
}
