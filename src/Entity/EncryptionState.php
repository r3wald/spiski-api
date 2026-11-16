<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Wo ein Konto bei der Ende-zu-Ende-Verschluesselung steht (CLAUDE.md 14).
 *
 * plaintext -> migrating -> encrypted. Ein neues Konto ohne Zeilen springt mit
 * `commit` direkt von plaintext nach encrypted.
 */
enum EncryptionState: string
{
    /** Zeilen im Klartext; auch alte Apps (Protokoll 1) werden bedient. */
    case Plaintext = 'plaintext';

    /**
     * Ein Geraet stellt gerade um. Nur dieses Geraet darf synchronisieren, und
     * nur mit Protokoll 2; alle Teilen-Beziehungen sind bereits geloest.
     */
    case Migrating = 'migrating';

    /** Alle Zeilen verschluesselt; nur noch Protokoll 2. */
    case Encrypted = 'encrypted';

    /** Erwartet der Server von diesem Konto verschluesselte Zeilen? */
    public function expectsEncryptedRows(): bool
    {
        return self::Plaintext !== $this;
    }
}
