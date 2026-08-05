<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_files', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('user_code');
            $table->uuid('parent_code')->nullable();
            $table->string('name', 160);
            $table->string('type', 3);
            $table->bigInteger('size')->default(0);
            $table->string('storage_path')->nullable();
            $table->string('mime_type', 255)->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_code', 'parent_code', 'deleted_at']);
            $table->foreign('user_code')
                ->references('code')
                ->on('users')
                ->restrictOnDelete();
        });

        // Self-reference: the primary key must exist before the constraint.
        Schema::table('drive_files', function (Blueprint $table) {
            $table->foreign('parent_code')
                ->references('code')
                ->on('drive_files')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE drive_files
            ADD CONSTRAINT drive_files_name_not_blank_check
            CHECK (btrim(name) <> ''),
            ADD CONSTRAINT drive_files_type_check
            CHECK (type IN ('dir', 'img', 'vid', 'aud', 'doc', 'zip', 'otr')),
            ADD CONSTRAINT drive_files_size_check
            CHECK (size >= 0),
            ADD CONSTRAINT drive_files_directory_has_no_blob_check
            CHECK ((type = 'dir') = (storage_path IS NULL)),
            ADD CONSTRAINT drive_files_directory_size_check
            CHECK (type <> 'dir' OR size = 0)
            SQL);

        // A name is unique among live siblings; trashed rows never block a new name.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX drive_files_root_name_unique
            ON drive_files (user_code, lower(name))
            WHERE parent_code IS NULL AND deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX drive_files_child_name_unique
            ON drive_files (parent_code, lower(name))
            WHERE parent_code IS NOT NULL AND deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX drive_files_owner_recent_idx
            ON drive_files (user_code, updated_at DESC)
            WHERE deleted_at IS NULL
            SQL);

        Schema::create('drive_shares', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('file_code');
            $table->uuid('shared_with_user_code');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['file_code', 'shared_with_user_code']);
            $table->index('shared_with_user_code');
            $table->foreign('file_code')
                ->references('code')
                ->on('drive_files')
                ->cascadeOnDelete();
            $table->foreign('shared_with_user_code')
                ->references('code')
                ->on('users')
                ->cascadeOnDelete();
        });

        // Folder-graph reads. The recursion belongs to PostgreSQL; Laravel still
        // owns ownership, filters, and the response shape.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION drive_file_subtree(root_code uuid)
            RETURNS TABLE (code uuid, storage_path text)
            LANGUAGE sql
            STABLE
            AS $$
                WITH RECURSIVE tree AS (
                    SELECT f.code, f.storage_path
                    FROM drive_files f
                    WHERE f.code = root_code
                    UNION ALL
                    SELECT child.code, child.storage_path
                    FROM drive_files child
                    INNER JOIN tree ON child.parent_code = tree.code
                )
                SELECT tree.code, tree.storage_path::text FROM tree
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION drive_file_contains(root_code uuid, candidate_code uuid)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            AS $$
                WITH RECURSIVE chain AS (
                    SELECT f.code, f.parent_code
                    FROM drive_files f
                    WHERE f.code = candidate_code
                    UNION ALL
                    SELECT parent.code, parent.parent_code
                    FROM drive_files parent
                    INNER JOIN chain ON parent.code = chain.parent_code
                )
                SELECT EXISTS (SELECT 1 FROM chain WHERE chain.code = root_code)
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION drive_folder_path(node_code uuid)
            RETURNS TABLE (code uuid, name text)
            LANGUAGE sql
            STABLE
            AS $$
                WITH RECURSIVE chain AS (
                    SELECT f.code, f.parent_code, f.name, 0 AS depth
                    FROM drive_files f
                    WHERE f.code = node_code
                    UNION ALL
                    SELECT parent.code, parent.parent_code, parent.name, chain.depth + 1
                    FROM drive_files parent
                    INNER JOIN chain ON parent.code = chain.parent_code
                )
                SELECT chain.code, chain.name::text FROM chain ORDER BY chain.depth DESC
            $$
            SQL);

        // Skipping the excluded folder in both branches drops its descendants
        // too, so a folder is never offered a destination inside itself.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION drive_folder_options(owner_code uuid, exclude_code uuid)
            RETURNS TABLE (code uuid, label text)
            LANGUAGE sql
            STABLE
            AS $$
                WITH RECURSIVE folders AS (
                    SELECT f.code, f.name::text AS label
                    FROM drive_files f
                    WHERE f.user_code = owner_code
                        AND f.type = 'dir'
                        AND f.deleted_at IS NULL
                        AND f.parent_code IS NULL
                        AND f.code IS DISTINCT FROM exclude_code
                    UNION ALL
                    SELECT child.code, folders.label || ' / ' || child.name
                    FROM drive_files child
                    INNER JOIN folders ON child.parent_code = folders.code
                    WHERE child.type = 'dir'
                        AND child.deleted_at IS NULL
                        AND child.code IS DISTINCT FROM exclude_code
                )
                SELECT folders.code, folders.label FROM folders ORDER BY lower(folders.label)
            $$
            SQL);

        // A share covers the node it points at and everything below it, so one
        // grant on a folder reaches its whole subtree without extra rows.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION drive_file_shared_with(node_code uuid, viewer_code uuid)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            AS $$
                SELECT EXISTS (
                    SELECT 1
                    FROM drive_shares s
                    WHERE s.shared_with_user_code = viewer_code
                        AND s.file_code IN (SELECT code FROM drive_folder_path(node_code))
                )
            $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS drive_file_shared_with(uuid, uuid)');
        DB::statement('DROP FUNCTION IF EXISTS drive_folder_options(uuid, uuid)');
        DB::statement('DROP FUNCTION IF EXISTS drive_folder_path(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS drive_file_contains(uuid, uuid)');
        DB::statement('DROP FUNCTION IF EXISTS drive_file_subtree(uuid)');
        Schema::dropIfExists('drive_shares');
        Schema::dropIfExists('drive_files');
    }
};
