<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Compras gravadas → crédito fiscal (ISV) que se resta del ISV de ventas.
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha');
            $table->string('numero_factura', 50);
            $table->string('proveedor_nombre');
            $table->string('proveedor_rtn', 14)->nullable();

            $table->enum('categoria', ['insumos', 'empaques', 'equipo', 'servicios', 'limpieza', 'otros'])
                ->default('otros');

            // Desglose: el ISV es el crédito fiscal de esta compra.
            $table->decimal('exento', 12, 2)->default(0);
            $table->decimal('gravado', 12, 2)->default(0);
            $table->decimal('isv', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            $table->string('notas')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Reportes del contador y libro de compras por período.
            $table->index(['fecha']);
            $table->index(['proveedor_rtn']);
        });

        // El período guarda también el crédito y el neto a pagar.
        Schema::table('periodos_fiscales', function (Blueprint $table): void {
            $table->decimal('credito_fiscal', 14, 2)->default(0)->after('isv');
            $table->decimal('isv_a_pagar', 14, 2)->default(0)->after('credito_fiscal');
        });
    }

    public function down(): void
    {
        Schema::table('periodos_fiscales', function (Blueprint $table): void {
            $table->dropColumn(['credito_fiscal', 'isv_a_pagar']);
        });

        Schema::dropIfExists('compras');
    }
};
