<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'shop_orders';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedInteger('total_pence')->default(0)->after('reference');
        });
    }
};
