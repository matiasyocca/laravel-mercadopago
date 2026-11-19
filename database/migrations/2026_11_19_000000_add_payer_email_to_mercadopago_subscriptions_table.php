<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercadopago_subscriptions', function (Blueprint $table): void {
            // The email the checkout was created for: Mercado Pago only lets that payer authorize it.
            $table->string('payer_email')->nullable()->after('init_point');
        });
    }

    public function down(): void
    {
        Schema::table('mercadopago_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('payer_email');
        });
    }
};
