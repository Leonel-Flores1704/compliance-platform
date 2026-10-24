<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['legal_name' => 'Compliance Platform Demo, S.A. de C.V.'],
            ['organization_type' => 'private_company', 'industry' => 'technology', 'state' => '09', 'municipality' => 'Cuauhtemoc', 'employees_count' => 25, 'business_activities' => ['51'], 'is_active' => true]
        );

        $password = (string) env('DEMO_USER_PASSWORD', 'password');
        foreach (['administrator', 'compliance_staff', 'general_employee'] as $role) {
            User::query()->updateOrCreate(
                ['email' => "demo.{$role}@compliance-platform.test"],
                ['organization_id' => $organization->id, 'name' => ucwords(str_replace('_', ' ', $role)), 'password' => Hash::make($password), 'role' => $role, 'is_active' => true]
            );
        }
    }
}
