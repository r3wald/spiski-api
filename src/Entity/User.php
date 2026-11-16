<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\DateTimeMsType;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Anker fuer die Zuordnung von Listen und Artikeln. Kein Login, keine
 * personenbezogenen Felder – die ID ist die client-generierte Account-UUID und
 * zugleich das einzige Secret (CLAUDE.md 6).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Monotoner Sync-Zaehler dieses Nutzers (CLAUDE.md 5.2). */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $versionCounter = 0;

    #[ORM\Column(type: DateTimeMsType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: DateTimeMsType::NAME)]
    private \DateTimeImmutable $lastSeenAt;

    /**
     * Stand der Ende-zu-Ende-Verschluesselung (CLAUDE.md 14). Geaendert wird er
     * nur ueber App\Encryption\EncryptionService, unter Sperre und per SQL; der
     * Wert hier ist der beim Laden des Kontos.
     */
    #[ORM\Column(length: 16, enumType: EncryptionState::class, options: ['default' => 'plaintext'])]
    private EncryptionState $encryptionState = EncryptionState::Plaintext;

    #[ORM\Column(type: DateTimeMsType::NAME, nullable: true)]
    private ?\DateTimeImmutable $encryptionChangedAt = null;

    /** Das Geraet (clientId), das die Migration fuehrt; nur in `migrating`. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $migrationClientId = null;

    /**
     * Pruefwert des Kontoschluessels: ein fester Text, vom Client verschluesselt.
     * Der Server kann ihn nicht pruefen, nur ausliefern; Geraete erkennen daran
     * einen falschen Konto-Code vor dem ersten Sync.
     */
    #[ORM\Column(length: 256, nullable: true)]
    private ?string $keyCheck = null;

    public function __construct(Uuid $id, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->createdAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVersionCounter(): int
    {
        return $this->versionCounter;
    }

    public function setVersionCounter(int $counter): void
    {
        $this->versionCounter = $counter;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): void
    {
        $this->lastSeenAt = $lastSeenAt;
    }

    public function getEncryptionState(): EncryptionState
    {
        return $this->encryptionState;
    }

    public function getEncryptionChangedAt(): ?\DateTimeImmutable
    {
        return $this->encryptionChangedAt;
    }

    public function getMigrationClientId(): ?Uuid
    {
        return $this->migrationClientId;
    }

    public function getKeyCheck(): ?string
    {
        return $this->keyCheck;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }
}
