<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Models\Calendar;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Tags\Tag;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('avatar')
                            ->collection('avatars')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->image()
                            ->avatar()
                            ->circleCropper()
                            ->columnSpanFull(),
                        TextInput::make('first_name')
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('last_name')
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('email')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->email()
                            ->required(),
                    ]),
                Section::make('Access')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->live()
                            ->preload(),
                        Select::make('calendar_audience_tag_ids')
                            ->label('Calendar Audience Tags')
                            ->multiple()
                            ->preload()
                            ->options(fn (): array => Tag::query()
                                ->where('type', Calendar::AUDIENCE_TAG_TYPE)
                                ->orderBy('order_column')
                                ->orderBy('id')
                                ->get()
                                ->mapWithKeys(fn (Tag $tag): array => [$tag->id => $tag->name])
                                ->all())
                            ->visible(fn (Get $get, ?User $record): bool => self::hasSelectedRoles($get('roles'), $record))
                            ->loadStateFromRelationshipsUsing(function (Select $component, ?User $record): void {
                                $component->state($record?->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)
                                    ->pluck('id')
                                    ->map(fn (int $id): string => (string) $id)
                                    ->all() ?? []);
                            })
                            ->saveRelationshipsUsing(function (?User $record, array $state): void {
                                $tagIds = Tag::query()
                                    ->where('type', Calendar::AUDIENCE_TAG_TYPE)
                                    ->whereIn('id', $state)
                                    ->pluck('id')
                                    ->all();

                                $record?->syncTagIds($tagIds, Calendar::AUDIENCE_TAG_TYPE);
                            })
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
                Section::make('Security')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('password')
                            ->password()
                            ->required(fn ($record): bool => $record === null)
                            ->revealable(filament()->arePasswordsRevealable())
                            ->rule(Password::default())
                            ->autocomplete('new-password')
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->dehydrateStateUsing(fn ($state): string => Hash::make($state)),
                    ]),
                Section::make('Staff Profile')
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('staff_photo')
                            ->label('Staff Photo')
                            ->collection('staff-photo')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->image()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function hasSelectedRoles(mixed $roles, ?User $record): bool
    {
        if (is_array($roles)) {
            return $roles !== [];
        }

        return $record instanceof User && $record->roles()->exists();
    }
}
