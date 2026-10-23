<?php

namespace Packstub\Calls\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void routes(string $prefix = 'calls', array|string $middleware = 'auth', string $as = 'calls.')
 * @method static \Packstub\Calls\Contracts\CallContext context()
 * @method static ?string currentUserId()
 * @method static ?\Illuminate\Contracts\Auth\Authenticatable findUser(string $id)
 * @method static array{id: string, name: string, avatar: ?string} who(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Packstub\Calls\Models\Call start(\Illuminate\Contracts\Auth\Authenticatable $caller, \Illuminate\Contracts\Auth\Authenticatable|string|null $callee, ?string $tab = null)
 * @method static \Packstub\Calls\Models\Call answer(\Packstub\Calls\Models\Call $call, string $userId, ?string $tab = null)
 * @method static \Packstub\Calls\Models\Call decline(\Packstub\Calls\Models\Call $call, string $userId)
 * @method static \Packstub\Calls\Models\Call hangup(\Packstub\Calls\Models\Call $call, string $userId)
 * @method static void end(\Packstub\Calls\Models\Call $call, string $reason)
 * @method static \Packstub\Calls\Models\CallSignal signal(\Packstub\Calls\Models\Call $call, string $fromUserId, string $type, array $payload)
 * @method static void expire(string $userId)
 * @method static array poll(string $me, int $after = 0, ?int $currentId = null, ?string $tab = null, bool $hold = false)
 * @method static \Illuminate\Support\Collection openCallsOf(string $userId)
 * @method static array present(\Packstub\Calls\Models\Call $call, string $me, ?string $tab = null)
 *
 * @see \Packstub\Calls\Calls
 */
class Calls extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Packstub\Calls\Calls::class;
    }
}
