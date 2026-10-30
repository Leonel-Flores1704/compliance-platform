<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalogs = [
            'organization_types' => 'organization_types.json',
            'industries' => 'industries.json',
            'mexican_states' => 'mexican_states.json',
            'business_activities' => 'business_activities.json',
            'document_categories' => 'document_categories.json',
        ];

        foreach ($catalogs as $table => $file) {
            $records = json_decode(file_get_contents(database_path("seeders/data/{$file}")), true, 512, JSON_THROW_ON_ERROR);
            $records = array_map(fn (array $record) => match ($table) {
                'document_categories' => array_intersect_key($record, array_flip(['code', 'name', 'requires_expiration_date'])),
                default => array_intersect_key($record, array_flip(['code', 'name'])),
            }, $records);
            DB::table($table)->upsert($records, ['code'], array_keys($records[0] ?? []));
        }
    }
}
