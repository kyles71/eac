<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CourseHolds\Pages;

use App\Actions\CourseHolds\ReleaseCourseHoldSeats;
use App\Actions\Mail\SendCourseHoldEmail;
use App\Filament\Admin\Resources\CourseHolds\CourseHoldResource;
use App\Models\CourseHold;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

final class ViewCourseHold extends ViewRecord
{
    protected static string $resource = CourseHoldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CourseHoldResource::editAction(),
            Action::make('resend')
                ->label('Resend Details')
                ->icon(Heroicon::OutlinedEnvelope)
                ->action(function (): void {
                    app(SendCourseHoldEmail::class)->handle($this->courseHold(), 'course-hold-changed');

                    Notification::make()->title('Hold details queued')->success()->send();
                }),
            Action::make('release')
                ->label('Release Remaining Seats')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->courseHold()->availableSeatCount() > 0)
                ->action(function (): void {
                    try {
                        /** @var User $admin */
                        $admin = auth()->user();
                        app(ReleaseCourseHoldSeats::class)->handle($this->courseHold(), $admin);
                        $this->refreshFormData(['expires_at']);
                        Notification::make()->title('Remaining seats released')->success()->send();
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title('Seats could not be released')->body($exception->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    private function courseHold(): CourseHold
    {
        /** @var CourseHold $record */
        $record = $this->getRecord();

        return $record;
    }
}
