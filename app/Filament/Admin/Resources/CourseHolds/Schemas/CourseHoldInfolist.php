<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CourseHolds\Schemas;

use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class CourseHoldInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Hold Details')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('user.full_name')->label('Family / User'),
                        TextEntry::make('status')
                            ->state(fn (CourseHold $record): string => $record->status()->getLabel())
                            ->badge()
                            ->color(fn (CourseHold $record): string => $record->status()->getColor()),
                        TextEntry::make('expires_at')->dateTime(),
                        TextEntry::make('createdBy.full_name')->label('Created By')->placeholder('System'),
                        TextEntry::make('notes')->placeholder('No notes')->columnSpanFull(),
                    ]),
                Section::make('Seats')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('seat_summary')
                            ->hiddenLabel()
                            ->state(fn (CourseHold $record): HtmlString => self::seatSummary($record))
                            ->html(),
                    ]),
            ]);
    }

    private static function seatSummary(CourseHold $hold): HtmlString
    {
        $hold->loadMissing(['seats.course', 'seats.student', 'seats.enrollment.orderItem.order']);

        $rows = $hold->seats
            ->groupBy('course_id')
            ->map(function ($seats): string {
                /** @var CourseHoldSeat $first */
                $first = $seats->first();
                $purchased = $seats->filter(fn (CourseHoldSeat $seat): bool => $seat->enrollment !== null)->count();
                $released = $seats->whereNotNull('released_at')->count();
                $students = $seats->pluck('student.full_name')->filter()->unique()->implode(', ');

                return '<tr>'
                    .'<td class="px-3 py-2">'.e($first->course->name).'</td>'
                    .'<td class="px-3 py-2">'.$seats->count().'</td>'
                    .'<td class="px-3 py-2">'.e(format_money($first->locked_unit_price)).'</td>'
                    .'<td class="px-3 py-2">'.$purchased.' purchased, '.$released.' released</td>'
                    .'<td class="px-3 py-2">'.e($students ?: 'Unassigned').'</td>'
                    .'</tr>';
            })
            ->implode('');

        return new HtmlString('<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr>'
            .'<th class="px-3 py-2 text-left">Class</th><th class="px-3 py-2 text-left">Seats</th>'
            .'<th class="px-3 py-2 text-left">Held Price</th><th class="px-3 py-2 text-left">Status</th>'
            .'<th class="px-3 py-2 text-left">Students</th></tr></thead><tbody>'.$rows.'</tbody></table></div>');
    }
}
