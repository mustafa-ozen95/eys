<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denetim zincirinin MÜHÜRLÜ durumu: çapa ve uç.
 *
 * Çapa (budamadan sonra zincirin başladığı özet) yalnızca önbellekte
 * duruyordu; önbellek kalıcı değil (gömülü redis diske yazmıyor) ve
 * yeniden başlatma sağlam zinciri "kırık" gösteriyordu. Uç (son özet, satır
 * sayısı) hiç tutulmuyordu; zincirin SONUNDAKİ satırları silmek doğrulamayı
 * bozmuyordu.
 *
 * Satır YOK iken doğrudan oluşturulmaz: ilk kullanımda mevcut zincirden
 * türetilir (bkz. ActivityChain::durum), böylece mevcut satırlar
 * doğrulanmaya devam eder. Satırın kendisi zincir anahtarıyla imzalıdır
 * (mac); veritabanına yazabilen biri anahtarsız uç/çapa değiştiremez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_chain_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('anchor_hash', 64)->nullable();
            $table->unsignedBigInteger('head_id')->nullable();
            $table->string('head_hash', 64)->nullable();
            $table->unsignedBigInteger('rows')->default(0);
            $table->string('mac', 64);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_chain_state');
    }
};
