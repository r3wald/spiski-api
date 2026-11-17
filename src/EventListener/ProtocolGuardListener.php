<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Encryption\ClientProtocol;
use App\Encryption\EncryptionPolicy;
use App\Entity\EncryptionState;
use App\Entity\User;
use App\Exception\ApiProblemException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Weist alte Apps (Protokoll 1, ohne Header `X-Spiski-Protocol`) ab, sobald
 * ihr Konto verschluesselt ist oder umgestellt wird oder die Frist abgelaufen
 * ist (CLAUDE.md 14).
 *
 * Laeuft nach der Firewall (Prioritaet 8), damit das Konto bekannt ist. Das
 * ist nur der schnelle Weg am Eingang; verbindlich prueft der Sync noch einmal
 * unter der Kontosperre (SyncService::guardEncryption).
 *
 * Ausnahmen: `DELETE /v1/me` (Recht auf Loeschung), `GET /v1/health` und die
 * Admin-Schnittstelle.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class ProtocolGuardListener
{
    public function __construct(
        private readonly Security $security,
        private readonly EncryptionPolicy $encryption,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!self::isGuarded($request) || ClientProtocol::supportsEncryption($request)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (EncryptionState::Plaintext !== $user->getEncryptionState() || $this->encryption->deadlinePassed()) {
            throw ApiProblemException::upgradeRequired('Diese App-Version ist veraltet. Bitte aktualisieren.');
        }
    }

    private static function isGuarded(Request $request): bool
    {
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/v1/') || '/v1/health' === $path || str_starts_with($path, '/v1/admin')) {
            return false;
        }

        return !('/v1/me' === $path && $request->isMethod('DELETE'));
    }
}
