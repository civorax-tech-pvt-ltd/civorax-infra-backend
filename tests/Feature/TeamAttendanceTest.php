<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceMonthlySummaryResource\Pages\ListAttendanceMonthlySummaries;
use App\Filament\Resources\AttendanceResource;
use App\Filament\Resources\AttendanceResource\Pages\CreateAttendance;
use App\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use App\Filament\Widgets\TodayAttendance;
use App\Livewire\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendanceMonthlySummary;
use App\Models\AttendanceVisit;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\LocationPing;
use App\Models\OfficeLocation;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamAttendanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Site of the test project (Belbari).
     */
    protected const SITE = [26.6621, 87.4478];

    protected User $admin;

    protected TeamMember $member;

    protected User $memberUser;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));

        [$this->member, $this->memberUser] = $this->makeTeamMember('9822222222', 'Hari Bdr');

        $client = Client::create([
            'user_id' => User::factory()->create(['phone' => '9811111111'])->id,
            'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111',
            'address' => 'Belbari',
        ]);

        $this->project = Project::create([
            'client_id' => $client->id,
            'project_type_id' => ProjectType::create(['name' => 'Interior', 'slug' => 'interior', 'is_active' => true])->id,
            'title' => 'Interior – Shishir Sharma',
            'description' => 'Interior work',
            'site_address' => 'Belbari',
            'latitude' => self::SITE[0],
            'longitude' => self::SITE[1],
            'geofence_radius' => 1000,
            'status' => 'designing',
            'created_by' => $this->admin->id,
        ]);

        $this->project->teamMembers()->attach($this->member);
    }

    public function test_distance_is_measured_in_metres(): void
    {
        // One thousandth of a degree of latitude is about 111 m.
        $this->assertEqualsWithDelta(111, Attendance::distanceInMeters(26.6621, 87.4478, 26.6631, 87.4478), 1);
        $this->assertSame(0, Attendance::distanceInMeters(...self::SITE, ...self::SITE));
    }

    public function test_being_within_the_site_radius_marks_attendance_once_per_day(): void
    {
        Carbon::setTestNow('2026-09-28 04:00:00'); // 9:45 AM in Nepal

        $result = Attendance::recordLocation($this->member, self::SITE[0] + 0.0045, self::SITE[1], 20);

        $this->assertTrue($result['matched']);
        $this->assertSame('Interior – Shishir Sharma', $result['place']);
        $this->assertEqualsWithDelta(500, $result['distance'], 5);

        Carbon::setTestNow('2026-09-28 09:00:00');
        Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 15);

        $attendance = Attendance::sole();
        $this->assertSame('2026-09-28', $attendance->date->toDateString());
        $this->assertSame('2026-09-28 04:00:00', $attendance->first_seen_at->toDateTimeString());
        $this->assertSame('2026-09-28 09:00:00', $attendance->last_seen_at->toDateTimeString());
        $this->assertSame(5.0, $attendance->hoursOnDuty());

        $visit = $attendance->visits()->sole();
        $this->assertSame($this->project->id, $visit->project_id);
        $this->assertSame(2, $visit->pings);
        $this->assertSame(0, $visit->closest_distance);
    }

    public function test_outside_the_radius_is_logged_but_not_counted(): void
    {
        $result = Attendance::recordLocation($this->member, self::SITE[0] + 0.02, self::SITE[1], 20); // ~2.2 km away

        $this->assertFalse($result['matched']);
        $this->assertSame(0, Attendance::count());
        $this->assertMatchesRegularExpression('/^Not at a site: nearest is .+, 2\.2 km away \(must be within 1 km\)\.$/', $result['message']);

        $ping = LocationPing::sole();
        $this->assertFalse($ping->matched);
        $this->assertSame($this->member->id, $ping->team_member_id);
    }

    public function test_only_sites_of_the_members_own_projects_count(): void
    {
        [$outsider] = $this->makeTeamMember('9833333333', 'Saugat Dhungana');

        $result = Attendance::recordLocation($outsider, ...[...self::SITE, 10]);

        $this->assertFalse($result['matched']);
        $this->assertSame(0, Attendance::count());
        // Nothing of theirs has coordinates, so the badge says what to fix instead of "not at a site".
        $this->assertSame('No office or project site of yours has a GPS location yet. Ask the admin to set one.', $result['message']);
    }

    public function test_offices_count_for_every_team_member(): void
    {
        [$officeWorker] = $this->makeTeamMember('9833333333', 'Saugat Dhungana');
        OfficeLocation::create(['name' => 'Head office', 'latitude' => 26.45, 'longitude' => 87.27, 'geofence_radius' => 300]);

        $result = Attendance::recordLocation($officeWorker, 26.4510, 87.2700, 25);

        $this->assertTrue($result['matched']);
        $this->assertSame('Head office', $result['place']);
        $this->assertSame('Head office', Attendance::sole()->placesSummary());
    }

    public function test_imprecise_locations_never_mark_attendance(): void
    {
        $result = Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 1500);

        $this->assertFalse($result['matched']);
        $this->assertStringContainsString('too imprecise', $result['message']);
        $this->assertSame(0, Attendance::count());
    }

    public function test_the_attendance_day_follows_nepal_time(): void
    {
        Carbon::setTestNow('2026-09-28 20:00:00'); // 1:45 AM on the 29th in Nepal

        Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 10);

        $this->assertSame('2026-09-29', Attendance::sole()->date->toDateString());
    }

    public function test_the_header_badge_reports_location_and_is_rate_limited(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->memberUser);
        RateLimiter::clear('attendance-location:'.$this->memberUser->id);

        Livewire::test(AttendanceTracker::class)
            ->assertSet('state', 'waiting')
            ->call('report', self::SITE[0], self::SITE[1], 12.5)
            ->assertSet('state', 'present')
            ->assertSee('At Interior – Shishir Sharma')
            ->call('report', self::SITE[0], self::SITE[1], 12.5);

        $this->assertSame(1, LocationPing::count());

        Livewire::test(AttendanceTracker::class)->assertSet('state', 'present')->assertSee('Present since');
    }

    public function test_admins_add_manual_attendance_but_not_twice_for_one_day(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'team_member_id' => $this->member->id,
                'first_seen_at' => '2026-09-28 09:00',
                'last_seen_at' => '2026-09-28 17:30',
                'note' => 'Phone battery died at site',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $attendance = Attendance::sole();
        $this->assertSame('manual', $attendance->source);
        $this->assertSame('2026-09-28', $attendance->date->toDateString());
        $this->assertSame('2026-09-28 03:15:00', $attendance->first_seen_at->toDateTimeString()); // 9:00 Nepal = 03:15 UTC
        $this->assertSame($this->admin->id, $attendance->recorded_by);

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'team_member_id' => $this->member->id,
                'first_seen_at' => '2026-09-28 10:00',
                'last_seen_at' => '2026-09-28 11:00',
                'note' => 'Duplicate',
            ])
            ->call('create')
            ->assertHasFormErrors(['first_seen_at']);
    }

    public function test_team_members_only_see_their_own_attendance_and_cannot_add_any(): void
    {
        [$colleague] = $this->makeTeamMember('9833333333', 'Saugat Dhungana');
        $mine = Attendance::create(['team_member_id' => $this->member->id, 'date' => '2026-09-28', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $theirs = Attendance::create(['team_member_id' => $colleague->id, 'date' => '2026-09-28', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->memberUser);

        $this->assertSame('My Attendance', AttendanceResource::getNavigationLabel());
        $this->assertFalse(AttendanceResource::canCreate());

        Livewire::test(ListAttendances::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_admins_can_open_team_member_locations_in_google_maps(): void
    {
        Carbon::setTestNow('2026-09-28 04:00:00');
        Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 12);
        Carbon::setTestNow('2026-09-28 06:00:00');
        Attendance::recordLocation($this->member, 26.7, 87.5, 30); // left the site

        $attendance = Attendance::sole();
        $latest = $attendance->latestPing();

        $this->assertSame('https://www.google.com/maps?q=26.7,87.5', $latest->mapUrl());
        $this->assertFalse($latest->matched);
        $this->assertCount(2, $attendance->dayPings()->get());
        $this->assertSame('https://www.google.com/maps?q=26.6621,87.4478', $attendance->visits()->sole()->mapUrl());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(ListAttendances::class)
            ->assertSee('11:45 AM · ±30 m')
            ->assertSee('https://www.google.com/maps?q=26.7,87.5', escape: false);

        $this->get(AttendanceResource::getUrl('index'))->assertOk();

        Livewire::test(TodayAttendance::class)
            ->assertSee('11:45 AM · outside sites')
            ->assertSee('https://www.google.com/maps?q=26.7,87.5', escape: false);
    }

    public function test_monthly_summaries_stay_up_to_date(): void
    {
        OfficeLocation::create(['name' => 'Head office', 'latitude' => 26.45, 'longitude' => 87.27, 'geofence_radius' => 300]);

        Carbon::setTestNow('2026-09-27 04:00:00');
        Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 10);
        Carbon::setTestNow('2026-09-27 10:00:00');
        Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 10);
        Carbon::setTestNow('2026-09-28 04:00:00');
        Attendance::recordLocation($this->member, 26.45, 87.27, 10);

        $summary = AttendanceMonthlySummary::sole();
        $this->assertSame('2026-09-01', $summary->month->toDateString());
        $this->assertSame(2, $summary->present_days);
        $this->assertSame(2, $summary->gps_days);
        $this->assertSame(6.0, $summary->total_hours);
        $this->assertSame(['Interior – Shishir Sharma' => 1, 'Head office' => 1], $summary->place_days);

        Attendance::query()->whereDate('date', '2026-09-28')->sole()->delete();

        $this->assertSame(1, $summary->refresh()->present_days);
    }

    public function test_the_monthly_attendance_screen_lists_summaries_per_panel(): void
    {
        [$colleague] = $this->makeTeamMember('9833333333', 'Saugat Dhungana');
        Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 10);
        Attendance::create(['team_member_id' => $colleague->id, 'date' => Attendance::businessToday(), 'first_seen_at' => now(), 'last_seen_at' => now(), 'source' => 'manual', 'note' => 'Office']);

        $mine = AttendanceMonthlySummary::where('team_member_id', $this->member->id)->sole();
        $theirs = AttendanceMonthlySummary::where('team_member_id', $colleague->id)->sole();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(ListAttendanceMonthlySummaries::class)
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->filterTable('team_member_id', $colleague->id)
            ->assertCanSeeTableRecords([$theirs])
            ->assertCanNotSeeTableRecords([$mine]);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->memberUser);

        Livewire::test(ListAttendanceMonthlySummaries::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_old_detail_is_pruned_but_monthly_summaries_are_kept(): void
    {
        Carbon::setTestNow('2026-05-12 04:00:00');
        Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 10);
        Carbon::setTestNow('2026-06-15 04:00:00');
        Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 10);

        Carbon::setTestNow('2026-09-28 04:00:00'); // keep June–September, drop May
        $this->artisan('attendance:maintain')->assertSuccessful();

        $this->assertSame(['2026-06-15'], Attendance::pluck('date')->map->toDateString()->all());
        $this->assertSame(1, LocationPing::count());
        $this->assertSame(0, AttendanceVisit::whereDate('first_seen_at', '<', '2026-06-01')->count());

        $may = AttendanceMonthlySummary::query()->whereDate('month', '2026-05-01')->sole();
        $this->assertSame(1, $may->present_days);

        // Frozen months are never recalculated from their (now deleted) detail.
        AttendanceMonthlySummary::rebuildFor($this->member->id, '2026-05-01');
        $this->assertSame(1, $may->refresh()->present_days);
        $this->assertSame(2, AttendanceMonthlySummary::count());
    }

    public function test_the_dashboard_shows_who_is_present_today(): void
    {
        [$absent] = $this->makeTeamMember('9833333333', 'Saugat Dhungana');
        Attendance::recordLocation($this->member, self::SITE[0], self::SITE[1], 10);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(TodayAttendance::class)
            ->assertCanSeeTableRecords([$this->member, $absent])
            ->assertSee('Present')
            ->assertSee('Not seen yet')
            ->assertSee('Interior – Shishir Sharma');
    }

    /**
     * @return array{0: TeamMember, 1: User}
     */
    protected function makeTeamMember(string $phone, string $name): array
    {
        $user = User::factory()->create(['phone' => $phone, 'name' => $name]);

        $member = TeamMember::create([
            'user_id' => $user->id,
            'fullname' => $name,
            'contact1' => $phone,
            'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg',
            'bank_name' => 'Nabil',
            'bank_account_name' => $name,
            'bank_account_number' => '111',
            'created_by' => $this->admin->id ?? null,
        ]);

        return [$member, $user];
    }
}
