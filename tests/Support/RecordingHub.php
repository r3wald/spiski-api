<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Ersetzt in Tests den Mercure-Hub: merkt sich die Updates, statt sie zu
 * senden. Token erzeugt weiter der echte Hub.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    public static array $updates = [];

    public function __construct(private readonly HubInterface $inner)
    {
    }

    public function getUrl(): string
    {
        return $this->inner->getUrl();
    }

    public function getPublicUrl(): string
    {
        return $this->inner->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->inner->getFactory();
    }

    public function publish(Update $update): string
    {
        self::$updates[] = $update;

        return 'urn:uuid:test';
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->inner->getProtocolVersion();
    }

    public function getCookieName(): string
    {
        return $this->inner->getCookieName();
    }
}
