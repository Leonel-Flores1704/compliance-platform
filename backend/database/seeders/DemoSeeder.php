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
            // CMP-17 acceptance criteria use this demo organization: Manufacturing, 120 employees.
            ['organization_type' => 'private_company', 'industry' => 'manufacturing', 'state' => '10', 'municipality' => 'Durango', 'employees_count' => 120, 'business_activities' => ['31-33', '43'], 'is_active' => true]
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
