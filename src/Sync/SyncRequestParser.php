<?php

declare(strict_types=1);

namespace App\Sync;

use App\Encryption\CipherFormat;
use App\Exception\ApiProblemException;
use App\Sync\Dto\ItemChange;
use App\Sync\Dto\ListChange;
use App\Sync\Dto\PushResult;
use App\Sync\Dto\RejectReason;
use App\Sync\Dto\SyncRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Parst und validiert den Body von POST /v1/sync.
 *
 * Zwei Fehlerklassen, bewusst unterschiedlich behandelt (CLAUDE.md 7):
 *  - Request-Ebene (kaputtes JSON, fehlende Pflichtfelder, Groessengrenzen)
 *    fuehrt zu einer ApiProblemException und damit zu 4xx.
 *  - Zeilen-Ebene (verletzte Feld-Constraints) fuehrt zu einem Reject mit Grund
 *    `invalid`. Ein einzelner defekter Datensatz darf den Sync eines Geraets
 *    nicht dauerhaft blockieren.
 *
 * Ausnahme: eine fehlende oder unlesbare `id` ist ein Request-Fehler. Ohne ID
 * gaebe es nichts, worauf der Client ein Zeilen-Ergebnis beziehen koennte.
 */
final class SyncRequestParser
{
    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})$/';

    public function __construct(
        private readonly ClockSkewGuard $clockSkewGuard,
    ) {}

    public function parse(string $body, \DateTimeImmutable $now): SyncRequest
    {
        if (\strlen($body) > SyncLimits::MAX_BODY_BYTES) {
            throw ApiProblemException::payloadTooLarge(\sprintf(
                'Request-Body ueberschreitet %d Bytes.',
                SyncLimits::MAX_BODY_BYTES,
            ));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiProblemException::badRequest('Request-Body ist kein gueltiges JSON: ' . $e->getMessage());
        }

        if (!\is_array($decoded)) {
            throw ApiProblemException::badRequest('Request-Body muss ein JSON-Objekt sein.');
        }

        $since = $this->readRequiredInt($decoded, 'since');
        if ($since < 0) {
            throw ApiProblemException::unprocessable('"since" darf nicht negativ sein.');
        }

        $clientId = $this->readRequiredUuid($decoded, 'clientId');
        $limit = $this->readLimit($decoded);

        $changes = $decoded['changes'] ?? [];
        if (!\is_array($changes)) {
            throw ApiProblemException::badRequest('"changes" muss ein Objekt sein.');
        }

        $rawLists = $this->readRowArray($changes, 'lists');
        $rawItems = $this->readRowArray($changes, 'items');

        $rowCount = \count($rawLists) + \count($rawItems);
        if ($rowCount > SyncLimits::MAX_PUSH_ROWS) {
            throw ApiProblemException::payloadTooLarge(\sprintf(
                'Push enthaelt %d Zeilen, erlaubt sind hoechstens %d.',
                $rowCount,
                SyncLimits::MAX_PUSH_ROWS,
            ));
        }

        $lists = [];
        $invalidLists = [];
        $pushedListIds = [];
        foreach ($rawLists as $index => $row) {
            $id = $this->readRowId($row, 'changes.lists', $index);
            $pushedListIds[] = $id;

            $change = $this->parseListRow($id, $row, $now);
            if (null === $change) {
                $invalidLists[] = PushResult::rejected($id, RejectReason::Invalid);
                continue;
            }

            $lists[] = $change;
        }

        $items = [];
        $invalidItems = [];
        $pushedItemIds = [];
        foreach ($rawItems as $index => $row) {
            $id = $this->readRowId($row, 'changes.items', $index);
            $pushedItemIds[] = $id;

            $change = $this->parseItemRow($id, $row, $now);
            if (null === $change) {
                $invalidItems[] = PushResult::rejected($id, RejectReason::Invalid);
                continue;
            }

            $items[] = $change;
        }

        return new SyncRequest(
            $since,
            $clientId,
            $limit,
            $lists,
            $items,
            $invalidLists,
            $invalidItems,
            $pushedListIds,
            $pushedItemIds,
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function parseListRow(Uuid $id, array $row, \DateTimeImmutable $now): ?ListChange
    {
        $color = $this->optionalInt($row, 'color');
        $sortOrder = $this->optionalInt($row, 'sortOrder');
        $createdAt = $this->optionalTimestamp($row, 'createdAt');
        $updatedAt = $this->optionalTimestamp($row, 'updatedAt');

        if (null === $color || null === $sortOrder || null === $createdAt || null === $updatedAt) {
            return null;
        }

        if (!$this->isUint32($color) || !$this->isInt32($sortOrder)) {
            return null;
        }

        if (!$this->isNullableTimestampValid($row, 'deletedAt')) {
            return null;
        }

        // Verschluesselte Zeile (CLAUDE.md 14): der Name steckt in `enc`, dazu
        // der verpackte Listenschluessel. Klartext daneben waere ein Leck.
        $enc = $row['enc'] ?? null;
        $wrappedKey = $row['wrappedKey'] ?? null;
        if (null !== $enc) {
            if (!CipherFormat::isEnc($enc) || !CipherFormat::isShort($wrappedKey) || !$this->isAbsent($row, 'name')) {
                return null;
            }
            \assert(\is_string($enc) && \is_string($wrappedKey), 'Von CipherFormat::isEnc()/isShort() oben geprueft.');
            $name = null;
        } else {
            if (null !== $wrappedKey) {
                return null;
            }
            $name = $this->optionalString($row, 'name');
            if (null === $name) {
                return null;
            }
            $nameLength = mb_strlen($name);
            if ($nameLength < 1 || $nameLength > SyncLimits::MAX_LIST_NAME_LENGTH) {
                return null;
            }
            $wrappedKey = null;
        }

        return new ListChange(
            $id,
            $name,
            $color,
            $sortOrder,
            $createdAt,
            $this->clockSkewGuard->clamp($updatedAt, $now),
            $this->optionalTimestamp($row, 'deletedAt'),
            $enc,
            $wrappedKey,
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function parseItemRow(Uuid $id, array $row, \DateTimeImmutable $now): ?ItemChange
    {
        $listIdRaw = $row['listId'] ?? null;
        if (!\is_string($listIdRaw) || !Uuid::isValid($listIdRaw)) {
            return null;
        }

        $sortOrder = $this->optionalInt($row, 'sortOrder');
        $createdAt = $this->optionalTimestamp($row, 'createdAt');
        $updatedAt = $this->optionalTimestamp($row, 'updatedAt');

        if (null === $sortOrder || null === $createdAt || null === $updatedAt) {
            return null;
        }

        if (!$this->isInt32($sortOrder)) {
            return null;
        }

        $isChecked = $row['isChecked'] ?? false;
        if (!\is_bool($isChecked)) {
            return null;
        }

        if (!$this->isNullableTimestampValid($row, 'deletedAt') || !$this->isNullableTimestampValid($row, 'movedAt')) {
            return null;
        }

        // Verschluesselte Zeile (CLAUDE.md 14): Name, Menge, Einheit und Notiz
        // stecken in `enc`. Der Server kann sie nicht pruefen, nur das Format.
        $enc = $row['enc'] ?? null;
        if (null !== $enc) {
            if (!CipherFormat::isEnc($enc)) {
                return null;
            }
            foreach (['name', 'quantity', 'unit', 'note'] as $plain) {
                if (!$this->isAbsent($row, $plain)) {
                    return null;
                }
            }
            \assert(\is_string($enc), 'Von CipherFormat::isEnc() oben geprueft.');
            $name = null;
            $quantity = null;
            $unit = null;
            $note = null;
        } else {
            $name = $this->optionalString($row, 'name');
            if (null === $name) {
                return null;
            }
            $nameLength = mb_strlen($name);
            if ($nameLength < 1 || $nameLength > SyncLimits::MAX_ITEM_NAME_LENGTH) {
                return null;
            }

            $quantity = $row['quantity'] ?? null;
            if (null !== $quantity) {
                if (!\is_int($quantity) && !\is_float($quantity)) {
                    return null;
                }
                $quantity = (float) $quantity;
                if ($quantity < 0 || !is_finite($quantity)) {
                    return null;
                }
            }

            $unit = $this->nullableBoundedString($row, 'unit', SyncLimits::MAX_UNIT_LENGTH);
            if (false === $unit) {
                return null;
            }

            $note = $this->nullableBoundedString($row, 'note', SyncLimits::MAX_NOTE_LENGTH);
            if (false === $note) {
                return null;
            }
        }

        return new ItemChange(
            $id,
            Uuid::fromString($listIdRaw),
            $name,
            $quantity,
            $unit,
            $isChecked,
            $note,
            $sortOrder,
            $createdAt,
            $this->clockSkewGuard->clamp($updatedAt, $now),
            $this->optionalTimestamp($row, 'deletedAt'),
            $this->optionalTimestamp($row, 'movedAt'),
            $enc,
        );
    }

    /**
     * Feld fehlt oder ist `null`. Bei verschluesselten Zeilen duerfen die
     * Klartext-Felder nicht gesetzt sein.
     *
     * @param array<mixed> $row
     */
    private function isAbsent(array $row, string $key): bool
    {
        return null === ($row[$key] ?? null);
    }

    /**
     * @param array<mixed> $data
     */
    private function readRequiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw ApiProblemException::badRequest(\sprintf('"%s" fehlt oder ist keine ganze Zahl.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function readRequiredUuid(array $data, string $key): Uuid
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || !Uuid::isValid($value)) {
            throw ApiProblemException::badRequest(\sprintf('"%s" fehlt oder ist keine gueltige UUID.', $key));
        }

        return Uuid::fromString($value);
    }

    /**
     * @param array<mixed> $data
     */
    private function readLimit(array $data): int
    {
        $value = $data['limit'] ?? null;
        if (null === $value) {
            return SyncLimits::DEFAULT_PULL_LIMIT;
        }

        if (!\is_int($value)) {
            throw ApiProblemException::badRequest('"limit" muss eine ganze Zahl sein.');
        }

        if ($value < 1 || $value > SyncLimits::MAX_PULL_LIMIT) {
            throw ApiProblemException::unprocessable(\sprintf(
                '"limit" muss zwischen 1 und %d liegen.',
                SyncLimits::MAX_PULL_LIMIT,
            ));
        }

        return $value;
    }

    /**
     * @param array<mixed> $changes
     *
     * @return list<array<mixed>>
     */
    private function readRowArray(array $changes, string $key): array
    {
        $value = $changes[$key] ?? [];
        if (!\is_array($value) || !array_is_list($value)) {
            throw ApiProblemException::badRequest(\sprintf('"changes.%s" muss ein Array sein.', $key));
        }

        $rows = [];
        foreach ($value as $index => $row) {
            if (!\is_array($row)) {
                throw ApiProblemException::badRequest(\sprintf('"changes.%s[%d]" muss ein Objekt sein.', $key, $index));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<mixed> $row
     */
    private function readRowId(array $row, string $path, int $index): Uuid
    {
        $value = $row['id'] ?? null;
        if (!\is_string($value) || !Uuid::isValid($value)) {
            throw ApiProblemException::badRequest(\sprintf(
                '"%s[%d].id" fehlt oder ist keine gueltige UUID.',
                $path,
                $index,
            ));
        }

        return Uuid::fromString($value);
    }

    /**
     * @param array<mixed> $row
     */
    private function optionalString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<mixed> $row
     */
    private function optionalInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * @param array<mixed> $row
     */
    private function optionalTimestamp(array $row, string $key): ?\DateTimeImmutable
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value) || 1 !== preg_match(self::TIMESTAMP_PATTERN, $value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Unterscheidet "Feld fehlt bzw. ist null" (erlaubt) von "Feld ist gesetzt,
     * aber unlesbar" (Zeile ungueltig).
     *
     * @param array<mixed> $row
     */
    private function isNullableTimestampValid(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (null === $value) {
            return true;
        }

        return null !== $this->optionalTimestamp($row, $key);
    }

    /**
     * @param array<mixed> $row
     *
     * @return string|null|false false = gesetzt, aber ungueltig
     */
    private function nullableBoundedString(array $row, string $key, int $maxLength): string|false|null
    {
        $value = $row[$key] ?? null;
        if (null === $value) {
            return null;
        }

        if (!\is_string($value) || mb_strlen($value) > $maxLength) {
            return false;
        }

        return $value;
    }

    private function isInt32(int $value): bool
    {
        return $value >= SyncLimits::INT32_MIN && $value <= SyncLimits::INT32_MAX;
    }

    private function isUint32(int $value): bool
    {
        return $value >= SyncLimits::UINT32_MIN && $value <= SyncLimits::UINT32_MAX;
    }
}
