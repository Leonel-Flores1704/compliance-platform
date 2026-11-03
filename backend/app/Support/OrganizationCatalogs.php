<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Read access to the organization catalogs seeded from backend/database/seeders/data (CMP-02, ADR-001).
 */
class OrganizationCatalogs
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    /**
     * Catalog entries as a list of ['code' => ..., 'name' => ...].
     *
     * @return list<array{code: string, name: string}>
     */
    public function list(string $table): array
    {
        return collect($this->names($table))
            ->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])
            ->values()
            ->all();
    }

    /**
     * Map of code => name for a catalog table, in display order.
     *
     * @return array<string, string>
     */
    public function names(string $table): array
    {
        return $this->cache[$table] ??= DB::table($table)
            ->select(['code', 'name'])
            ->get()
            ->sortBy(fn ($row) => match ($table) {
                // Numeric codes (INEGI states, SCIAN sectors) keep their official order.
                'mexican_states', 'business_activities' => $row->code,
                // "Other" goes last; the rest alphabetically.
                default => ($row->code === 'other' ? '1' : '0').$row->name,
            })
            ->mapWithKeys(fn ($row) => [(string) $row->code => (string) $row->name])
            ->all();
    }

    public function name(string $table, ?string $code): ?string
    {
        return $code === null ? null : ($this->names($table)[$code] ?? null);
    }

    /**
     * @return array<string, list<array{code: string, name: string}>>
     */
    public function forOrganizationForm(): array
    {
        return [
            'organization_types' => $this->list('organization_types'),
            'industries' => $this->list('industries'),
            'states' => $this->list('mexican_states'),
            'business_activities' => $this->list('business_activities'),
        ];
    }
}
