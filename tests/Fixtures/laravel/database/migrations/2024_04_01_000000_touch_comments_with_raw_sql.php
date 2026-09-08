<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable');
            $table->text('body');
        });

        // The reader can see that something happened here and cannot see what. A table whose
        // history contains one of these has a shape that is a best reading, not a fact.
        DB::statement('ALTER TABLE comments ADD FULLTEXT INDEX comments_body (body)');
    }
};
