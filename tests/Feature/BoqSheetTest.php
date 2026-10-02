<?php

namespace Tests\Feature;

use App\Filament\Resources\BoqMasterItemResource\Pages\ManageBoqMasterItems;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\BoqItemsRelationManager;
use App\Models\BoqCategory;
use App\Models\BoqMasterItem;
use App\Models\BoqMeasurement;
use App\Models\BoqSheet;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BoqSheetTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        $this->project = Project::create([
            'client_id' => Client::create([
                'user_id' => User::factory()->create(['phone' => '9811111111'])->id, 'contact_person' => 'Shishir Sharma',
                'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
                'contact' => '9811111111', 'address' => 'Belbari',
            ])->id,
            'project_type_id' => ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true])->id,
            'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'created_by' => $this->admin->id,
        ]);
    }

    protected function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'boq');
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_amount_is_quantity_times_rate_and_typing_an_amount_sets_the_rate(): void
    {
        $master = BoqMasterItem::create(['code' => 'TILE-001', 'description' => 'Vitrified tiles', 'unit' => 'm²', 'default_rate' => 2100]);

        Livewire::test(BoqItemsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->mountTableAction('addFromLibrary')
            ->setTableActionData(['master_item_id' => $master->id, 'quantity' => 10])
            ->assertTableActionDataSet(['rate' => '2100.00', 'amount' => 21000.0])
            ->setTableActionData(['amount' => 25000])
            ->assertTableActionDataSet(['rate' => 2500.0])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $line = $this->project->boqItems()->sole();
        $this->assertSame('2500.00', $line->rate);
        $this->assertSame('25000.00', $line->planned_value);
        $this->assertFalse($line->rate_includes_vat);
    }

    public function test_vat_switch_is_filled_from_the_library_and_can_be_changed_per_project(): void
    {
        $master = BoqMasterItem::create(['code' => 'TILE-001', 'description' => 'Vitrified tiles', 'unit' => 'm²', 'default_rate' => 2100, 'rate_includes_vat' => true]);

        Livewire::test(BoqItemsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->mountTableAction('addFromLibrary')
            ->setTableActionData(['master_item_id' => $master->id, 'quantity' => 3])
            ->assertTableActionDataSet(['rate_includes_vat' => true, 'amount' => 6300.0])
            ->setTableActionData(['rate_includes_vat' => false])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertFalse($this->project->boqItems()->sole()->rate_includes_vat);
        $this->assertTrue($master->fresh()->rate_includes_vat); // the library is not changed

        // Import: "yes" in the VAT column switches it on for the line.
        BoqSheet::importProject($this->project, BoqSheet::read($this->csv("Code,Qty,Rate includes VAT\nTILE-001,5,yes")), $this->admin);
        $this->assertTrue($this->project->boqItems()->sole()->rate_includes_vat);
    }

    public function test_library_import_adds_and_updates_items_with_loose_column_names(): void
    {
        BoqMasterItem::create(['code' => 'RCC-01', 'description' => 'RCC M20', 'unit' => 'm³', 'default_rate' => 14000]);

        $file = $this->csv("\xEF\xBB\xBF".implode("\n", [
            'Item No;Category;Particulars;Unit;Rate (Rs);Includes VAT',
            'RCC-01;Concrete / RCC;RCC M20 in slab;m³;"15,500";no',
            'TILE-01;Tile Work;Vitrified tiles 600x600;m²;2100;yes',
            ';;;;;',
            'X-1;;;;100;',
        ]));

        $result = BoqSheet::importLibrary(BoqSheet::read($file), $this->admin);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertCount(1, $result['skipped']);

        $rcc = BoqMasterItem::query()->where('code', 'RCC-01')->sole();
        $this->assertSame('15500.00', $rcc->default_rate);
        $this->assertSame('concrete', $rcc->category);
        $this->assertSame('RCC M20 in slab', $rcc->description);

        $tile = BoqMasterItem::query()->where('code', 'TILE-01')->sole();
        $this->assertTrue($tile->rate_includes_vat);
        $this->assertSame('tile-work', $tile->category);
        $this->assertTrue(BoqCategory::query()->where('key', 'tile-work')->exists());
    }

    public function test_project_import_links_library_items_adds_new_ones_and_updates_existing_lines(): void
    {
        $rcc = BoqMasterItem::create(['code' => 'RCC-01', 'description' => 'RCC M20', 'unit' => 'm³', 'default_rate' => 14000]);
        $this->project->boqItems()->create(['master_item_id' => $rcc->id, 'code' => 'RCC-01', 'description' => 'RCC M20', 'unit' => 'm³', 'quantity' => 10, 'rate' => 14000]);

        $file = $this->csv(implode("\n", [
            'Code,Description,Unit,Qty,Rate,Amount,Planned start',
            'RCC-01,,,45,,,2026-11-01',
            'PAINT-01,Two coats of emulsion paint,m²,300,,"54,000",',
            'SAND-01,Sand filling,m³,,500,,',
        ]));

        $result = BoqSheet::importProject($this->project, BoqSheet::read($file), $this->admin);

        $this->assertSame(['created' => 1, 'updated' => 1, 'library' => 1], array_intersect_key($result, array_flip(['created', 'updated', 'library'])));
        $this->assertCount(1, $result['skipped']);

        $line = $this->project->boqItems()->where('code', 'RCC-01')->sole();
        $this->assertSame('45.000', $line->quantity);
        $this->assertSame('14000.00', $line->rate); // no rate in the sheet: unchanged
        $this->assertSame('2026-11-01', $line->planned_start->toDateString());

        $paint = $this->project->boqItems()->where('code', 'PAINT-01')->sole();
        $this->assertSame('180.00', $paint->rate); // 54,000 ÷ 300
        $this->assertSame('180.00', BoqMasterItem::query()->where('code', 'PAINT-01')->sole()->default_rate);
    }

    public function test_super_admins_can_approve_their_own_measurements_but_others_cannot(): void
    {
        $line = $this->project->boqItems()->create(['code' => 'TILE-001', 'description' => 'Tiles', 'unit' => 'm²', 'quantity' => 5.574, 'rate' => 2100]);
        $own = BoqMeasurement::create(['boq_item_id' => $line->id, 'measured_date' => now()->toDateString(), 'executed_quantity' => 2, 'status' => 'pending', 'entered_by' => $this->admin->id]);

        $this->assertTrue(BoqMeasurement::canBeReviewedBy($this->admin, $own));
        $own->approve($this->admin);
        $this->assertSame('approved', $own->fresh()->status);

        $engineer = User::factory()->create(['phone' => '9822222222']);
        $engineer->assignRole(Role::create(['name' => 'engineer'])->givePermissionTo(Permission::findOrCreate('approve_boq_measurements')));
        $theirs = BoqMeasurement::create(['boq_item_id' => $line->id, 'measured_date' => now()->toDateString(), 'executed_quantity' => 3, 'status' => 'pending', 'entered_by' => $engineer->id]);

        $this->assertFalse(BoqMeasurement::canBeReviewedBy($engineer, $theirs));
        $this->assertTrue(BoqMeasurement::canBeReviewedBy($this->admin, $theirs));
    }

    public function test_import_buttons_accept_an_uploaded_file(): void
    {
        Livewire::test(ManageBoqMasterItems::class)
            ->callAction('import', ['file' => UploadedFile::fake()->createWithContent('library.csv', "Code,Description,Unit,Rate\nRCC-01,RCC M20,m³,15000")])
            ->assertHasNoActionErrors();

        $this->assertSame('15000.00', BoqMasterItem::query()->where('code', 'RCC-01')->sole()->default_rate);

        Livewire::test(BoqItemsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->callTableAction('importBoq', data: ['file' => UploadedFile::fake()->createWithContent('boq.csv', "Code,Qty,Rate\nRCC-01,12.5,15500")])
            ->assertHasNoTableActionErrors();

        $line = $this->project->boqItems()->sole();
        $this->assertSame('12.500', $line->quantity);
        $this->assertSame('193750.00', $line->planned_value);
    }

    public function test_exports_can_be_imported_back(): void
    {
        $master = BoqMasterItem::create(['code' => 'TILE-001', 'description' => 'Vitrified tiles, 600×600', 'unit' => 'm²', 'default_rate' => 2100, 'category' => 'flooring']);
        $this->project->boqItems()->create(['master_item_id' => $master->id, 'code' => 'TILE-001', 'description' => $master->description, 'unit' => 'm²', 'quantity' => 27.88, 'rate' => 2200]);

        $library = Livewire::test(ManageBoqMasterItems::class)->callAction('export');
        $library->assertFileDownloaded('boq-library-'.now()->format('Y-m-d').'.csv');

        ob_start();
        BoqSheet::exportProject($this->project)->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Vitrified tiles, 600×600', $csv);
        $this->assertStringContainsString('27.88', $csv);
        $this->assertStringContainsString('61336', $csv);

        // Re-importing the export into another project reproduces the BOQ.
        $other = $this->project->replicate();
        $other->title = 'House – Itahari';
        $other->save();

        BoqSheet::importProject($other, BoqSheet::read($this->csv($csv)), $this->admin);

        $copy = $other->boqItems()->sole();
        $this->assertSame($master->id, $copy->master_item_id);
        $this->assertSame('2200.00', $copy->rate);
    }
}
