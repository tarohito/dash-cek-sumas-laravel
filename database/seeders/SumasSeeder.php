<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SumasSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/data/sumas_seed.json');
        $raw = file_get_contents($jsonPath);
        $data = json_decode($raw ?: '{}', true);

        if (!is_array($data)) {
            return;
        }

        $defaultBcrypt = Hash::make('SumasPass2026!');
        $rinaBcrypt = Hash::make('rinawijaya01');

        DB::transaction(function () use ($data, $defaultBcrypt, $rinaBcrypt) {
            if (!empty($data['users'])) {
                foreach ($data['users'] as $user) {
                    $pwd = ($user['id'] ?? '') === 'u3' ? $rinaBcrypt : $defaultBcrypt;
                    DB::table('users')->updateOrInsert(
                        ['id' => $user['id']],
                        [...$user, 'password' => $pwd]
                    );
                }
            }

            $simpleTables = [
                'batches' => 'id',
                'scan_points' => 'id',
                'scan_activities' => 'id',
                'audit_logs' => 'id',
                'brands' => 'id',
                'products' => 'id',
                'categories' => 'id',
                'system_settings' => 'key',
            ];

            foreach ($simpleTables as $table => $pk) {
                $rows = $data[$table] ?? [];
                foreach ($rows as $row) {
                    DB::table($table)->updateOrInsert(
                        [$pk => $row[$pk]],
                        $row
                    );
                }
            }

            if (!empty($data['tags']) && is_array($data['tags'])) {
                $updateCols = ['batchId', 'tagNumber', 'pin', 'status', 'project', 'specs', 'codeType', 'warranty', 'prodCode', 'note', 'allocatedAt'];
                foreach (array_chunk($data['tags'], 300) as $chunk) {
                    DB::table('tags')->upsert($chunk, ['id'], $updateCols);
                }
            }
        });
    }
}
