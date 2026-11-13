<?php

namespace Packstub\Holler;

use Closure;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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

    protected ?Closure $searchUsersUsing = null;

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
     * Who the widget's "Add someone" finds: fn (Authenticatable $caller, string $query, int $limit): iterable
     * of users. Defaults to the guard's Eloquent model searched by the `search_columns` config key.
     */
    public function searchUsersUsing(?Closure $callback): static
    {
        $this->searchUsersUsing = $callback;

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

    public function searchUsers(Authenticatable $caller, string $query, int $limit): iterable
    {
        if ($this->searchUsersUsing) {
            return ($this->searchUsersUsing)($caller, $query, $limit) ?? [];
        }

        return static::searchProvider($this->guard()->getProvider(), $query, $limit, (array) config('holler.search_columns', ['name']));
    }

    /**
     * The provider's users with the words in any of these columns, whatever the case, ordered
     * by the first one; nobody from a provider that is not Eloquent, or without columns.
     * A column the users table lacks is left out, with a warning in the log, rather than
     * failing every search. Read one by one as the caller walks through them, never more
     * than `$limit`: the search stops at the first few people it may show, however many it
     * has to pass over.
     *
     * @param  array<int, string>  $columns
     * @return iterable<int, Authenticatable>
     */
    public static function searchProvider(?UserProvider $provider, string $query, int $limit, array $columns): iterable
    {
        $columns = array_values(array_filter($columns, fn ($column): bool => is_string($column) && $column !== ''));

        if (! $provider instanceof EloquentUserProvider || $columns === []) {
            return [];
        }

        $model = $provider->createModel();
        $columns = static::existingColumns($model, $columns);

        if ($columns === []) {
            return [];
        }

        // Typed by a person: `%` and `\` mean nothing to them, and would to LIKE.
        $words = str_replace(['%', '\\'], '', $query);

        return $model->newQuery()
            ->when($words !== '', fn ($users) => $users->where(function ($users) use ($columns, $words): void {
                foreach ($columns as $column) {
                    $users->orWhereLike($column, '%'.$words.'%');
                }
            }))
            ->orderBy($columns[0])
            ->limit(max(1, $limit))
            ->cursor();
    }

    /**
     * The columns the model's table has, of these. Qualified names (`users.name`) and JSON paths
     * (`profile->name`) are taken on trust; when the table cannot be read, every column is.
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    protected static function existingColumns(Model $model, array $columns): array
    {
        try {
            $listing = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
        } catch (\Throwable) {
            return $columns;
        }

        if ($listing === []) {
            return $columns;
        }

        $listing = array_map('strtolower', $listing);
        $missing = array_values(array_filter($columns, fn (string $column): bool => ! str_contains($column, '.')
            && ! str_contains($column, '->')
            && ! in_array(strtolower($column), $listing, true)));

        if ($missing !== []) {
            Log::warning('Holler: the people search skips the columns '.implode(', ', $missing).", which the {$model->getTable()} table does not have. Fix `search_columns` in config/holler.php.");
        }

        return array_values(array_diff($columns, $missing));
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
