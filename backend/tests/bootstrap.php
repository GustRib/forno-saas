<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Prepara o banco de testes (forno_test) com as migrations atuais.
// Cada teste roda dentro de uma transação revertida (DAMA DoctrineTestBundle).
if (!getenv('FORNO_SKIP_DB_SETUP')) {
    $console = escapeshellarg(dirname(__DIR__).'/bin/console');
    // cache:clear também zera o estado dos rate limiters entre execuções.
    foreach (['cache:clear', 'doctrine:database:create --if-not-exists', 'doctrine:migrations:migrate --allow-no-migration'] as $command) {
        passthru(sprintf('php %s %s --env=test --no-interaction --quiet', $console, $command), $exitCode);
        if (0 !== $exitCode) {
            fwrite(STDERR, "Falha ao preparar o banco de testes: {$command}\n");
            exit($exitCode);
        }
    }
}
