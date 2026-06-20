<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tier de combo en la proteína: define a qué precios de combo
        // aplica. Solo las proteínas lo usan; null en el resto.
        Schema::table('productos', function (Blueprint $table): void {
            $table->enum('tier_combo', ['pollo_cerdo', 'res'])->nullable()->after('categoria')
                ->comment('Tier de precios de combo. Solo proteínas.');
        });

        // Reglas de combo: (tier × nº de complementos) → precio. Configurable,
        // nunca hardcodeado. Ej: pollo_cerdo + 2 = 100; res + 3 = 135.
        Schema::create('combos', function (Blueprint $table): void {
            $table->id();
            $table->enum('tier', ['pollo_cerdo', 'res']);
            $table->unsignedSmallInteger('complementos');
            $table->decimal('precio', 12, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['tier', 'complementos']);
        });

        // Detalle de complementos del plato (nombres), para el ticket.
        // jsonb justificado: lista genuinamente variable por venta.
        Schema::table('venta_items', function (Blueprint $table): void {
            $table->jsonb('detalle')->nullable()->after('grava_isv')
                ->comment('Complementos del plato al momento de la venta (snapshot).');
        });
    }

    public function down(): void
    {
        Schema::table('venta_items', function (Blueprint $table): void {
            $table->dropColumn('detalle');
        });

        Schema::dropIfExists('combos');

        Schema::table('productos', function (Blueprint $table): void {
            $table->dropColumn('tier_combo');
        });
    }
};
