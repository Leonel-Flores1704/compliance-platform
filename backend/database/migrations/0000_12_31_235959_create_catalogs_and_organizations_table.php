<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_types', function (Blueprint $table) {
            $table->string('code', 50)->primary();
            $table->string('name', 100);
        });
        Schema::create('industries', function (Blueprint $table) {
            $table->string('code', 50)->primary();
            $table->string('name', 100);
        });
        Schema::create('mexican_states', function (Blueprint $table) {
            $table->string('code', 50)->primary();
            $table->string('name', 100);
        });
        Schema::create('business_activities', function (Blueprint $table) {
            $table->string('code', 50)->primary();
            $table->string('name', 150);
        });
        Schema::create('document_categories', function (Blueprint $table) {
            $table->string('code', 50)->primary();
            $table->string('name', 100);
            $table->boolean('requires_expiration_date')->default(false);
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name', 150)->unique();
            $table->string('organization_type', 50);
            $table->string('industry', 50);
            $table->string('state', 50);
            $table->string('municipality', 100);
            $table->integer('employees_count');
            $table->jsonb('business_activities');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('organization_type')->references('code')->on('organization_types')->restrictOnDelete();
            $table->foreign('industry')->references('code')->on('industries')->restrictOnDelete();
            $table->foreign('state')->references('code')->on('mexican_states')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('document_categories');
        Schema::dropIfExists('business_activities');
        Schema::dropIfExists('mexican_states');
        Schema::dropIfExists('industries');
        Schema::dropIfExists('organization_types');
    }
};
