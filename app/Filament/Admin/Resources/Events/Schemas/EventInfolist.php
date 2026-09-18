<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Schemas;

use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Event;
use App\Models\EventSubstituteRequest;
use App\Models\Order;
use App\Models\OrderItemFulfillment;
use App\Support\MediaDisks;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class EventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Event')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('focus')
                            ->label('Focus / Theme (Public)')
                            ->placeholder('None'),
                        TextEntry::make('description')
                            ->label('Public Description')
                            ->placeholder('None')
                            ->columnSpanFull(),
                        TextEntry::make('details')
                            ->label('Lesson Plan (Staff Only)')
                            ->placeholder('None')
                            ->columnSpanFull(),
                        TextEntry::make('course.name')
                            ->label('Course')
                            ->placeholder('None'),
                        TextEntry::make('teachers.fullName')
                            ->label('Teachers')
                            ->listWithLineBreaks()
                            ->placeholder('Unassigned'),
                        TextEntry::make('calendar.name')
                            ->label('Calendar')
                            ->placeholder('None'),
                        TextEntry::make('start_time')
                            ->label('Starts At')
                            ->dateTime(),
                        TextEntry::make('end_time')
                            ->label('Ends At')
                            ->dateTime(),
                    ]),
                Section::make('Media')
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('images')
                            ->collection('images')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            // ->conversion('thumb')
                            ->columnSpanFull(),
                        RepeatableEntry::make('documents')
                            ->state(fn (Event $record) => $record->getMedia('documents'))
                            ->schema([
                                TextEntry::make('file_name')
                                    ->label('Document')
                                    ->icon(Heroicon::OutlinedArrowDownTray)
                                    ->url(fn (Media $record): string => $record->getTemporaryUrl(
                                        now()->addMinutes((int) config('filament.temporary_file_url_expiry_minutes', 30)),
                                    ))
                                    ->openUrlInNewTab(),
                            ])
                            ->contained(false)
                            ->columnSpanFull()
                            ->visible(fn (Event $record): bool => $record->getMedia('documents')->isNotEmpty()),
                    ]),
                Section::make('Order Fulfillment')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('orderItemFulfillments')
                            ->hiddenLabel()
                            ->columns(6)
                            ->schema([
                                TextEntry::make('orderItem.order.id')
                                    ->label('Order #')
                                    ->url(fn (OrderItemFulfillment $record): string => OrderResource::getUrl('view', [
                                        'record' => $record->orderItem->order_id,
                                    ])),
                                TextEntry::make('orderItem.order.user.full_name')
                                    ->label('Purchaser'),
                                TextEntry::make('orderItem.product.name')
                                    ->label('Product'),
                                TextEntry::make('unit_number')
                                    ->label('Unit'),
                                TextEntry::make('fulfilled_at')
                                    ->label('Linked At')
                                    ->dateTime(),
                                TextEntry::make('link_status')
                                    ->label('Status')
                                    ->state(fn (OrderItemFulfillment $record): string => $record->isActive() ? 'Active' : 'Reopened')
                                    ->badge()
                                    ->color(fn (OrderItemFulfillment $record): string => $record->isActive() ? 'success' : 'gray'),
                            ]),
                    ])
                    ->visible(fn (Event $record): bool => Gate::allows('viewAny', Order::class)
                        && $record->orderItemFulfillments()->exists()),
                Section::make('Substitute Coverage')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('substitute_coverage_status')
                            ->label('Status')
                            ->state(fn (Event $record): string => $record->substituteCoverageLabel())
                            ->badge()
                            ->color(fn (Event $record): string => $record->substituteCoverageStatus()->getColor()),
                        RepeatableEntry::make('substituteCoverages')
                            ->label('Coverage by Teacher')
                            ->table([
                                TableColumn::make('Teacher'),
                                TableColumn::make('Confirmed Substitute'),
                                TableColumn::make('Needed At'),
                                TableColumn::make('Closed At'),
                            ])
                            ->schema([
                                TextEntry::make('coveredTeacher.fullName')
                                    ->label('Teacher')
                                    ->placeholder('Original teacher not recorded'),
                                TextEntry::make('substituteTeacher.fullName')
                                    ->label('Confirmed Substitute')
                                    ->placeholder('Uncovered'),
                                TextEntry::make('needed_at')
                                    ->label('Needed At')
                                    ->dateTime(),
                                TextEntry::make('closed_at')
                                    ->label('Closed At')
                                    ->dateTime()
                                    ->placeholder('Active'),
                            ])
                            ->contained(false)
                            ->columnSpanFull(),
                        RepeatableEntry::make('substituteRequests')
                            ->label('Request History')
                            ->table([
                                TableColumn::make('Teacher Covered'),
                                TableColumn::make('Teacher'),
                                TableColumn::make('Status'),
                                TableColumn::make('Requested By'),
                                TableColumn::make('Requested'),
                                TableColumn::make('Reason / Note'),
                            ])
                            ->schema([
                                TextEntry::make('coverage.coveredTeacher.fullName')
                                    ->label('Teacher Covered')
                                    ->placeholder('Original teacher not recorded'),
                                TextEntry::make('teacher.fullName')
                                    ->label('Teacher')
                                    ->placeholder('Deleted user'),
                                TextEntry::make('status')
                                    ->badge(),
                                TextEntry::make('requestedBy.fullName')
                                    ->label('Requested By')
                                    ->placeholder('Deleted user'),
                                TextEntry::make('created_at')
                                    ->label('Requested')
                                    ->dateTime(),
                                TextEntry::make('request_summary')
                                    ->label('Reason / Note')
                                    ->state(fn (EventSubstituteRequest $record): ?string => $record->release_reason
                                        ?? $record->response_note
                                        ?? $record->closure_reason
                                        ?? $record->request_reason)
                                    ->placeholder('None'),
                            ])
                            ->contained(false)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Event $record): bool => Gate::allows('update', $record)
                        && ($record->substituteCoverages()->exists()
                            || $record->substituteRequests()->exists())),
                Section::make('Cancellation')
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (Event $record): bool => $record->isCancelled())
                    ->schema([
                        TextEntry::make('cancelled_at')
                            ->label('Cancelled At')
                            ->dateTime(),
                        TextEntry::make('cancelledBy.fullName')
                            ->label('Cancelled By')
                            ->placeholder('Unknown'),
                        TextEntry::make('cancellation_reason')
                            ->label('Reason')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
