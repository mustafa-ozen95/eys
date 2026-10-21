<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KAYIT BAZINDA ROL KISITI.
 *
 * Yetki bu panelde GRUP düzeyindedir: politika da sorgu süzmesi de kaydın
 * grubuna bakar. Gerçek hayat her zaman gruba uymuyor — aynı grupta duran iki
 * kayıttan biri bir role kapalı olmalı olabiliyor (ör. bir NAS grubundaki
 * web yönetim hesabı bir role kapalı, FTP destek hesabı açık).
 *
 * Grup düzeyini BOZMADAN bir istisna katmanı: burada satırı olan kayıt, o role
 * TÜMÜYLE kapalıdır — görüntüleme, düzenleme, silme, parola açma, oturum
 * açma, hepsi. Kısmi bir kısıt tanımlanmıyor; "bu kayıt o role ait değil"
 * demek, altı ayrı kutuyu doğru işaretlemekten hem anlaşılır hem daha
 * güvenli.
 *
 * YÖNETİCİ ETKİLENMEZ: yönetici bütün kontrolleri zaten geçiyor
 * (bkz. AppServiceProvider'daki Gate::before). Yöneticiyi buradan kısıtlamak,
 * kasayı kimsenin açamayacağı bir kutuya çevirebilirdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_role_restrictions', function (Blueprint $table): void {
            $table->id();

            /* Kayıt ya da rol silinince kısıt da gider: anlamı kalmaz. */
            $table->foreignId('credential_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            /* Aynı kayıt-rol ikilisi iki kez yazılamaz. */
            $table->unique(['credential_id', 'role_id']);

            /* Sorgu süzmesi "bu rolde kısıtlı kayıtlar" diye sorar. */
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_role_restrictions');
    }
};
