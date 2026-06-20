<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Por factura: ¿se imprime detallada o como concepto único?
        // null = usar el default de config('empresa.factura_detallada').
        Schema::table('facturas', function (Blueprint $table): void {
            $table->boolean('detallada')->nullable()->after('numero');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropColumn('detallada');
        });
    }
};
