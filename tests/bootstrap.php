<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Schema einmal pro Testlauf ueber die echten Migrations aufbauen – damit sind
// die Migrations selbst mitgetestet und nicht nur das Entity-Mapping
// (CLAUDE.md 9). Die Testdatenbank liegt in tmpfs und ist nach jedem Neustart
// des Containers ohnehin leer.
$console = dirname(__DIR__).'/bin/console';
$commands = [
    'doctrine:database:create --if-not-exists --no-interaction',
    'doctrine:migrations:migrate --no-interaction --allow-no-migration',
];

foreach ($commands as $command) {
    $line = \sprintf('APP_ENV=test php %s %s 2>&1', escapeshellarg($console), $command);
    exec($line, $output, $exitCode);

    if (0 !== $exitCode) {
        fwrite(\STDERR, \sprintf("Test-Setup fehlgeschlagen bei \"%s\":\n%s\n", $command, implode("\n", $output)));
        exit(1);
    }
}
