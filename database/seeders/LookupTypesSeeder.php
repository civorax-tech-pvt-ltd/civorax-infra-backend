<?php

namespace Database\Seeders;

use App\Models\ClientType;
use App\Models\InquiryType;
use App\Models\ProjectType;
use Illuminate\Database\Seeder;

class LookupTypesSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => 'Individual', 'slug' => 'individual'],
            ['name' => 'Corporate', 'slug' => 'corporate'],
            ['name' => 'Government', 'slug' => 'government'],
            ['name' => 'Developer', 'slug' => 'developer'],
        ])->each(fn (array $type) => ClientType::query()->firstOrCreate(['slug' => $type['slug']], $type));

        collect([
            ['name' => 'Residential', 'slug' => 'residential'],
            ['name' => 'Commercial', 'slug' => 'commercial'],
            ['name' => 'Interior', 'slug' => 'interior'],
            ['name' => 'Renovation', 'slug' => 'renovation'],
        ])->each(fn (array $type) => ProjectType::query()->firstOrCreate(['slug' => $type['slug']], $type));

        collect([
            ['label' => 'Client', 'slug' => 'client'],
            ['label' => 'Student', 'slug' => 'student'],
            ['label' => 'Supplier', 'slug' => 'supplier'],
            ['label' => 'Contractor', 'slug' => 'contractor'],
            ['label' => 'Worker', 'slug' => 'worker'],
            ['label' => 'Volunteer', 'slug' => 'volunteer'],
        ])->each(fn (array $type) => InquiryType::query()->firstOrCreate(['slug' => $type['slug']], $type));
    }
}
