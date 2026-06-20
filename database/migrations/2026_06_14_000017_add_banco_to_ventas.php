<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Banco de la transferencia (solo cuando forma_pago = transferencia).
        Schema::table('ventas', function (Blueprint $table): void {
            $table->string('banco')->nullable()->after('forma_pago');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropColumn('banco');
        });
    }
};
