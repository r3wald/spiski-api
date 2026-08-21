<?php

declare(strict_types=1);

namespace App\Sync;

use Symfony\Component\Uid\Uuid;

/**
 * Last-Write-Wins mit deterministischem Tiebreaker (CLAUDE.md 5.3).
 *
 * `(updatedAt, clientId)` bildet eine totale Ordnung. Dadurch haengt das
 * Ergebnis nicht von der Verarbeitungsreihenfolge ab und alle Geraete
 * konvergieren unabhaengig davon, wer wann synchronisiert.
 */
final class ConflictResolver
{
    public function decide(
        ?\DateTimeImmutable $serverUpdatedAt,
        ?Uuid $serverClientId,
        \DateTimeImmutable $incomingUpdatedAt,
        Uuid $incomingClientId,
    ): MergeDecision {
        if (null === $serverUpdatedAt || null === $serverClientId) {
            return MergeDecision::Insert;
        }

        // Der Vergleich von DateTimeImmutable beruecksichtigt Mikrosekunden und
        // rechnet Zeitzonen korrekt gegeneinander auf.
        $byTime = $incomingUpdatedAt <=> $serverUpdatedAt;

        if ($byTime > 0) {
            return MergeDecision::Accept;
        }

        if ($byTime < 0) {
            return MergeDecision::Reject;
        }

        $byClient = strcmp($incomingClientId->toRfc4122(), $serverClientId->toRfc4122());

        return match (true) {
            $byClient > 0 => MergeDecision::Accept,
            $byClient < 0 => MergeDecision::Reject,
            default => MergeDecision::NoOp,
        };
    }
}
