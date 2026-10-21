<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KAYIT BAZINDA EK İZİN — kısıtın AYNASI.
 *
 * Yetki bu panelde GRUP düzeyindedir. Kısıt tablosu grubun verdiğini tek bir
 * kayıtta geri alıyordu; bu tablo tersini yapar: grubun VERMEDİĞİNİ tek bir
 * kayıtta verir.
 *
 * Örnek: bir rol bir grupta parola görmemeli — grupta iLO kayıtları var, iLO
 * donanım konsoludur. Ama aynı gruptaki izleme (Zabbix) hesabını kullanması
 * gerekiyor. Gruba parola yetkisi vermek bütün iLO parolalarını da açardı;
 * kaydı başka bir gruba taşımak listeyi bozardı. Doğru ölçü kaydın kendisi.
 *
 * AKSİYON SÜTUNU VAR ama bugün yalnızca 'field.password' yazılıyor
 * (bkz. Credential::VERILEBILEN_AKSIYONLAR). Sebebi: ek izin, kaydı ZATEN
 * GÖREBİLEN bir rolün o kayıttaki bir alanını açmak içindir. "view" burada
 * verilemez — liste süzmesi grup yetkisine bakıyor, kayıt listede yine
 * çıkmazdı ve yarım çalışan bir izin, çalışmayan bir izinden daha kötüdür.
 *
 * KISIT BUNU YENER: aynı kayıtta hem kısıt hem ek izin varsa kayıt kapalıdır
 * (bkz. CredentialPolicy::can). Yasak, iznin önünde gelir.
 *
 * YÖNETİCİ ETKİLENMEZ: zaten bütün kontrolleri geçiyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_role_grants', function (Blueprint $table): void {
            $table->id();

            /* Kayıt ya da rol silinince izin de gider: anlamı kalmaz. */
            $table->foreignId('credential_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            /* Hangi yetenek: 'field.password' (bugün tek geçerli değer). */
            $table->string('action', 40);

            $table->timestamps();

            /* Aynı kayıt-rol-aksiyon üçlüsü iki kez yazılamaz. */
            $table->unique(['credential_id', 'role_id', 'action']);

            $table->index(['role_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_role_grants');
    }
};
