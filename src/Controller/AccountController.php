<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Sharing\ShareService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AccountController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShareService $shares,
    ) {
    }

    /**
     * Recht auf Loeschung (CLAUDE.md 6).
     *
     * Loescht die Nutzerzeile hart; Listen und Artikel haengen per
     * ON DELETE CASCADE daran. Der naechste Request derselben UUID legt den
     * Nutzer mit versionCounter = 0 neu an – der Client muss seinen
     * lastSyncCursor deshalb gleichzeitig lokal auf 0 zuruecksetzen.
     */
    #[Route('/v1/me', name: 'v1_me_delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Alle Daten des Nutzers loeschen',
        description: <<<'TEXT'
            Loescht Nutzer, Listen und Artikel unwiderruflich (Recht auf
            Loeschung). Mit anderen geteilte Listen verschwinden auch bei deren
            Mitgliedern; aus fremden geteilten Listen tritt das Konto aus. Derselbe Bearer-Token legt beim naechsten Request einen
            leeren Account an; der Client muss seinen `lastSyncCursor`
            gleichzeitig lokal auf 0 zuruecksetzen.
            TEXT,
        tags: ['Account'],
    )]
    #[OA\Response(response: 204, description: 'Geloescht')]
    #[OA\Response(response: 401, description: 'Bearer-Token fehlt oder ist keine UUID', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function delete(#[CurrentUser] User $user): Response
    {
        $this->em->getConnection()->transactional(function (Connection $connection) use ($user): void {
            // Mitglieder seiner geteilten Listen verlieren den Zugriff, aus
            // fremden Listen tritt das Konto aus (CLAUDE.md 12).
            $this->shares->detachAccount($connection, $user);
            $connection->executeStatement(
                'DELETE FROM app_user WHERE id = :id',
                ['id' => $user->getId()->toRfc4122()],
            );
        });

        // Der geloeschte Nutzer haengt noch im Identity Map des EntityManagers.
        $this->em->clear();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
