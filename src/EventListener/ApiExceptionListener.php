<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiProblemException;
use App\Http\ProblemResponseFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Uebersetzt Ausnahmen unterhalb von /v1, /admin und /status.* in Problem Details (CLAUDE.md 7).
 *
 * Prioritaet 64 laeuft vor dem ErrorListener des HttpKernel (-64), damit dort
 * gar nicht erst die HTML- bzw. Standard-Fehlerdarstellung greift.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
final class ApiExceptionListener
{
    private const TYPE_BASE = 'https://spiski.app/problems/';

    public function __construct(
        private readonly ProblemResponseFactory $problems,
        private readonly bool $debug,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (
            !str_starts_with($path, '/v1')
            && !str_starts_with($path, '/admin')
            && !str_starts_with($path, '/status.')
        ) {
            return;
        }

        $throwable = $event->getThrowable();

        if ($throwable instanceof ApiProblemException) {
            $event->setResponse($this->problems->fromException($throwable));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();

            $headers = [];
            foreach ($throwable->getHeaders() as $name => $value) {
                if (\is_string($value)) {
                    $headers[(string) $name] = $value;
                }
            }

            $event->setResponse($this->problems->create(
                $status,
                self::TYPE_BASE . 'http-error',
                $this->titleFor($status),
                $throwable->getMessage(),
                $headers,
            ));

            return;
        }

        // Unerwarteter Fehler: nach aussen ohne Details, damit nichts ueber
        // interne Struktur oder Daten durchsickert. Im Debug-Modus ist die
        // Ursache dagegen sichtbar, sonst ist das API-Backend kaum zu debuggen.
        $event->setResponse($this->problems->create(
            500,
            self::TYPE_BASE . 'internal-error',
            'Internal Server Error',
            $this->debug ? \sprintf('%s: %s', $throwable::class, $throwable->getMessage()) : 'Unerwarteter Fehler.',
        ));
    }

    private function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            413 => 'Payload Too Large',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            default => 'Error',
        };
    }
}
