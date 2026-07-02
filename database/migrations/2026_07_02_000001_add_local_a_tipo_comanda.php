<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Pagar después" también en el local: el pedido pendiente genera comanda
 * (la cocina necesita el ticket físico con lo que va a preparar), así que
 * el CHECK de comandas.tipo debe aceptar 'local' además de llevar/domicilio.
 *
 * Postgres: enum() de Laravel es varchar + CHECK; se recrea el constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE comandas DROP CONSTRAINT IF EXISTS comandas_tipo_check');
        DB::statement("ALTER TABLE comandas ADD CONSTRAINT comandas_tipo_check CHECK (tipo::text = ANY (ARRAY['local'::text, 'llevar'::text, 'domicilio'::text]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE comandas DROP CONSTRAINT IF EXISTS comandas_tipo_check');
        DB::statement("ALTER TABLE comandas ADD CONSTRAINT comandas_tipo_check CHECK (tipo::text = ANY (ARRAY['llevar'::text, 'domicilio'::text]))");
    }
};
