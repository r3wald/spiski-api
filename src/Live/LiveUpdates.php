<?php

declare(strict_types=1);

namespace App\Live;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Update;

/**
 * Live-Updates ueber Mercure (CLAUDE.md 13): Nach jeder Aenderung bekommt
 * jedes betroffene Konto ein "es gibt Neues" und synchronisiert dann selbst.
 * Der Inhalt der Aenderung geht nie ueber den Hub.
 *
 * Gesammelt wird waehrend der Transaktion (queue()), gesendet erst nach dem
 * Commit (send()) – sonst holt ein Client womoeglich ab, bevor die Daten
 * sichtbar sind. Faellt der Hub aus, merkt das nur der Live-Kanal: der Sync
 * selbst ist nie davon abhaengig, die Apps synchronisieren weiter periodisch.
 */
final class LiveUpdates
{
    /** Gueltigkeit eines Abo-Tokens; die App holt sich danach ein neues. */
    public const TOKEN_LIFETIME = 3600;

    /** @var array<string, ?string> Konto => clientId des ausloesenden Geraets */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%spiski.mercure.issuer%')]
        private readonly string $issuer,
        #[Autowire('%spiski.mercure.audience%')]
        private readonly string $audience,
    ) {
    }

    /**
     * Topic eines Kontos. Die Konto-ID ist das Secret und darf weder im Topic
     * noch im Hub-Log auftauchen, deshalb ein HMAC davon.
     */
    public function topicFor(string $userId): string
    {
        return 'urn:spiski:account:'.hash_hmac('sha256', strtolower($userId), $this->secret);
    }

    /** Abo-Token, das genau das Topic des Kontos lesen darf. */
    public function subscriberToken(string $userId, \DateTimeImmutable $now): string
    {
        $factory = $this->hub->getFactory() ?? throw new \LogicException('Mercure-Hub ohne Token-Factory.');

        return $factory->create(
            [new Grant([Grant::ACTION_SUBSCRIBE], [$this->topicFor($userId)])],
            [
                'iss' => $this->issuer,
                'aud' => $this->audience,
                'sub' => 'spiski-app',
                'client_id' => 'spiski-app',
                'exp' => $now->modify('+'.self::TOKEN_LIFETIME.' seconds'),
            ],
        );
    }

    /**
     * Konten vormerken, die eine Aenderung abholen sollen.
     *
     * @param list<string> $userIds
     * @param string|null  $originUserId   Konto, das die Aenderung ausgeloest hat
     * @param string|null  $originClientId Geraet, das sie ausgeloest hat: Es
     *                                     kennt sie schon und ignoriert die
     *                                     Meldung. Andere Konten erfahren es nie.
     */
    public function queue(array $userIds, ?string $originUserId = null, ?string $originClientId = null): void
    {
        foreach ($userIds as $userId) {
            $origin = $userId === $originUserId ? $originClientId : null;
            // Zwei Ausloeser im selben Request: dann ist keiner allein der Urheber.
            $this->pending[$userId] = \array_key_exists($userId, $this->pending) && $this->pending[$userId] !== $origin
                ? null
                : $origin;
        }
    }

    /** Nach dem Commit: alle vorgemerkten Konten benachrichtigen. */
    public function send(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $userId => $origin) {
            try {
                $this->hub->publish(new Update(
                    $this->topicFor($userId),
                    json_encode(['origin' => $origin], \JSON_THROW_ON_ERROR),
                    private: true,
                ));
            } catch (\Throwable $e) {
                // Ohne Konto-ID im Log (CLAUDE.md 6).
                $this->logger->warning('Mercure-Update fehlgeschlagen: {message}', ['message' => $e->getMessage()]);
            }
        }
    }

    /** Transaktion zurueckgerollt: nichts senden. */
    public function discard(): void
    {
        $this->pending = [];
    }

    /**
     * Fuehrt $work aus und sendet danach die vorgemerkten Updates; bei einer
     * Exception werden sie verworfen.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function around(callable $work): mixed
    {
        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->discard();

            throw $e;
        }
        $this->send();

        return $result;
    }
}
