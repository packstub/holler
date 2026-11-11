<?php

namespace Packstub\Holler;

use Closure;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Packstub\Holler\Contracts\CallContext;

/**
 * The context of a plain Laravel app: the config file for what fits in config,
 * closures registered here (from a service provider's boot method) for the rest.
 *
 *     app(AppContext::class)
 *         ->canCallUsing(fn (User $caller, User $callee): bool => $caller->team_id === $callee->team_id)
 *         ->userAvatarUsing(fn (User $user): ?string => $user->avatar_url);
 */
class AppContext implements CallContext
{
    protected ?Closure $canCallUsing = null;

    protected ?Closure $userNameUsing = null;

    protected ?Closure $userAvatarUsing = null;

    /** @var array<int, array<string, mixed>> | Closure | null */
    protected array|Closure|null $iceServers = null;

    protected bool|Closure|null $video = null;

    protected int|Closure|null $maxParticipants = null;

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * Who may call whom: fn (Authenticatable $caller, Authenticatable $callee): bool.
     * Defaults to any two different users of the guard.
     */
    public function canCallUsing(?Closure $callback): static
    {
        $this->canCallUsing = $callback;

        return $this;
    }

    /** How a user is named to the other side: fn (Authenticatable $user): string. Defaults to `name`, then `email`. */
    public function userNameUsing(?Closure $callback): static
    {
        $this->userNameUsing = $callback;

        return $this;
    }

    /** The avatar URL shown to the other side: fn (Authenticatable $user): ?string. Defaults to none. */
    public function userAvatarUsing(?Closure $callback): static
    {
        $this->userAvatarUsing = $callback;

        return $this;
    }

    /**
     * The ICE servers, for example with short-lived TURN credentials. Overrides
     * the `ice_servers` config key.
     *
     * @param  array<int, array<string, mixed>> | Closure | null  $servers
     */
    public function iceServersUsing(array|Closure|null $servers): static
    {
        $this->iceServers = $servers;

        return $this;
    }

    /** Start calls with the camera on, or as voice calls. Overrides the `video` config key. */
    public function video(bool|Closure|null $condition = true): static
    {
        $this->video = $condition;

        return $this;
    }

    /** People on one call, the caller included. Overrides the `max_participants` config key. */
    public function maxParticipantsUsing(int|Closure|null $max): static
    {
        $this->maxParticipants = $max;

        return $this;
    }

    // ------------------------------------------------------------------
    // CallContext
    // ------------------------------------------------------------------

    public function guard(): Guard
    {
        return auth()->guard(config('holler.guard'));
    }

    public function scope(): string
    {
        return (string) (config('holler.guard') ?? config('auth.defaults.guard'));
    }

    public function currentUserId(): ?string
    {
        $id = $this->guard()->id();

        return $id === null ? null : (string) $id;
    }

    public function findUser(string $id): ?Authenticatable
    {
        $provider = $this->guard()->getProvider();

        if (! static::couldBeKey($provider, $id)) {
            return null;
        }

        $user = $provider?->retrieveById($id);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Several people at once (one query on an Eloquent provider), for the roster of a call.
     *
     * @param  array<int, string>  $ids
     * @return array<int, Authenticatable>
     */
    public function findUsers(array $ids): array
    {
        return static::retrieveMany($this->guard()->getProvider(), $ids);
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, Authenticatable>
     */
    public static function retrieveMany(?UserProvider $provider, array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), fn (string $id) => static::couldBeKey($provider, $id)));

        if ($ids === []) {
            return [];
        }

        if ($provider instanceof EloquentUserProvider) {
            return $provider->createModel()->newQuery()->whereKey($ids)->get()->all();
        }

        return array_values(array_filter(
            array_map(fn (string $id) => $provider?->retrieveById($id), $ids),
            fn ($user) => $user instanceof Authenticatable,
        ));
    }

    /**
     * Whether an id can be a key of the provider's model: an integer key takes digits only
     * (Postgres refuses to compare anything else and the request would fail).
     */
    public static function couldBeKey(?UserProvider $provider, string $id): bool
    {
        if (! $provider instanceof EloquentUserProvider) {
            return true;
        }

        $model = $provider->createModel();

        return ! in_array($model->getKeyType(), ['int', 'integer'], true) || ctype_digit($id);
    }

    public function who(Authenticatable $user): array
    {
        $name = $this->userNameUsing
            ? ($this->userNameUsing)($user)
            : (filled($user->name ?? null) ? $user->name : ($user->email ?? null));

        $avatar = $this->userAvatarUsing ? ($this->userAvatarUsing)($user) : null;

        return [
            'id' => (string) $user->getAuthIdentifier(),
            'name' => filled($name) ? (string) $name : __('holler::messages.someone'),
            'avatar' => filled($avatar) ? (string) $avatar : null,
        ];
    }

    public function canCall(Authenticatable $caller, Authenticatable $callee): bool
    {
        return $this->canCallUsing === null || (bool) ($this->canCallUsing)($caller, $callee);
    }

    public function iceServers(): array
    {
        $servers = $this->iceServers instanceof Closure ? ($this->iceServers)() : $this->iceServers;

        return $servers ?? config('holler.ice_servers', []);
    }

    public function hasVideo(): bool
    {
        $video = $this->video instanceof Closure ? ($this->video)() : $this->video;

        return (bool) ($video ?? config('holler.video', true));
    }

    public function maxParticipants(): int
    {
        $max = $this->maxParticipants instanceof Closure ? ($this->maxParticipants)() : $this->maxParticipants;

        return (int) ($max ?? config('holler.max_participants', 4));
    }
}
