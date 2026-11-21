<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('batchId', 128)->index('idx_batches_batchId');
            $table->string('date', 80)->index('idx_batches_date');
            $table->string('project', 255)->nullable();
            $table->string('sku', 200);
            $table->string('brand', 120)->index('idx_batches_brand');
            $table->integer('qty');
            $table->text('specs')->nullable();
            $table->integer('pinLength');
            $table->string('status', 40);
            $table->tinyInteger('isB2B')->default(0);
            $table->string('redirectUrl', 500)->default('https://cek.sumas.id/');
            $table->string('qrType', 20)->default('dynamic');
            $table->tinyInteger('requirePin')->default(1);
            $table->tinyInteger('enableGps')->default(1);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('batchId', 128)->index('idx_tags_batchId');
            $table->string('tagNumber', 128)->index('idx_tags_tagNumber');
            $table->string('pin', 32)->index('idx_tags_pin');
            $table->string('status', 40)->index('idx_tags_status');
            $table->string('project', 255)->nullable();
            $table->text('specs')->nullable();
            $table->string('codeType', 120)->nullable();
            $table->string('warranty', 120)->nullable();
            $table->string('prodCode', 120)->nullable();
            $table->text('note')->nullable();
            $table->string('allocatedAt', 80)->nullable();

            $table->index(['batchId', 'status', 'tagNumber'], 'idx_tags_search');
        });

        Schema::create('scan_points', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('city', 120);
            $table->string('province', 120);
            $table->string('venue', 255)->nullable();
            $table->double('lat');
            $table->double('lng');
            $table->integer('totalScans');
            $table->integer('todayScans');
            $table->string('brand', 120)->index('idx_scan_points_brand');
            $table->string('productType', 200)->nullable();
            $table->string('batchId', 128)->nullable();
            $table->string('latestTagId', 128)->nullable()->index('idx_scan_points_tagId');
            $table->string('pin', 32)->nullable()->index('idx_scan_points_pin');
            $table->string('latestTime', 80)->nullable();
            $table->tinyInteger('isB2B')->default(0);
            $table->string('status', 40)->default('valid')->index('idx_scan_points_status');
            $table->text('anomalyReason')->nullable();
            $table->string('deviceInfo', 255)->nullable();
            $table->longText('scanHistory')->nullable();
            $table->string('createdAt', 80)->nullable();
        });

        Schema::create('scan_activities', function (Blueprint $table) {
            $table->id();
            $table->string('rangeType', 16);
            $table->string('label', 64);
            $table->string('fullDate', 120);
            $table->integer('scans');
            $table->integer('valid');
            $table->string('topBrand', 120);
            $table->integer('sortOrder');

            $table->index(['rangeType', 'sortOrder'], 'idx_scan_activities_range');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('batchId', 128)->index('idx_audit_logs_batchId');
            $table->string('timestamp', 80);
            $table->string('user', 120);
            $table->text('reason');
            $table->longText('changes');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('name', 120);
            $table->string('username', 80)->nullable()->index('idx_users_username');
            $table->string('email', 160)->index('idx_users_email');
            $table->string('phone', 40)->nullable();
            $table->string('role', 64);
            $table->longText('avatar')->nullable();
            $table->string('status', 32)->default('Aktif');
            $table->string('createdAt', 80)->nullable();
            $table->string('password', 255)->nullable();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('name', 120);
            $table->longText('logo')->nullable();
            $table->string('ownerId', 128)->index('idx_brands_ownerId');
            $table->text('description')->nullable();
            $table->string('category', 160)->nullable();
            $table->string('businessType', 32)->default('Retail');
            $table->string('status', 32);
            $table->string('createdAt', 80);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('brandId', 128)->nullable()->index('idx_products_brandId');
            $table->string('brandName', 120)->index('idx_products_brandName');
            $table->string('sku', 200)->index('idx_products_sku');
            $table->string('category', 160)->nullable();
            $table->tinyInteger('isB2B')->default(0);
            $table->text('description')->nullable();
            $table->string('createdAt', 80)->nullable();
            $table->string('skuCode', 80)->nullable();
            $table->longText('image')->nullable();
        });

        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('userId', 128)->nullable()->index('idx_pr_userId');
            $table->string('requestedAt', 80);
            $table->string('status', 32)->default('Menunggu');
            $table->text('actionNote')->nullable();
            $table->bigInteger('createdAtTs')->nullable()->index('idx_pr_created');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('type', 40);
            $table->string('severity', 20)->default('info');
            $table->string('audience', 32)->default('brand_scope');
            $table->string('targetUserId', 128)->nullable();
            $table->string('brandName', 120)->nullable();
            $table->string('brandId', 128)->nullable();
            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('entityType', 64)->nullable();
            $table->string('entityId', 128)->nullable();
            $table->string('status', 40)->default('info');
            $table->longText('metadata')->nullable();
            $table->string('dedupeKey', 191)->nullable();
            $table->bigInteger('createdAtTs')->index('idx_notifications_created');

            $table->index(['audience', 'createdAtTs'], 'idx_notifications_audience');
            $table->index(['entityType', 'entityId'], 'idx_notifications_entity');
            $table->index(['dedupeKey', 'createdAtTs'], 'idx_notifications_dedupe');
        });

        Schema::create('read_notifications', function (Blueprint $table) {
            $table->string('userId', 128);
            $table->string('notificationId', 128);
            $table->string('readAt', 80);

            $table->primary(['userId', 'notificationId']);
            $table->index('userId', 'idx_read_notifications_user');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->string('name', 120);
            $table->string('parentId', 128)->nullable()->index('idx_categories_parent');
            $table->integer('level')->index('idx_categories_level');
            $table->string('createdAt', 80);
            $table->longText('specSchema')->nullable();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 128)->primary();
            $table->longText('value');
            $table->string('updatedAt', 80);
        });

        Schema::create('server_secrets', function (Blueprint $table) {
            $table->string('key', 128)->primary();
            $table->text('value');
            $table->string('createdAt', 80);
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->string('tokenHash', 128)->primary();
            $table->string('userId', 128)->index('idx_user_sessions_userId');
            $table->bigInteger('createdAt');
            $table->bigInteger('expiresAt')->index('idx_user_sessions_expiresAt');
            $table->string('ipAddress', 80)->nullable();
            $table->string('userAgent', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('server_secrets');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('read_notifications');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('users');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('scan_activities');
        Schema::dropIfExists('scan_points');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('batches');
    }
};
