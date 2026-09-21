<?php

namespace Database\Seeders;

use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web']
        );

        $user = User::query()->firstOrCreate(
            ['phone' => '9800000000'],
            [
                'name' => 'CivoraX Super Admin',
                'email' => 'admin@civorax.test',
                'password' => Hash::make('password'),
            ]
        );

        TeamMember::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'fullname' => 'CivoraX Super Admin',
                'contact1' => '9800000000',
                'designation' => 'System Administrator',
                'marital_status' => 'not_specified',
                'national_id_path' => 'seed/placeholder.txt',
                'bank_name' => 'N/A',
                'bank_account_name' => 'N/A',
                'bank_account_number' => 'N/A',
                'created_by' => $user->id,
            ]
        );

        $user->assignRole($role);
    }
}
