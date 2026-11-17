<?php

declare(strict_types=1);

namespace App\Sharing;

/**
 * Wer eine Liste sieht: der Besitzer und die Mitglieder (CLAUDE.md 12).
 * Kontos als RFC-4122-Strings.
 */
final class ListAudience
{
    /**
     * @param list<string> $members ohne den Besitzer
     */
    public function __construct(
        public readonly string $owner,
        public readonly array $members = [],
    ) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [$this->owner, ...$this->members];
    }

    public function includes(string $userId): bool
    {
        return $this->owner === $userId || \in_array($userId, $this->members, true);
    }

    /** Muss die Aenderung an diese Liste mehr als ein Konto erreichen? */
    public function isShared(): bool
    {
        return [] !== $this->members;
    }

    public function sameAs(self $other): bool
    {
        $mine = $this->all();
        $theirs = $other->all();
        sort($mine);
        sort($theirs);

        return $mine === $theirs;
    }

    public function without(string $userId): self
    {
        return new self(
            $this->owner,
            array_values(array_filter($this->members, static fn(string $m): bool => $m !== $userId)),
        );
    }
}
