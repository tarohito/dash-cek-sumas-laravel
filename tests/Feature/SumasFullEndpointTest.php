<?php

namespace Tests\Feature;

use Database\Seeders\SumasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SumasFullEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SumasSeeder::class);
    }

    /**
     * @return array{cookie: string, csrf: string, user: array<string, mixed>}
     */
    private function loginAs(string $identifier, string $password): array
    {
        $res = $this->postJson('/api/auth/login', [
            'identifier' => $identifier,
            'password' => $password,
            'rememberMe' => true,
        ]);

        $res->assertStatus(200)
            ->assertJson(['success' => true]);

        $sessionCookie = $res->getCookie('sumas_session', false);
        $this->assertNotNull($sessionCookie, 'Expected sumas_session cookie on login');

        return [
            'cookie' => (string) $sessionCookie->getValue(),
            'csrf' => (string) $res->json('csrfToken'),
            'user' => (array) $res->json('user'),
        ];
    }

    /**
     * @param array{cookie: string, csrf: string} $auth
     */
    private function authedGet(array $auth, string $uri)
    {
        return $this->withCredentials()->withUnencryptedCookies([
            'sumas_session' => $auth['cookie'],
            'sumas_csrf' => $auth['csrf'],
        ])->getJson($uri);
    }

    /**
     * @param array{cookie: string, csrf: string} $auth
     * @param array<string, mixed> $payload
     */
    private function authedMutation(array $auth, string $method, string $uri, array $payload = [])
    {
        return $this->withCredentials()->withUnencryptedCookies([
            'sumas_session' => $auth['cookie'],
            'sumas_csrf' => $auth['csrf'],
        ])->withHeaders([
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Sumas-CSRF' => $auth['csrf'],
        ])->json($method, $uri, $payload);
    }

    public function test_public_routes_and_qr_verification_pages(): void
    {
        $this->get('/up')->assertStatus(200);
        $this->get('/')->assertStatus(200);
        $this->getJson('/api/auth/me')->assertStatus(200)->assertJson(['authenticated' => false]);
        $this->getJson('/api/auth/demo-accounts')->assertStatus(200);
        $this->getJson('/api/does-not-exist')->assertStatus(404)->assertJson(['success' => false]);

        // Unregistered QR tag returns 404 HTML
        $this->get('/q/NON-EXISTENT-TAG-99999')
            ->assertStatus(404)
            ->assertSee('Tag Tidak Terdaftar');

        // Registered QR tag returns 200 HTML verification page
        $sampleTag = DB::table('tags')->first();
        $this->assertNotNull($sampleTag);
        $this->get('/q/' . rawurlencode($sampleTag->tagNumber))
            ->assertStatus(200);

        // Public QR verify endpoint
        $verifyRes = $this->postJson('/api/q/' . rawurlencode($sampleTag->tagNumber) . '/verify', [
            'gpsGranted' => false,
        ]);
        $verifyRes->assertStatus(200);
    }

    public function test_authentication_csrf_and_logout_lifecycle(): void
    {
        // Wrong password fails with 401
        $this->postJson('/api/auth/login', [
            'identifier' => 'kalinggajeandy3@gmail.com',
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJson(['success' => false]);

        // Protected route without session returns 401
        $this->getJson('/api/dashboard/stats')->assertStatus(401);

        // Valid login works
        $auth = $this->loginAs('kalinggajeandy3@gmail.com', 'SumasPass2026!');
        $this->assertSame('Super Admin', $auth['user']['role']);

        // /api/auth/me returns authenticated user
        $this->authedGet($auth, '/api/auth/me')
            ->assertStatus(200)
            ->assertJson([
                'authenticated' => true,
                'user' => ['email' => 'kalinggajeandy3@gmail.com'],
            ]);

        // Mutating request without CSRF header is blocked (403)
        $this->withCredentials()->withUnencryptedCookies([
            'sumas_session' => $auth['cookie'],
            'sumas_csrf' => $auth['csrf'],
        ])->postJson('/api/audit-logs', [
            'batchId' => 'TEST',
            'reason' => 'No CSRF',
        ])->assertStatus(403);

        // Logout invalidates session
        $this->authedMutation($auth, 'POST', '/api/auth/logout')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->authedGet($auth, '/api/auth/me')
            ->assertStatus(200)
            ->assertJson(['authenticated' => false]);
    }

    public function test_admin_brand_sumas_isolation_and_rbac_restrictions(): void
    {
        $sumasAuth = $this->loginAs('budi.s@sumas.id', 'SumasPass2026!');
        $this->assertSame('Admin Brand', $sumasAuth['user']['role']);

        // Allowed read endpoints for Admin Brand
        $this->authedGet($sumasAuth, '/api/dashboard/stats')->assertStatus(200);
        $this->authedGet($sumasAuth, '/api/scans/stats')->assertStatus(200);
        $this->authedGet($sumasAuth, '/api/scans/activity')->assertStatus(200);
        $this->authedGet($sumasAuth, '/api/notifications')->assertStatus(200);

        // Brand isolation: Brands list only contains Sumas
        $brandsRes = $this->authedGet($sumasAuth, '/api/brands')->assertStatus(200);
        $brands = $brandsRes->json();
        $this->assertNotEmpty($brands);
        foreach ($brands as $b) {
            $this->assertSame('Sumas', $b['name'], 'Admin Brand Sumas should only see Sumas brand');
        }

        // Brand isolation: Products only belong to Sumas
        $productsRes = $this->authedGet($sumasAuth, '/api/products')->assertStatus(200);
        foreach ($productsRes->json() as $p) {
            $this->assertSame('Sumas', $p['brandName'], 'Admin Brand Sumas should only see Sumas products');
        }

        // Brand isolation: Batches only belong to Sumas
        $batchesRes = $this->authedGet($sumasAuth, '/api/batches')->assertStatus(200);
        foreach ($batchesRes->json() as $batch) {
            $this->assertSame('Sumas', $batch['brand'], 'Admin Brand Sumas should only see Sumas batches');
        }

        // Brand isolation: Scan locations only belong to Sumas
        $scansRes = $this->authedGet($sumasAuth, '/api/scans/locations')->assertStatus(200);
        foreach ($scansRes->json() as $scan) {
            $this->assertSame('Sumas', $scan['brand'], 'Admin Brand Sumas should only see Sumas scans');
        }

        // RBAC restrictions: Admin Brand calling GET /api/users only receives their own profile (never other users)
        $usersRes = $this->authedGet($sumasAuth, '/api/users')->assertStatus(200);
        $this->assertCount(1, $usersRes->json());
        $this->assertSame('budi.s@sumas.id', $usersRes->json('0.email'));

        // Admin Brand must be denied (403) on Super Admin-only endpoints
        $this->authedMutation($sumasAuth, 'POST', '/api/users', ['name' => 'Hacker', 'email' => 'h@x.id'])->assertStatus(403);
        $this->authedGet($sumasAuth, '/api/settings')->assertStatus(403);
        $this->authedGet($sumasAuth, '/api/settings/backup')->assertStatus(403);
        $this->authedMutation($sumasAuth, 'POST', '/api/brands', ['name' => 'Unauthorized Brand'])->assertStatus(403);
        $this->authedMutation($sumasAuth, 'POST', '/api/categories', ['name' => 'Unauthorized Cat', 'level' => 1])->assertStatus(403);
        $this->authedMutation($sumasAuth, 'PUT', '/api/rbac/permissions', ['Admin Brand' => ['dashboard.view']])->assertStatus(403);
    }

    public function test_super_admin_all_pages_read_endpoints(): void
    {
        $auth = $this->loginAs('kalinggajeandy3@gmail.com', 'SumasPass2026!');

        $readEndpoints = [
            '/api/dashboard/stats',
            '/api/scans/locations',
            '/api/scans/stats',
            '/api/scans/activity',
            '/api/batches',
            '/api/audit-logs',
            '/api/brands',
            '/api/categories',
            '/api/products',
            '/api/users',
            '/api/password-resets',
            '/api/notifications',
            '/api/settings',
            '/api/rbac/permissions',
            '/api/tag-layouts',
            '/api/auth/check-username?username=new_unique_user_99',
        ];

        foreach ($readEndpoints as $endpoint) {
            $this->authedGet($auth, $endpoint)
                ->assertStatus(200, "Failed GET {$endpoint}");
        }
    }

    public function test_super_admin_crud_brands_categories_products_batches_users_settings(): void
    {
        $auth = $this->loginAs('kalinggajeandy3@gmail.com', 'SumasPass2026!');

        // 1. Brand CRUD
        $brandCreate = $this->authedMutation($auth, 'POST', '/api/brands', [
            'name' => 'QA Test Brand',
            'ownerId' => 'u2',
            'category' => 'Skincare',
            'businessType' => 'Retail',
            'status' => 'Aktif',
            'description' => 'Brand uji otomatis',
        ])->assertSuccessful();
        $brandId = $brandCreate->json('brand.id') ?? $brandCreate->json('id');
        $this->assertNotEmpty($brandId);

        $this->authedMutation($auth, 'PUT', "/api/brands/{$brandId}", [
            'name' => 'QA Test Brand Updated',
            'status' => 'Aktif',
        ])->assertSuccessful();

        // 2. Category CRUD
        $catCreate = $this->authedMutation($auth, 'POST', '/api/categories', [
            'name' => 'QA Test Category',
            'level' => 1,
            'parentId' => null,
        ])->assertSuccessful();
        $catId = $catCreate->json('category.id') ?? $catCreate->json('id');
        $this->assertNotEmpty($catId);

        $this->authedMutation($auth, 'PUT', "/api/categories/{$catId}", [
            'name' => 'QA Test Category Updated',
        ])->assertSuccessful();

        $this->authedMutation($auth, 'PUT', "/api/categories/{$catId}/specs", [
            'specSchema' => [
                ['key' => 'volume', 'label' => 'Volume', 'type' => 'text', 'required' => false],
            ],
        ])->assertSuccessful();

        // 3. Product / SKU CRUD
        $prodCreate = $this->authedMutation($auth, 'POST', '/api/products', [
            'brandId' => $brandId,
            'brandName' => 'QA Test Brand Updated',
            'sku' => 'QA Serum Glow 30ml',
            'skuCode' => 'QA-SRM-030',
            'category' => 'QA Test Category Updated',
            'isB2B' => false,
            'description' => 'Produk uji otomatis',
        ])->assertSuccessful();
        $prodId = $prodCreate->json('product.id') ?? $prodCreate->json('id');
        $this->assertNotEmpty($prodId);

        $this->authedGet($auth, "/api/products/{$prodId}/relational")->assertStatus(200);
        $this->authedMutation($auth, 'PUT', "/api/products/{$prodId}", [
            'sku' => 'QA Serum Glow 50ml',
            'brandId' => $brandId,
            'brandName' => 'QA Test Brand Updated',
        ])->assertSuccessful();

        // 4. Batch & Tag Operations
        $batchCreate = $this->authedMutation($auth, 'POST', '/api/batches', [
            'batchId' => 'BATCH-QA-001',
            'brand' => 'QA Test Brand Updated',
            'sku' => 'QA Serum Glow 50ml',
            'qty' => 5,
            'pinLength' => 6,
            'isB2B' => true,
            'redirectUrl' => 'https://cek.sumas.id/',
            'qrType' => 'dynamic',
            'requirePin' => true,
            'enableGps' => true,
        ])->assertSuccessful();
        $batchId = $batchCreate->json('batch.id') ?? $batchCreate->json('id');
        $this->assertNotEmpty($batchId);

        $tagsRes = $this->authedGet($auth, "/api/batches/{$batchId}/tags")->assertStatus(200);
        $tags = $tagsRes->json('data') ?? $tagsRes->json('tags') ?? $tagsRes->json();
        $this->assertNotEmpty($tags);

        $this->authedGet($auth, "/api/batches/{$batchId}/summary")->assertStatus(200);
        $this->authedMutation($auth, 'PATCH', "/api/batches/{$batchId}/redirect-url", [
            'redirectUrl' => 'https://cek.maklonkosmetik.id/',
        ])->assertSuccessful();

        // 5. User CRUD
        $userCreate = $this->authedMutation($auth, 'POST', '/api/users', [
            'name' => 'QA Brand Admin',
            'username' => 'qa.brand.admin',
            'email' => 'qa.admin@sumas.id',
            'phone' => '081234567890',
            'role' => 'Admin Brand',
            'status' => 'Aktif',
            'password' => 'QaPassword2026!',
        ])->assertSuccessful();
        $userId = $userCreate->json('user.id') ?? $userCreate->json('id');
        $this->assertNotEmpty($userId);

        $this->authedMutation($auth, 'PUT', "/api/users/{$userId}", [
            'name' => 'QA Brand Admin Edited',
            'role' => 'Admin Brand',
            'status' => 'Aktif',
        ])->assertSuccessful();

        // 6. Settings & Backup
        $settings = $this->authedGet($auth, '/api/settings')->assertStatus(200)->json();
        $this->authedMutation($auth, 'PUT', '/api/settings', $settings)
            ->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->authedGet($auth, '/api/settings/backup')->assertStatus(200);

        // 7. Cleanup created records
        $this->authedMutation($auth, 'DELETE', "/api/batches/{$batchId}")->assertSuccessful();
        $this->authedMutation($auth, 'DELETE', "/api/products/{$prodId}")->assertSuccessful();
        $this->authedMutation($auth, 'DELETE', "/api/categories/{$catId}")->assertSuccessful();
        $this->authedMutation($auth, 'DELETE', "/api/brands/{$brandId}")->assertSuccessful();
        $this->authedMutation($auth, 'DELETE', "/api/users/{$userId}")->assertSuccessful();
    }

    public function test_rapid_duplicate_audit_log_and_notification_are_deduplicated(): void
    {
        $auth = $this->loginAs('kalinggajeandy3@gmail.com', 'SumasPass2026!');
        $sampleBatch = DB::table('batches')->first();
        $this->assertNotNull($sampleBatch);

        $reason = 'Membatalkan alokasi proyek: PIK 2 Central Market';
        $changes = [
            ['field' => 'Alokasi Proyek', 'old' => 'PIK 2 Central Market', 'new' => 'Tersedia Kembali'],
        ];

        $beforeLogs = DB::table('audit_logs')
            ->where('batchId', $sampleBatch->batchId)
            ->where('reason', $reason)
            ->count();
        $beforeNotifs = DB::table('notifications')
            ->where('title', "Pengeditan Data: {$sampleBatch->batchId}")
            ->count();

        // Simulate user rapidly clicking 10x on a slow connection with a client clock skewed 1 hour behind server
        $skewedClientId = (time() - 3600) * 1000;
        for ($i = 0; $i < 10; $i++) {
            $this->authedMutation($auth, 'POST', '/api/audit-logs', [
                'id' => $skewedClientId + $i,
                'batchId' => $sampleBatch->batchId,
                'reason' => $reason,
                'changes' => $changes,
            ])->assertSuccessful();
        }

        $afterLogs = DB::table('audit_logs')
            ->where('batchId', $sampleBatch->batchId)
            ->where('reason', $reason)
            ->count();
        $afterNotifs = DB::table('notifications')
            ->where('title', "Pengeditan Data: {$sampleBatch->batchId}")
            ->count();

        $this->assertSame($beforeLogs + 1, $afterLogs, 'Rapid 10x clicks must only create 1 audit log even with client clock skew');
        $this->assertSame($beforeNotifs + 1, $afterNotifs, 'Rapid 10x clicks must only create 1 notification');

        // Verify double allocate & double deallocate are idempotent
        $b2bBatch = DB::table('batches')->where('isB2B', 1)->first();
        $this->assertNotNull($b2bBatch);
        $availTags = DB::table('tags')
            ->where('batchId', $b2bBatch->id)
            ->where('status', 'Tersedia')
            ->limit(2)
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($availTags);

        $alloc1 = $this->authedMutation($auth, 'POST', "/api/batches/{$b2bBatch->id}/allocate", [
            'tagIds' => $availTags,
            'project' => 'QA Idempotent Project',
            'specs' => 'Phenolic',
        ])->assertSuccessful();
        $this->assertSame(count($availTags), $alloc1->json('allocatedCount'));

        $alloc2 = $this->authedMutation($auth, 'POST', "/api/batches/{$b2bBatch->id}/allocate", [
            'tagIds' => $availTags,
            'project' => 'QA Idempotent Project',
            'specs' => 'Phenolic',
        ])->assertSuccessful();
        $this->assertSame(0, $alloc2->json('allocatedCount'), 'Duplicate allocate must affect 0 tags');

        $dealloc1 = $this->authedMutation($auth, 'POST', "/api/batches/{$b2bBatch->id}/deallocate", [
            'project' => 'QA Idempotent Project',
        ])->assertSuccessful();
        $this->assertSame(count($availTags), $dealloc1->json('deallocatedCount'));

        $dealloc2 = $this->authedMutation($auth, 'POST', "/api/batches/{$b2bBatch->id}/deallocate", [
            'project' => 'QA Idempotent Project',
        ])->assertSuccessful();
        $this->assertSame(0, $dealloc2->json('deallocatedCount'), 'Duplicate deallocate must affect 0 tags');
    }
}
