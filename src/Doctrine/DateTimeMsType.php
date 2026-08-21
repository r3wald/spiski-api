<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

/**
 * TIMESTAMPTZ mit Sub-Sekunden-Aufloesung.
 *
 * Der Doctrine-Standardtyp deklariert auf PostgreSQL `TIMESTAMP(0) WITH TIME ZONE`
 * und formatiert beim Schreiben ohne Bruchteile – beides schneidet Millisekunden ab.
 * Die Konfliktaufloesung vergleicht `updatedAt` aber millisekundengenau
 * (CLAUDE.md 5.3); ohne diesen Typ wuerde jeder Vergleich innerhalb derselben
 * Sekunde faelschlich als Gleichstand gelten und staendig den clientId-Tiebreaker
 * ausloesen.
 *
 * Der Typ ist bewusst PostgreSQL-spezifisch – das Projekt ist auf PostgreSQL
 * festgelegt (CLAUDE.md 2).
 */
final class DateTimeMsType extends DateTimeTzImmutableType
{
    public const NAME = 'datetime_ms';

    private const FORMAT = 'Y-m-d H:i:s.uP';

    /**
     * @param array<string, mixed> $column
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMP(6) WITH TIME ZONE';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value->format(self::FORMAT);
        }

        throw InvalidType::new($value, self::class, ['null', \DateTimeImmutable::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (!\is_string($value) || '' === $value) {
            throw InvalidType::new($value, self::class, ['null', 'string', \DateTimeImmutable::class]);
        }

        // Nicht createFromFormat(): PostgreSQL laesst nachlaufende Nullen der
        // Bruchteile weg und gibt bei glatten Sekunden gar keine aus, sodass ein
        // fester Formatstring je nach Wert mal passt und mal nicht.
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw InvalidFormat::new($value, self::class, self::FORMAT, $e);
        }
    }
}
