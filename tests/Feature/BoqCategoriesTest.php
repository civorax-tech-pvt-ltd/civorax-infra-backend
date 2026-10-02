<?php

namespace Tests\Feature;

use App\Filament\Resources\BoqCategoryResource;
use App\Filament\Resources\BoqMasterItemResource\Pages\ManageBoqMasterItems;
use App\Models\BoqCategory;
use App\Models\BoqMasterItem;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BoqCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
    }

    public function test_a_category_added_from_the_dropdown_is_saved_at_once_and_offered_everywhere(): void
    {
        // Added with "+" and then the item form is abandoned: the category still exists.
        Livewire::test(ManageBoqMasterItems::class)
            ->mountAction('create')
            ->callFormComponentAction('category', 'createOption', ['name' => '  Tile   work '], [], 'mountedActionForm')
            ->assertHasNoFormComponentActionErrors();

        $category = BoqCategory::query()->where('name', 'Tile work')->sole();
        $this->assertSame('tile-work', $category->key);

        // Same name with other capitals reuses it; the item stores the key and shows the name.
        Livewire::test(ManageBoqMasterItems::class)
            ->mountAction('create')
            ->setActionData(['description' => 'Floor tiles 600x600 with adhesive', 'unit' => 'm²', 'default_rate' => 1450])
            ->callFormComponentAction('category', 'createOption', ['name' => 'TILE WORK'], [], 'mountedActionForm')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1, BoqCategory::query()->where('name', 'like', 'tile work')->count());
        $item = BoqMasterItem::sole();
        $this->assertSame('tile-work', $item->category);
        $this->assertSame('Tile work', BoqMasterItem::categoryLabel($item->category));

        // New categories sit before "Other", which stays last.
        $options = BoqMasterItem::categoryOptions();
        $this->assertSame('Tile work', $options['tile-work']);
        $this->assertSame('other', array_key_last($options));

        // In use: can be renamed, not deleted.
        $this->assertTrue(BoqCategoryResource::canEdit($category));
        $this->assertFalse(BoqCategoryResource::canDelete($category));
        $this->get(BoqCategoryResource::getUrl())->assertOk()->assertSee('Tile work');
    }

    public function test_long_specifications_are_stored_in_full(): void
    {
        $spec = 'Polished Vitrified Tiles, coloured/patterned as per approved Colour Code, in dado, skirting, flooring, etc., laid with cement-sand mortar in 1:3 proportion, including joint filling with white cement slurry, cutting, fitting, fixing, leveling, alignment and cleaning, complete with smooth polished finished surface as per instructions and specifications.';

        Livewire::test(ManageBoqMasterItems::class)
            ->callAction('create', ['code' => 'TILE-001', 'description' => $spec, 'unit' => 'm²', 'default_rate' => 2100])
            ->assertHasNoActionErrors();

        $item = BoqMasterItem::sole();
        $this->assertSame($spec, $item->description);
        // The library picker shows a shortened label.
        $this->assertLessThan(160, mb_strlen($item->label()));
    }

    public function test_library_items_used_only_by_deleted_projects_can_be_deleted(): void
    {
        $item = BoqMasterItem::create(['description' => 'RCC 1:1.5:3', 'unit' => 'm³', 'default_rate' => 15000]);
        $project = Project::create([
            'client_id' => Client::create([
                'user_id' => User::factory()->create(['phone' => '9811111111'])->id, 'contact_person' => 'Shishir Sharma',
                'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
                'contact' => '9811111111', 'address' => 'Belbari',
            ])->id,
            'project_type_id' => ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true])->id,
            'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'created_by' => $this->admin->id,
        ]);
        $line = $project->boqItems()->create(['master_item_id' => $item->id, 'description' => 'RCC 1:1.5:3', 'unit' => 'm³', 'quantity' => 10, 'rate' => 15000]);

        $this->assertTrue($item->isUsed());

        $project->delete();
        $this->assertFalse($item->isUsed());

        Livewire::test(ManageBoqMasterItems::class)->callTableAction('delete', $item);

        $this->assertModelMissing($item);
        // The deleted project's own BOQ line keeps its copy, so restoring the project loses nothing.
        $this->assertSame('RCC 1:1.5:3', $line->fresh()->description);
        $this->assertNull($line->fresh()->master_item_id);
    }
}
