<?php

namespace Packstub\Holler\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void routes(string $prefix = 'calls', array|string|null $middleware = null, string $as = 'holler.', bool $window = true)
 * @method static string asset(string $file)
 * @method static \Packstub\Holler\Contracts\CallContext context()
 * @method static ?string currentUserId()
 * @method static ?\Illuminate\Contracts\Auth\Authenticatable findUser(string $id)
 * @method static array{id: string, name: string, avatar: ?string} who(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static array<string, array{id: string, name: string, avatar: ?string}> whoAre(array $userIds)
 * @method static array{iceServers: array<int, array<string, mixed>>, iceTransportPolicy: string} rtcConfiguration()
 * @method static array<int, array<string, mixed>> iceServers()
 * @method static array{username: string, credential: string} turnCredentials(string $secret, int $ttl = 3600, ?string $userId = null)
 * @method static int maxParticipants()
 * @method static \Packstub\Holler\Models\Call start(\Illuminate\Contracts\Auth\Authenticatable $caller, \Illuminate\Contracts\Auth\Authenticatable|\Packstub\Holler\Participant|string|array|null $callees, ?string $tab = null, string $kind = 'call', ?string $scope = null)
 * @method static \Packstub\Holler\Models\Call invite(\Packstub\Holler\Models\Call $call, string $byUserId, \Illuminate\Contracts\Auth\Authenticatable|string|null $callee)
 * @method static \Packstub\Holler\Models\Call answer(\Packstub\Holler\Models\Call $call, string $userId, ?string $tab = null)
 * @method static \Packstub\Holler\Models\Call decline(\Packstub\Holler\Models\Call $call, string $userId)
 * @method static \Packstub\Holler\Models\Call hangup(\Packstub\Holler\Models\Call $call, string $userId)
 * @method static void withdraw(\Packstub\Holler\Models\Call $call, ?\Packstub\Holler\Models\CallParticipant $participant, ?string $status = null)
 * @method static void end(\Packstub\Holler\Models\Call $call, string $reason)
 * @method static ?\Packstub\Holler\Models\CallSignal signal(\Packstub\Holler\Models\Call $call, string $fromUserId, string $type, array $payload, ?string $to = null)
 * @method static void expire(string $userId)
 * @method static void expireCalls(iterable $calls)
 * @method static array poll(string $me, int $after = 0, ?int $currentId = null, ?string $tab = null, bool $hold = false, ?string $kind = null)
 * @method static \Illuminate\Support\Collection openCallsOf(string $userId, ?string $scope = null)
 * @method static string scope()
 * @method static bool inScope(\Packstub\Holler\Models\Call $call, string $userId)
 * @method static array present(\Packstub\Holler\Models\Call $call, string $me, ?string $tab = null)
 *
 * @see \Packstub\Holler\Holler
 */
class Holler extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Packstub\Holler\Holler::class;
    }
}
