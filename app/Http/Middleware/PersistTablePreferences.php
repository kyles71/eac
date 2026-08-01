<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class PersistTablePreferences
{
    private const string TABLE_SESSION_KEY = 'tables';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $this->hydrateSession($request, $user);
        $preferencesBeforeRequest = $this->columnPreferencesFromSession($request);
        $response = $next($request);
        $preferencesAfterRequest = $this->columnPreferencesFromSession($request);
        $changedPreferences = array_filter(
            $preferencesAfterRequest,
            fn (mixed $value, string $key): bool => ! array_key_exists($key, $preferencesBeforeRequest)
                || $preferencesBeforeRequest[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
        $removedPreferenceKeys = array_keys(array_diff_key(
            $preferencesBeforeRequest,
            $preferencesAfterRequest,
        ));

        if ($changedPreferences !== [] || $removedPreferenceKeys !== []) {
            $this->persistPreferences($user, $changedPreferences, $removedPreferenceKeys);
        }

        return $response;
    }

    private function hydrateSession(Request $request, User $user): void
    {
        $tableSession = $request->session()->get(self::TABLE_SESSION_KEY, []);
        $tableSession = is_array($tableSession) ? $tableSession : [];
        $tableSession = array_filter(
            $tableSession,
            fn (string $key): bool => ! $this->isColumnPreferenceKey($key),
            ARRAY_FILTER_USE_KEY,
        );
        $storedPreferences = $this->preferencesFor($user);

        $request->session()->put(self::TABLE_SESSION_KEY, [
            ...$tableSession,
            ...$this->sanitizePreferences($storedPreferences),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function columnPreferencesFromSession(Request $request): array
    {
        $tableSession = $request->session()->get(self::TABLE_SESSION_KEY, []);

        if (! is_array($tableSession)) {
            return [];
        }

        return $this->sanitizePreferences($tableSession);
    }

    /**
     * @return array<string, mixed>
     */
    private function preferencesFor(User $user): array
    {
        if (array_key_exists('table_preferences', $user->getAttributes())) {
            return $this->decodePreferences($user->getRawOriginal('table_preferences'));
        }

        return $this->decodePreferences(
            User::query()
                ->whereKey($user->getKey())
                ->value('table_preferences'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePreferences(mixed $preferences): array
    {
        if (is_array($preferences)) {
            return $preferences;
        }

        if (! is_string($preferences) || $preferences === '') {
            return [];
        }

        try {
            $decodedPreferences = json_decode($preferences, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decodedPreferences)
            ? $decodedPreferences
            : [];
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @param  list<string>  $removedPreferenceKeys
     */
    private function persistPreferences(User $user, array $preferences, array $removedPreferenceKeys): void
    {
        $freshUser = User::query()->find($user->getKey());

        if (! $freshUser instanceof User) {
            return;
        }

        $storedPreferences = $this->sanitizePreferences($this->preferencesFor($freshUser));

        foreach ($removedPreferenceKeys as $removedPreferenceKey) {
            unset($storedPreferences[$removedPreferenceKey]);
        }

        $freshUser->timestamps = false;
        $freshUser->forceFill([
            'table_preferences' => [
                ...$storedPreferences,
                ...$preferences,
            ],
        ])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    private function sanitizePreferences(array $preferences): array
    {
        return array_filter(
            $preferences,
            fn (string $key): bool => $this->isColumnPreferenceKey($key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function isColumnPreferenceKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{32}_(columns|has_reordered_columns)$/', $key) === 1;
    }
}
