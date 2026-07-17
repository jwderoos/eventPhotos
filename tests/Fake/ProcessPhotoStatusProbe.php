<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use Symfony\Component\Messenger\Stamp\StampInterface;
use App\Message\ProcessPhoto;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Test-only middleware that records the *committed* photos.status for every
 * ProcessPhoto at the instant it is dispatched onto the bus. A raw DBAL read
 * (bypassing the ORM identity map) sees only flushed data — exactly what a
 * separate worker process would read. If a controller dispatches before it has
 * flushed the status→Pending transition, the probe captures the stale prior
 * status, reproducing the dispatch-before-flush race that stranded photos in
 * Pending (worker consumes, find() returns non-Pending, handler no-ops).
 */
final class ProcessPhotoStatusProbe implements MiddlewareInterface
{
    /** @var array<int, string|false> photoId => photos.status read at dispatch time (false when no row) */
    public array $statusAtDispatch = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        // Only on dispatch (send), never on the receive leg.
        if ($message instanceof ProcessPhoto && !$envelope->last(ReceivedStamp::class) instanceof StampInterface) {
            $status = $this->connection->fetchOne(
                'SELECT status FROM photos WHERE id = ?',
                [$message->photoId],
            );
            $this->statusAtDispatch[$message->photoId] = is_string($status) ? $status : false;
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
