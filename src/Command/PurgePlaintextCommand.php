<?php

declare(strict_types=1);

namespace App\Command;

use App\Encryption\EncryptionPolicy;
use App\Entity\EncryptionState;
use App\Sync\SyncLock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loescht nach Ablauf der Frist (SPISKI_ENCRYPTION_DEADLINE) Listen und
 * Artikel aller Konten, die noch `plaintext` sind (CLAUDE.md 14). Taeglich
 * per Cron.
 *
 * Das Konto bleibt, ebenso sein `version_counter`: Ein Zuruecksetzen loeste
 * beim naechsten Sync `fullResync` aus, und das Geraet verwuerfe seine lokalen
 * Zeilen. So kann ein Geraet mit neuer App spaeter noch umstellen und laedt
 * dabei alles verschluesselt hoch.
 *
 * Konten in `migrating` bleiben unberuehrt: Ihre Zeilen sind teils schon
 * verschluesselt, und ein Loeschen mitten in der Umstellung verloere Artikel,
 * die das Geraet fuer hochgeladen haelt.
 *
 * Mitgliedschaften und Teilen-Codes verschwinden mit den Listen (Fremdschluessel).
 * Mitglieder sind selbst `plaintext` – Beitritt nur im selben Zustand, und
 * `begin` loest alle Teilen-Beziehungen.
 *
 * Danach haelt PostgreSQL den Klartext noch in toten Tupeln: `VACUUM FULL`
 * im Wartungsfenster (README).
 */
#[AsCommand(
    name: 'app:purge-plaintext',
    description: 'Loescht nach Ablauf der Frist den Klartext nicht umgestellter Konten',
)]
final class PurgePlaintextCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SyncLock $lock,
        private readonly EncryptionPolicy $encryption,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Nur zaehlen, nichts loeschen')]
        bool $dryRun = false,
    ): int {
        $deadline = $this->encryption->deadline();
        if (null === $deadline) {
            $io->note('Keine Frist gesetzt (SPISKI_ENCRYPTION_DEADLINE leer); nichts zu tun.');

            return Command::SUCCESS;
        }
        if (!$this->encryption->deadlinePassed()) {
            $io->note(\sprintf('Frist laeuft erst am %s ab; nichts zu tun.', $deadline->format(\DATE_ATOM)));

            return Command::SUCCESS;
        }

        $connection = $this->em->getConnection();
        $counts = $connection->transactional(function (Connection $connection) use ($dryRun): array {
            // Wie Beitritt und `begin`: kein Sync und kein Teilen dazwischen.
            $this->lock->exclusive($connection);

            $counts = $this->count($connection);
            if (!$dryRun && ($counts['lists'] + $counts['items']) > 0) {
                $this->purge($connection);
            }

            return $counts;
        });

        $io->table(['Konten', 'Listen', 'Artikel'], [[$counts['accounts'], $counts['lists'], $counts['items']]]);
        $io->success(
            $dryRun
                ? 'Probelauf: nichts geloescht.'
                : 'Klartext geloescht. Danach im Wartungsfenster VACUUM FULL (README).',
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{accounts: int, lists: int, items: int}
     */
    private function count(Connection $connection): array
    {
        /** @var array{accounts: int|string, lists: int|string, items: int|string} $row */
        $row = $connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    (SELECT COUNT(DISTINCT user_id) FROM shopping_list WHERE user_id IN (SELECT id FROM app_user WHERE encryption_state = :state)) AS accounts,
                    (SELECT COUNT(*) FROM shopping_list WHERE user_id IN (SELECT id FROM app_user WHERE encryption_state = :state)) AS lists,
                    (SELECT COUNT(*) FROM shopping_item WHERE user_id IN (SELECT id FROM app_user WHERE encryption_state = :state)) AS items
                SQL,
            ['state' => EncryptionState::Plaintext->value],
        );

        return ['accounts' => (int) $row['accounts'], 'lists' => (int) $row['lists'], 'items' => (int) $row['items']];
    }

    private function purge(Connection $connection): void
    {
        $params = ['state' => EncryptionState::Plaintext->value];

        // Zuerst die Sicht aller Konten (auch der Mitglieder), sonst zeigte sie
        // auf geloeschte Zeilen. Artikel gehoeren immer dem Besitzer der Liste.
        $connection->executeStatement(<<<'SQL'
            DELETE FROM sync_view sv
            USING shopping_item i, app_user u
            WHERE sv.kind = 'item' AND sv.row_id = i.id AND i.user_id = u.id AND u.encryption_state = :state
            SQL, $params);
        $connection->executeStatement(<<<'SQL'
            DELETE FROM sync_view sv
            USING shopping_list l, app_user u
            WHERE sv.kind = 'list' AND sv.row_id = l.id AND l.user_id = u.id AND u.encryption_state = :state
            SQL, $params);
        $connection->executeStatement(
            'DELETE FROM shopping_item WHERE user_id IN (SELECT id FROM app_user WHERE encryption_state = :state)',
            $params,
        );
        // list_member faellt per ON DELETE CASCADE mit, share_code mit der Zeile.
        $connection->executeStatement(
            'DELETE FROM shopping_list WHERE user_id IN (SELECT id FROM app_user WHERE encryption_state = :state)',
            $params,
        );
    }
}
