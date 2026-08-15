<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas prepago: empresas y personas que dejan dinero por adelantado y van
 * consumiendo contra ese saldo.
 *
 * REGLA FISCAL: el depósito NO es una venta. No consume CAI ni lleva ISV —
 * es plata del cliente que el negocio todavía debe. La factura SAR sale en
 * cada CONSUMO, por lo consumido, igual que cualquier otra venta. Facturar el
 * depósito completo cobraría ISV por comida no servida y dejaría cada plato
 * sin documento.
 *
 * `cuenta_movimientos` es un libro mayor: se agrega, nunca se borra ni se
 * edita. `monto` va CON SIGNO (depósito +, consumo −, ajuste cualquiera), de
 * modo que el saldo es literalmente la suma de los movimientos y se puede
 * reconstruir para auditarlo. `saldo_despues` guarda la foto del saldo tras
 * cada movimiento, para leer el historial sin ir sumando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_prepago', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');                  // razón social o persona
            $table->string('rtn', 14)->nullable();     // con el que se factura cada consumo
            $table->string('telefono', 20)->nullable();
            // Token del link público. No adivinable y estable: el cliente lo
            // guarda en su WhatsApp y tiene que seguir funcionando mañana.
            $table->string('token', 40)->unique();
            $table->decimal('saldo', 12, 2)->default(0);
            // Crédito OPCIONAL por cuenta (decisión 2026-08-15): por defecto no
            // se puede consumir más de lo depositado; a las empresas de
            // confianza se les habilita un tope de sobregiro.
            $table->boolean('permite_credito')->default(false);
            $table->decimal('limite_credito', 12, 2)->default(0);
            $table->boolean('activa')->default(true);
            $table->string('notas')->nullable();
            $table->timestamps();

            $table->index('activa');
            $table->index('rtn');
        });

        Schema::create('cuenta_movimientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cuenta_prepago_id')->constrained('cuentas_prepago')->cascadeOnDelete();
            $table->string('tipo', 12);                // deposito | consumo | ajuste
            $table->decimal('monto', 12, 2);           // CON SIGNO
            $table->decimal('saldo_despues', 12, 2);
            // Consumos: la factura que respalda el descuento.
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->nullOnDelete();
            // Depósitos: cómo entró la plata (efectivo | transferencia | cheque).
            $table->string('forma_pago', 15)->nullable();
            $table->string('banco')->nullable();
            $table->string('referencia', 60)->nullable();   // n.º de cheque o de transferencia
            // Un depósito EN EFECTIVO entra a la gaveta: el arqueo del turno
            // tiene que contarlo o el corte marca sobrante.
            $table->foreignId('corte_caja_id')->nullable()->constrained('corte_cajas')->nullOnDelete();
            $table->string('notas')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // El estado de cuenta se lee siempre por cuenta y en orden.
            $table->index(['cuenta_prepago_id', 'created_at']);
        });

        DB::statement("ALTER TABLE cuenta_movimientos ADD CONSTRAINT cuenta_movimientos_tipo_valido CHECK (tipo IN ('deposito', 'consumo', 'ajuste'))");
        // Un movimiento de cero no dice nada y ensucia el historial.
        DB::statement('ALTER TABLE cuenta_movimientos ADD CONSTRAINT cuenta_movimientos_monto_no_cero CHECK (monto <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cuenta_movimientos');
        Schema::dropIfExists('cuentas_prepago');
    }
};
