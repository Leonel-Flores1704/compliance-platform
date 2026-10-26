<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('category', 50);
            $table->string('access_level', 20);
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->foreign('category')->references('code')->on('document_categories')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_access_level_check CHECK (access_level IN ('all_staff', 'compliance_only'))");

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('version_label', 20);
            $table->string('storage_path', 255);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->date('expiration_date')->nullable();
            $table->string('status', 20);
            $table->string('failure_reason', 255)->nullable();
            $table->boolean('is_current')->default(false);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unique(['document_id', 'version_label']);
        });
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_status_check CHECK (status IN ('queued', 'processing', 'processed', 'processing_failed'))");
        DB::statement('CREATE UNIQUE INDEX document_versions_one_current_per_document ON document_versions (document_id) WHERE is_current');

        Schema::create('compliance_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description');
            $table->string('regulation', 150);
            $table->date('deadline');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20);
            $table->jsonb('applicability_criteria')->nullable();
            $table->boolean('applies')->default(true);
            $table->jsonb('notification_periods');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE compliance_requirements ADD CONSTRAINT compliance_requirements_status_check CHECK (status IN ('compliant', 'pending', 'at_risk', 'overdue'))");

        Schema::create('required_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_requirement_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('category', 50);
            $table->foreignId('document_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->foreign('category')->references('code')->on('document_categories')->restrictOnDelete();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('compliance_requirement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->smallInteger('period_days');
            $table->string('message', 500);
            $table->date('due_date');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['type', 'compliance_requirement_id', 'document_version_id', 'period_days']);
        });
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_single_target_check CHECK ((compliance_requirement_id IS NOT NULL)::integer + (document_version_id IS NOT NULL)::integer = 1)');

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category', 20);
            $table->string('action', 60);
            $table->string('resource_type', 60)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->jsonb('metadata');
            $table->timestamp('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_category_check CHECK (category IN ('functional', 'security'))");

        $dimensions = (int) config('ai.embedding_dimensions', 1536);
        if ($dimensions < 1) {
            throw new RuntimeException('AI_EMBEDDING_DIMENSIONS must be a positive integer.');
        }
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();
            $table->integer('chunk_index');
            $table->text('content');
            $table->integer('page_start')->nullable();
            $table->integer('page_end')->nullable();
            $table->string('section', 255)->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['document_version_id', 'chunk_index']);
        });
        DB::statement("ALTER TABLE document_chunks ADD COLUMN embedding vector({$dimensions}) NOT NULL");
        DB::statement('CREATE INDEX document_chunks_embedding_hnsw_idx ON document_chunks USING hnsw (embedding vector_cosine_ops)');

        Schema::create('ai_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('question');
            $table->text('answer');
            $table->string('status', 30);
            $table->jsonb('citations');
            $table->integer('latency_ms');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_question_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('rating', 12);
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['ai_question_id', 'user_id']);
        });
        DB::statement("ALTER TABLE ai_feedback ADD CONSTRAINT ai_feedback_rating_check CHECK (rating IN ('useful', 'not_useful'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
        Schema::dropIfExists('ai_questions');
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('required_documents');
        Schema::dropIfExists('compliance_requirements');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
