<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->string('hash_verificacion', 64)->nullable()->unique()->after('numero')
                ->comment('HMAC-SHA256 para verificación pública de autenticidad.');
        });

        // Backfill de facturas existentes (las de prueba) con un hash válido.
        $clave = (string) config('app.key');

        DB::table('facturas')->whereNull('hash_verificacion')->orderBy('id')->each(function (object $f) use ($clave): void {
            $hash = hash_hmac('sha256', "{$f->numero}|{$f->rtn_cliente}|{$f->total}|{$f->cai_id}", $clave);
            DB::table('facturas')->where('id', $f->id)->update(['hash_verificacion' => $hash]);
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropColumn('hash_verificacion');
        });
    }
};
