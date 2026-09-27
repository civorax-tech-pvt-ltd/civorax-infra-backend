<?php

namespace App\Livewire;

use App\Models\Attendance;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Team panel header badge: sends the browser's location while the panel is open and shows
 * whether the member is marked present at a site or office.
 */
class AttendanceTracker extends Component
{
    /**
     * Seconds between location reports; the browser also reports on every page load.
     */
    public const INTERVAL_SECONDS = 300;

    public string $state = 'waiting';

    public string $message = 'Checking location…';

    public function mount(): void
    {
        $today = auth()->user()?->teamMember?->attendances()
            ->whereDate('date', Attendance::businessToday())
            ->with('visits.project', 'visits.officeLocation')
            ->first();

        if ($today !== null) {
            $this->state = 'present';
            $this->message = 'Present since '.$today->first_seen_at->timezone(config('app.business_timezone'))->format('g:i A');
        }
    }

    public function report(float $latitude, float $longitude, float $accuracy): void
    {
        $teamMember = auth()->user()?->teamMember;

        if ($teamMember === null || abs($latitude) > 90 || abs($longitude) > 180 || $accuracy < 0) {
            return;
        }

        if (! RateLimiter::attempt('attendance-location:'.auth()->id(), 1, fn () => true, 60)) {
            return;
        }

        $result = Attendance::recordLocation($teamMember, $latitude, $longitude, $accuracy);

        $this->state = $result['matched'] ? 'present' : 'away';
        $this->message = $result['matched']
            ? "At {$result['place']}"
            : $result['message'];
    }

    public function locationUnavailable(string $reason): void
    {
        $this->state = 'error';
        $this->message = $reason;
    }

    public function render(): View
    {
        return view('livewire.attendance-tracker');
    }
}
