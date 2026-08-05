<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Actions\Events\ManageEventSubstitution;
use App\Enums\EventSubstituteRequestStatus;
use App\Filament\Admin\Pages\SubstituteRequest;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

final class SubstituteRequestBanners extends Widget
{
    public ?int $suppressedRequestId = null;

    protected static bool $isLazy = false;

    protected string $view = 'filament.admin.widgets.substitute-request-banners';

    public function mount(): void
    {
        if (! request()->routeIs(SubstituteRequest::getRouteName())) {
            return;
        }

        $request = request()->route('request');

        if ($request instanceof EventSubstituteRequest) {
            $this->suppressedRequestId = $request->id;

            return;
        }

        if (is_numeric($request)) {
            $this->suppressedRequestId = (int) $request;
        }
    }

    /** @return Collection<int, EventSubstituteRequest> */
    public function pendingSubstituteRequests(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        return EventSubstituteRequest::query()
            ->with(['event.course', 'requestedBy'])
            ->where('teacher_id', $user->id)
            ->where('status', EventSubstituteRequestStatus::Pending)
            ->when(
                $this->suppressedRequestId !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($this->suppressedRequestId),
            )
            ->whereHas('event', fn (Builder $query): Builder => $query
                ->whereNull('cancelled_at')
                ->where(function (Builder $query): void {
                    $query
                        ->where('end_time', '>', now())
                        ->orWhere(function (Builder $query): void {
                            $query
                                ->whereNull('end_time')
                                ->where('start_time', '>', now());
                        });
                }))
            ->oldest()
            ->get();
    }

    public function acceptSubstituteRequest(int $requestId): void
    {
        $this->respondToSubstituteRequest($requestId, true);
    }

    public function declineSubstituteRequest(int $requestId): void
    {
        $this->respondToSubstituteRequest($requestId, false);
    }

    public function substituteRequestUrl(EventSubstituteRequest $request): string
    {
        return SubstituteRequest::getUrl(['request' => $request], panel: 'admin');
    }

    private function respondToSubstituteRequest(int $requestId, bool $accept): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $request = EventSubstituteRequest::query()
            ->whereKey($requestId)
            ->where('teacher_id', $user->id)
            ->first();

        if (! $request instanceof EventSubstituteRequest) {
            return;
        }

        try {
            app(ManageEventSubstitution::class)->respond($request, $user, $accept);

            Notification::make()
                ->title($accept ? 'Substitute request accepted' : 'Substitute request declined')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not respond to substitute request')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
