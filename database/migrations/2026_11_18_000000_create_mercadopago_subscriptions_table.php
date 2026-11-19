<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercadopago_subscriptions', function (Blueprint $table): void {
            $table->id();
            // Follows Schema::morphUsingUuids() / morphUsingUlids() when tenants use them.
            $table->morphs('billable');
            $table->string('type')->default('default');
            $table->string('plan')->nullable();
            $table->string('preapproval_id')->unique();
            $table->string('status')->nullable()->index();
            $table->text('init_point')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('next_payment_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercadopago_subscriptions');
    }
};
