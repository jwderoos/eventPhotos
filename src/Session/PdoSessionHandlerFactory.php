<?php

declare(strict_types=1);

namespace App\Session;

use Doctrine\DBAL\Connection;
use PDO;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final readonly class PdoSessionHandlerFactory
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(): PdoSessionHandler
    {
        $pdo = $this->connection->getNativeConnection();

        if (!$pdo instanceof PDO) {
            throw new RuntimeException(
                'Expected the Doctrine connection to expose a PDO native connection; got '
                . (get_debug_type($pdo))
            );
        }

        // LOCK_ADVISORY uses Postgres pg_try_advisory_lock instead of opening a
        // transaction on the shared Doctrine connection — TRANSACTIONAL would
        // BEGIN at session read and then collide with Doctrine's own
        // beginTransaction() at flush() ("active transaction already").
        return new PdoSessionHandler($pdo, [
            'db_table'        => 'sessions',
            'db_id_col'       => 'sess_id',
            'db_data_col'     => 'sess_data',
            'db_lifetime_col' => 'sess_lifetime',
            'db_time_col'     => 'sess_time',
            'lock_mode'       => PdoSessionHandler::LOCK_ADVISORY,
        ]);
    }
}
