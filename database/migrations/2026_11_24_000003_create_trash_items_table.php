<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('trash_items')) {
            Schema::create('trash_items', function (Blueprint $table) {
                $table->string('id', 128)->primary();
                $table->string('entityType', 40)->index('idx_trash_entity_type');
                $table->string('entityId', 128)->index('idx_trash_entity_id');
                $table->string('title', 255);
                $table->string('subtitle', 255)->nullable();
                $table->string('brandName', 120)->nullable()->index('idx_trash_brand');
                $table->string('brandId', 128)->nullable();
                $table->string('deletedById', 128)->nullable();
                $table->string('deletedByName', 120)->nullable();
                $table->string('deletedAt', 80);
                $table->bigInteger('deletedAtTs')->index('idx_trash_deleted_at');
                $table->bigInteger('expiresAtTs')->index('idx_trash_expires_at');
                $table->integer('itemCount')->default(1);
                $table->longText('payload');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trash_items');
    }
};
