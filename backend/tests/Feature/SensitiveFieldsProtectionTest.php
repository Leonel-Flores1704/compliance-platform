<?php

use App\Http\Resources\UserResource;
use App\Models\ComplianceRequirement;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    $this->organization = Organization::query()->create([
        'legal_name' => 'Acme Compliance Corp',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 50,
        'business_activities' => ['51'],
        'is_active' => true,
    ]);

    $this->admin = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Admin User',
        'email' => 'admin@compliance.test',
        'password' => 'admin-secure-pass',
        'phone' => '8112345678',
        'role' => 'administrator',
        'is_active' => true,
    ]);

    $this->complianceStaff = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Compliance Staff User',
        'email' => 'staff@compliance.test',
        'password' => 'staff-secure-pass',
        'phone' => '8198765432',
        'role' => 'compliance_staff',
        'is_active' => true,
    ]);

    $this->generalEmployee = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'General Employee User',
        'email' => 'employee@compliance.test',
        'password' => 'employee-secure-pass',
        'phone' => '8187654321',
        'role' => 'general_employee',
        'is_active' => true,
    ]);

    $this->requirement = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Data Privacy Policy Audit',
        'description' => 'Review of data retention and encryption mechanisms.',
        'regulation' => 'NFR-001',
        'deadline' => '2026-12-31',
        'responsible_user_id' => $this->complianceStaff->id,
        'status' => 'compliant',
        'applicability_criteria' => ['industry' => 'technology'],
        'applies' => true,
        'notification_periods' => [30, 15, 7],
        'created_by' => $this->admin->id,
    ]);
});

it('creates a user with phone "8112345678" and asserts the raw database value is different and the model value equals it', function (): void {
    $rawPhone = DB::table('users')->where('id', $this->admin->id)->value('phone');

    expect($rawPhone)->not->toBe('8112345678')
        ->and($rawPhone)->toBeString()
        ->and($rawPhone)->toStartWith('eyJ')
        ->and($this->admin->fresh()->phone)->toBe('8112345678');
});

it('hashes user passwords and does not expose password or phone in model serialization', function (): void {
    $rawPassword = DB::table('users')->where('id', $this->admin->id)->value('password');

    expect($rawPassword)->not->toBe('admin-secure-pass')
        ->and(Hash::check('admin-secure-pass', $rawPassword))->toBeTrue();

    $serialized = $this->admin->toArray();
    expect($serialized)->not->toHaveKey('password')
        ->and($serialized)->not->toHaveKey('phone')
        ->and($serialized)->toHaveKey('email');
});

it('returns 401 when unauthenticated users request GET /api/users', function (): void {
    $this->getJson('/api/users')
        ->assertStatus(401);
});

it('returns 403 when compliance staff requests GET /api/users', function (): void {
    $this->actingAs($this->complianceStaff)
        ->getJson('/api/users')
        ->assertStatus(403);
});

it('returns 403 when general employee requests GET /api/users', function (): void {
    $this->actingAs($this->generalEmployee)
        ->getJson('/api/users')
        ->assertStatus(403);
});

it('returns 200 with phone masked as "*** *** 5678" when administrator requests GET /api/users', function (): void {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/users')
        ->assertOk();

    $users = $response->json('data') ?? $response->json();

    $adminRecord = collect($users)->firstWhere('email', 'admin@compliance.test');

    expect($adminRecord)->not->toBeNull()
        ->and($adminRecord['phone'])->toBe('*** *** 5678')
        ->and($adminRecord)->not->toHaveKey('password');
});

it('protects responsible_user contact details returning only name to general employees', function (): void {
    $response = $this->actingAs($this->generalEmployee)
        ->getJson("/api/compliance-requirements/{$this->requirement->id}")
        ->assertOk();

    $data = $response->json('data') ?? $response->json();
    $responsible = $data['responsible_user'];

    expect($responsible)->not->toBeNull()
        ->and($responsible['name'])->toBe('Compliance Staff User')
        ->and($responsible)->not->toHaveKey('email')
        ->and($responsible)->not->toHaveKey('phone');
});

it('allows compliance staff and administrators to see responsible_user contact info with masked phone', function (): void {
    $response = $this->actingAs($this->complianceStaff)
        ->getJson("/api/compliance-requirements/{$this->requirement->id}")
        ->assertOk();

    $data = $response->json('data') ?? $response->json();
    $responsible = $data['responsible_user'];

    expect($responsible)->not->toBeNull()
        ->and($responsible['name'])->toBe('Compliance Staff User')
        ->and($responsible['email'])->toBe('staff@compliance.test')
        ->and($responsible['phone'])->toBe('*** *** 5432');
});

it('masks phone numbers accurately in UserResource for various formats', function (): void {
    expect(UserResource::maskPhone('8112345678'))->toBe('*** *** 5678')
        ->and(UserResource::maskPhone('8112341234'))->toBe('*** *** 1234')
        ->and(UserResource::maskPhone('+52 (81) 1234-5678'))->toBe('*** *** 5678')
        ->and(UserResource::maskPhone(null))->toBeNull();
});

it('ensures log files contain no email, phone or password values', function (): void {
    $logPath = storage_path('logs/laravel.log');

    // Clear existing log file if present
    if (File::exists($logPath)) {
        File::put($logPath, '');
    }

    $sensitiveEmail = 'leak_test_user@compliance.test';
    $sensitivePhone = '8115559999';
    $sensitivePassword = 'super-secret-password-xyz';

    Log::channel('single')->info('User registration event', [
        'email' => $sensitiveEmail,
        'phone' => $sensitivePhone,
        'password' => $sensitivePassword,
        'note' => "Contact phone: {$sensitivePhone} and email {$sensitiveEmail}",
    ]);

    expect(File::exists($logPath))->toBeTrue();
    $logContents = File::get($logPath);

    expect($logContents)->not->toContain($sensitiveEmail)
        ->and($logContents)->not->toContain($sensitivePhone)
        ->and($logContents)->not->toContain($sensitivePassword);

    // Verify grep check returns 0 matches for sensitive values
    $escapedEmail = escapeshellarg($sensitiveEmail);
    $escapedPhone = escapeshellarg($sensitivePhone);
    $escapedPass = escapeshellarg($sensitivePassword);
    $escapedLogPath = escapeshellarg($logPath);

    $command = "grep -E \"({$sensitiveEmail}|{$sensitivePhone}|{$sensitivePassword})\" {$escapedLogPath}";
    exec($command, $output, $resultCode);

    // grep exits with 1 when no line matches
    expect($resultCode)->toBe(1)
        ->and($output)->toBeEmpty();
});

it('decrypts encrypted attributes using previous keys when APP_PREVIOUS_KEYS is configured', function (): void {
    // Key 1 (old key)
    $oldKey = 'base64:'.base64_encode(random_bytes(32));
    $oldEncrypter = new Encrypter(
        base64_decode(substr($oldKey, 7)),
        'AES-256-CBC'
    );

    $plainPhone = '8112345678';
    $cipherText = $oldEncrypter->encryptString($plainPhone);

    // New active key
    $newKey = 'base64:'.base64_encode(random_bytes(32));

    // Multi-key encrypter with previous keys
    $multiKeyEncrypter = new Encrypter(
        base64_decode(substr($newKey, 7)),
        'AES-256-CBC'
    );
    $multiKeyEncrypter->previousKeys([
        base64_decode(substr($oldKey, 7)),
    ]);

    $decrypted = $multiKeyEncrypter->decryptString($cipherText);

    expect($decrypted)->toBe($plainPhone);
});
