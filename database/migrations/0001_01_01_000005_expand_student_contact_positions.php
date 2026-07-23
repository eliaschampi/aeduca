<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE student_contacts
            DROP CONSTRAINT student_contacts_position_range_check,
            ADD CONSTRAINT student_contacts_position_positive_check
            CHECK (position > 0)
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE student_contacts
            DROP CONSTRAINT student_contacts_position_positive_check,
            ADD CONSTRAINT student_contacts_position_range_check
            CHECK (position BETWEEN 1 AND 2)
            SQL);
    }
};
