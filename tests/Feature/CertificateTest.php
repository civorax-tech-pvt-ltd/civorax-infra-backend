<?php

namespace Tests\Feature;

use App\Filament\Resources\CertificateResource;
use App\Filament\Resources\CertificateResource\Pages\CreateCertificate;
use App\Filament\Resources\CertificateResource\Pages\ListCertificates;
use App\Filament\Student\Resources\EnrollmentResource\Pages\ViewEnrollment;
use App\Models\Certificate;
use App\Models\CertificateSetting;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $studentUser;

    protected Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-28 06:00:00');

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));

        $this->studentUser = User::factory()->create(['phone' => '9844444444']);
        $student = Student::create(['user_id' => $this->studentUser->id, 'fullname' => 'Divash Chaudahary', 'dob' => '2004-01-01', 'contact' => '9844444444', 'address' => 'Itahari']);
        $course = Course::create(['title' => 'AutoCAD 2D', 'description' => 'x', 'syllabus' => 'x', 'type' => 'online', 'duration' => '2 months', 'fee' => 12000, 'status' => 'active', 'created_by' => $this->admin->id]);
        $this->enrollment = Enrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'enrolled_at' => '2026-07-01', 'created_by' => $this->admin->id]);

        CertificateSetting::current()->update(['organization_name' => 'CivoraX Infra', 'phone' => '9812345678', 'pan_number' => '123456789', 'default_instructor' => 'Dheeraj Uparkoti', 'director_name' => 'Hari Sharma']);
    }

    protected function issue(array $overrides = []): Certificate
    {
        return Certificate::create([
            'enrollment_id' => $this->enrollment->id,
            'student_name' => 'Divash Chaudahary',
            'course_title' => 'AutoCAD 2D — morning batch',
            'grade' => 'A+',
            'issued_on' => '2026-09-28',
            'completed_on' => '2026-09-27',
            'instructor_name' => 'Dheeraj Uparkoti',
            'issued_by' => $this->admin->id,
            ...$overrides,
        ]);
    }

    public function test_admin_issues_a_numbered_certificate_from_an_enrollment(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::withQueryParams(['enrollment' => $this->enrollment->id])
            ->test(CreateCertificate::class)
            ->assertFormSet(['enrollment_id' => $this->enrollment->id, 'student_name' => 'Divash Chaudahary', 'course_title' => 'AutoCAD 2D', 'instructor_name' => 'Dheeraj Uparkoti'])
            ->fillForm(['course_title' => 'AutoCAD 2D — morning batch', 'grade' => 'A+'])
            ->call('create')
            ->assertHasNoFormErrors();

        $certificate = Certificate::sole();
        $this->assertSame('CERT-2026-0001', $certificate->number);
        $this->assertSame(10, strlen($certificate->verification_code));
        $this->assertSame('Your certificate is ready', $this->studentUser->notifications()->sole()->data['title']);

        // One certificate per enrollment.
        Livewire::test(CreateCertificate::class)
            ->fillForm(['enrollment_id' => $this->enrollment->id, 'student_name' => 'X', 'course_title' => 'Y'])
            ->call('create')
            ->assertHasFormErrors(['enrollment_id']);
    }

    public function test_numbers_run_per_year(): void
    {
        $this->assertSame('CERT-2026-0001', $this->issue()->number);
        $this->assertSame('CERT-2026-0002', Certificate::nextNumber('2026-12-31'));
        $this->assertSame('CERT-2027-0001', Certificate::nextNumber('2027-01-01'));
    }

    public function test_the_printed_certificate_matches_the_design(): void
    {
        $certificate = $this->issue();

        $this->actingAs($this->studentUser)
            ->get(route('certificates.show', $certificate))
            ->assertOk()
            ->assertSee('Certificate of Completion')
            ->assertSee('CivoraX Infra')
            ->assertSee('PAN Number: 123456789')
            ->assertSee('Divash Chaudahary')
            ->assertSee('AutoCAD 2D — morning batch')
            ->assertSee('Result / Grade: A+')
            ->assertSee('CERT-2026-0001')
            ->assertSee('2026-09-28 (2083 Aswin 12)')
            ->assertSee('<svg', false);
    }

    public function test_only_the_owner_or_an_admin_can_open_it_and_revoked_ones_are_hidden_from_students(): void
    {
        $certificate = $this->issue();
        $other = User::factory()->create(['phone' => '9855555555']);
        Student::create(['user_id' => $other->id, 'fullname' => 'Someone Else', 'dob' => '2004-01-01', 'contact' => '9855555555', 'address' => 'Itahari']);

        $this->actingAs($other)->get(route('certificates.show', $certificate))->assertForbidden();
        $this->actingAs($this->admin)->get(route('certificates.show', $certificate))->assertOk();

        $certificate->revoke('Name misspelt');

        $this->actingAs($this->studentUser)->get(route('certificates.show', $certificate))->assertForbidden();
        $this->actingAs($this->admin)->get(route('certificates.show', $certificate))->assertOk()->assertSee('REVOKED');
    }

    public function test_guests_are_sent_to_the_student_login(): void
    {
        $this->get(route('certificates.show', $this->issue()))->assertRedirect('/student/login');
    }

    public function test_anyone_can_verify_a_certificate_by_its_qr_code(): void
    {
        $certificate = $this->issue();

        $this->get($certificate->verifyUrl())
            ->assertOk()
            ->assertSee('Genuine certificate')
            ->assertSee('Divash Chaudahary')
            ->assertSee('CERT-2026-0001');

        $this->get(route('certificates.verify', strtolower($certificate->verification_code)))->assertSee('Genuine certificate');
        $this->get(route('certificates.verify', 'NOTAREALCODE'))->assertOk()->assertSee('Certificate not found');

        $certificate->revoke('Issued by mistake');
        $this->get($certificate->verifyUrl())->assertSee('Certificate revoked');
    }

    public function test_the_student_photo_shows_when_verifying_but_is_never_printed(): void
    {
        $this->enrollment->student->update(['photo_path' => 'students/divash.jpg']);
        $certificate = $this->issue();

        $this->assertSame('students/divash.jpg', $certificate->photo_path);

        // Copied at issue time: a later photo change does not alter the issued certificate.
        $this->enrollment->student->update(['photo_path' => 'students/new.jpg']);
        $this->assertSame('students/divash.jpg', $certificate->refresh()->photo_path);

        $this->get($certificate->verifyUrl())->assertSee('students/divash.jpg')->assertSee('Photo of Divash Chaudahary');
        $this->actingAs($this->admin)->get(route('certificates.show', $certificate))->assertDontSee('divash.jpg');
    }

    public function test_the_company_stamp_is_printed_when_uploaded(): void
    {
        $certificate = $this->issue();
        $this->actingAs($this->admin)->get(route('certificates.show', $certificate))->assertDontSee('class="cert-stamp"', false);

        CertificateSetting::current()->update(['stamp_path' => 'certificates/stamp.png']);

        $this->get(route('certificates.show', $certificate))->assertSee('class="cert-stamp"', false)->assertSee('certificates/stamp.png');
    }

    public function test_the_name_can_be_left_to_a_logo_that_already_shows_it(): void
    {
        $certificate = $this->issue();
        $this->actingAs($this->admin);

        CertificateSetting::current()->update(['logo_path' => 'certificates/logo.png', 'show_name_with_logo' => false]);
        $this->get(route('certificates.show', $certificate))->assertDontSee('class="cert-org"', false)->assertSee('cert-logo--alone');
        $this->get($certificate->verifyUrl())->assertSee('Issued by CivoraX Infra');

        // Without a logo the name is always printed.
        CertificateSetting::current()->update(['logo_path' => null]);
        $this->get(route('certificates.show', $certificate))->assertSee('class="cert-org"', false);
    }

    public function test_a_year_typed_into_the_prefix_is_not_doubled(): void
    {
        CertificateSetting::current()->update(['number_prefix' => 'CERT-2026']);

        $this->assertSame('CERT-2026-0001', $this->issue()->number);
    }

    public function test_uploaded_logos_are_trimmed_of_empty_borders(): void
    {
        Storage::fake('public');

        // A 400×400 transparent palette PNG with a 100×60 mark in the middle, like a padded logo export.
        $image = imagecreate(400, 400);
        imagecolortransparent($image, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 150, 170, 249, 229, imagecolorallocate($image, 27, 36, 102));
        ob_start();
        imagepng($image);
        Storage::disk('public')->put('certificates/logo.png', ob_get_clean());

        $trimmed = CertificateSetting::trimImage('certificates/logo.png');

        $this->assertSame('certificates/logo-trimmed.png', $trimmed);
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($trimmed));
        $this->assertLessThan(120, $width);
        $this->assertLessThan(80, $height);
    }

    public function test_students_download_their_certificate_from_my_courses(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $this->actingAs($this->studentUser);

        Livewire::test(ViewEnrollment::class, ['record' => $this->enrollment->getRouteKey()])
            ->assertActionHidden('certificate');

        $certificate = $this->issue();

        Livewire::test(ViewEnrollment::class, ['record' => $this->enrollment->getRouteKey()])
            ->assertActionVisible('certificate')
            ->assertActionHasUrl('certificate', route('certificates.show', $certificate));
    }

    public function test_admins_revoke_and_restore_but_the_team_panel_has_no_certificates(): void
    {
        $certificate = $this->issue();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(ListCertificates::class)
            ->callTableAction('revoke', $certificate, ['reason' => 'Name misspelt']);
        $this->assertTrue($certificate->refresh()->isRevoked());

        Livewire::test(ListCertificates::class)
            ->callTableAction('restore', $certificate);
        $this->assertFalse($certificate->refresh()->isRevoked());

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->assertFalse(CertificateResource::canAccess());
    }
}
