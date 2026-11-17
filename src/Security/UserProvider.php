<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reiner Lese-Provider.
 *
 * Das Anlegen beim ersten Kontakt passiert bewusst nicht hier, sondern im
 * UuidBearerAuthenticator: nur dort ist der Request verfuegbar, und ohne ihn
 * liesse sich das IP-Limit fuer neue Accounts nicht anwenden.
 *
 * @implements UserProviderInterface<User>
 */
final class UserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if (!Uuid::isValid($identifier)) {
            throw new UserNotFoundException('Kein gueltiges UUID-Format.');
        }

        $user = $this->users->find(Uuid::fromString($identifier));
        if (!$user instanceof User) {
            throw new UserNotFoundException('Unbekannter Account.');
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Nicht unterstuetzte Nutzerklasse "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class;
    }
}
