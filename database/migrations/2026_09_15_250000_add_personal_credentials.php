<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * KİŞİSEL KAYITLAR.
 *
 * Kasa bugüne kadar yalnızca EKİP sırlarını tutuyordu ve yetki GRUP
 * düzeyindeydi. Amaç: kişi kendi parolasını da burada üretip saklayabilsin.
 *
 * SAHİPLİK AYRI BİR EKSEN. Grup yetkisi "bu gruptaki kayıtları kim görür"
 * sorusunu yanıtlıyor; kişisel kayıtta soru "bu kaydı kim yazdı"ya dönüyor.
 * İkisini aynı listede çözmek yerine kayda bir SAHİP veriliyor: sahibi olan
 * kaydı grup yetkisi AÇMAZ, yalnızca sahibi (ve yönetici) görür.
 *
 * GRUP SÜTUNU NEDEN DURUYOR: ekranların yarısı kaydın grubunu okuyor
 * (başlık, kenar çubuğu, denetim özeti). Sütunu boş bırakmak o yolların
 * hepsini "ya null ise" ile doldurmak olurdu. Bunun yerine tek bir taşıyıcı
 * grup var ve ADIYLA DEĞİL bir bayrakla tanınıyor (`is_personal`): grubun adı
 * değiştirilirse ya da başka bir kurulumdan yedek dönülürse kural bozulmasın.
 *
 * SAHİBİ SİLİNİRSE KAYIT SİLİNMEZ. `nullOnDelete`: sahipsiz kalan kayıt
 * yöneticide görünür kalır. Bir hesabı kapatmak, o kişinin sakladığı sırrı
 * sessizce yok etmek olmamalı — işten ayrılan birinin kaydı çoğu zaman tam
 * da o an gereklidir.
 *
 * OTOMASYON KİŞİSEL KAYITLARA DOKUNMAZ: gece koşan kasa denetimi, toplu
 * döndürme ve toplu çıktı bunları hiç görmez (bkz. Credential::scopeOtomasyon
 * ve DriverRegistry::blockedReason). Bu bilinçli — kişinin kendi parolasını
 * panel kendiliğinden değiştirmemeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credential_groups', function (Blueprint $table): void {
            $table->boolean('is_personal')->default(false)->after('name');
        });

        Schema::table('credentials', function (Blueprint $table): void {
            $table->foreignId('owner_id')->nullable()->after('credential_group_id')
                ->constrained('users')->nullOnDelete();
        });

        /* Taşıyıcı grup. Sıra en sona: kişisel alan ekip gruplarının altında. */
        if (DB::table('credential_groups')->where('is_personal', true)->doesntExist()) {
            DB::table('credential_groups')->insert([
                'uuid' => (string) Str::uuid7(),
                'name' => 'Kişisel',
                'is_personal' => true,
                'description' => 'Kendi kayıtlarınız. Yalnızca siz görürsünüz; otomasyon bunlara dokunmaz.',
                'position' => 9999,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('credentials')->whereNotNull('owner_id')->delete();
        DB::table('credential_groups')->where('is_personal', true)->delete();

        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropForeign(['owner_id']);
            $table->dropColumn('owner_id');
        });

        Schema::table('credential_groups', function (Blueprint $table): void {
            $table->dropColumn('is_personal');
        });
    }
};
