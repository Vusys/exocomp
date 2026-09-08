<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->renameColumn('internal_note', 'note');
            $table->dropColumn('placed_at');
            $table->index(['tenant_id', 'status']);
        });
    }
};
