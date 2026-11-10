<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMP-29: store the indexing result returned by the AI service (number of chunks and pages)
 * and add the retrieval filter index from ADR-012.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->integer('chunk_count')->nullable();
            $table->integer('page_count')->nullable();
        });

        Schema::table('document_chunks', function (Blueprint $table) {
            $table->index(['organization_id', 'document_id', 'is_current'], 'document_chunks_org_document_current_idx');
        });
    }

    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropIndex('document_chunks_org_document_current_idx');
        });

        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn(['chunk_count', 'page_count']);
        });
    }
};
