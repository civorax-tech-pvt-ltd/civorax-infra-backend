<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\TeamMember;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

class TodayAttendance extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public function table(Table $table): Table
    {
        $today = Attendance::businessToday();
        $timezone = config('app.business_timezone');
        $attendanceToday = fn (TeamMember $record): ?Attendance => $record->attendances->first();

        return $table
            ->heading('Today\'s attendance')
            ->description(now($timezone)->format('l, M j'))
            ->query(
                TeamMember::query()
                    ->withoutSuperAdmins()
                    ->with([
                        'attendances' => fn ($query) => $query->whereDate('date', $today)->with('visits.project', 'visits.officeLocation'),
                        'locationPings' => fn ($query) => $query
                            ->where('recorded_at', '>=', Carbon::parse($today, $timezone)->startOfDay()->utc())
                            ->with('project', 'officeLocation')
                            ->latest('recorded_at'),
                    ])
                    ->orderBy('fullname'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('fullname')->label('Team member'),
                Tables\Columns\TextColumn::make('status')
                    ->state(fn (TeamMember $record): string => $attendanceToday($record) ? 'Present' : 'Not seen yet')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Present' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('check_in')
                    ->state(fn (TeamMember $record) => $attendanceToday($record)?->first_seen_at)
                    ->time('g:i A')
                    ->timezone($timezone)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('last_seen')
                    ->state(fn (TeamMember $record) => $attendanceToday($record)?->last_seen_at)
                    ->since()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('where')
                    ->label('Sites / office today')
                    ->state(fn (TeamMember $record): ?string => $attendanceToday($record)?->placesSummary())
                    ->placeholder('—')
                    ->wrap(),
                Tables\Columns\TextColumn::make('last_location')
                    ->label('Last location')
                    ->state(fn (TeamMember $record): ?string => ($ping = $record->locationPings->first())
                        ? $ping->recorded_at->timezone($timezone)->format('g:i A').' · '.($ping->placeName() ?? 'outside sites')
                        : null)
                    ->icon('heroicon-o-map')
                    ->color('primary')
                    ->url(fn (TeamMember $record): ?string => $record->locationPings->first()?->mapUrl(), shouldOpenInNewTab: true)
                    ->tooltip('Open in Google Maps')
                    ->placeholder('No location today'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('allAttendance')
                    ->label('All attendance')
                    ->url(AttendanceResource::getUrl())
                    ->color('gray'),
            ])
            ->paginated([10, 25, 50]);
    }
}
