<?php

// The Phame game server. nginx serves it under /phame and strips that prefix, so paths here start at /.
require __DIR__ . '/vendor/autoload.php';

use PhameServer\RocksGame;
use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\Swerve;
use Swerve\WebSocket;

return new RequestHandler(function (ClientRequest $request) {
    $path = parse_url($request->getTarget(), PHP_URL_PATH) ?: '/';
    if ($path === '/ws') {
        // one tab: its socket coroutine relays between the browser and the game, through publish/subscribe
        WebSocket::from($request, function (WebSocket $ws) {
            $epoch = null;   // the game's start, from the snapshot: any worker can tell the game's time
            $client = bin2hex(random_bytes(6));
            $ws->onMessage->listen(function (string $data) use ($ws, &$epoch, $client) {   // first, so that no early ping is missed
                $message = json_decode($data, true);
                if (($message['type'] ?? '') === 'ping' && $epoch !== null) {
                    $ws->send(json_encode(['type' => 'pong', 'c' => $message['c'], 's' => microtime(true) - $epoch, 'w' => getmypid()]));
                } elseif (($message['type'] ?? '') === 'drop') {
                    Swerve::publishRaw('phame:rocks:in', json_encode(['type' => 'drop', 'client' => $client]));
                } elseif (($message['type'] ?? '') === 'camera') {   // input: to the game, wherever it runs
                    Swerve::publishRaw('phame:rocks:in', json_encode(['type' => 'camera', 'client' => $client, 'p' => $message['p'], 'q' => $message['q']]));
                }
            });
            RocksGame::ensureRunning();
            $mine = Swerve::subscribe("phame:rocks:client:$client", heartbeat: 1.0);   // subscribe first: no history is kept
            $state = Swerve::subscribe('phame:rocks:out', heartbeat: 2.0);
            Swerve::publishRaw('phame:rocks:in', json_encode(['type' => 'join', 'client' => $client]));
            try {
                foreach ($mine as $snapshot) {
                    if ($snapshot === null) {   // a second without an answer: the game may be starting, or moving to another worker
                        Swerve::publishRaw('phame:rocks:in', json_encode(['type' => 'join', 'client' => $client]));
                        continue;
                    }
                    $part = json_decode($snapshot, true);
                    $epoch = $part['epoch'];
                    $ws->send($snapshot);
                    if ($part['part'] === $part['parts'] - 1) {
                        break;   // the whole snapshot is through
                    }
                }
                $spoke = microtime(true);
                foreach ($state as $delta) {
                    if (microtime(true) - $spoke >= 2.0) {   // still here: the game drops viewers it stops hearing from
                        Swerve::publishRaw('phame:rocks:in', json_encode(['type' => 'alive', 'client' => $client]));
                        $spoke = microtime(true);
                    }
                    if ($delta === null) {   // a heartbeat: two quiet seconds
                        continue;
                    }
                    if ($delta[0] === '{') {   // JSON (an add, a remove), or binary (a batch of descriptions)
                        $ws->send($delta);
                    } else {
                        $ws->sendBinary($delta);
                    }
                }
            } finally {   // the tab is gone (swerve cancels this when the client leaves): its camera leaves the world
                // shielded: a cancelled coroutine's waits throw at once, and publishing may have to wait
                \phasync::shielded(fn () => Swerve::publishRaw('phame:rocks:in', json_encode(['type' => 'leave', 'client' => $client])));
            }
        });
        return;
    }
    $request->sendResponseHeaders(404, ['content-type' => 'text/plain']);
    $request->write("Not found\n");
});
