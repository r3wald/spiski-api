<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Letzter Schreiber eines Artikels (Konto), fuer `changedByOther` im Pull.
 * Bestehende Artikel bleiben `null` und gelten als nicht fremd geaendert.
 */
final class Version20261102192758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'shopping_item.last_user_id fuer Benachrichtigungen ueber Aenderungen anderer.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_item ADD last_user_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_item DROP last_user_id');
    }
}
