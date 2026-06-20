<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Factura a "Consumidor Final": se emite sin RTN del cliente.
        Schema::table('facturas', function (Blueprint $table): void {
            $table->string('rtn_cliente', 14)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->string('rtn_cliente', 14)->nullable(false)->change();
        });
    }
};
