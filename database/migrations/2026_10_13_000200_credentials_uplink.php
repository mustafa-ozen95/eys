<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UPLINK ALANI: switchler için adresin yanında, aramada da bulunur.
 *
 * Değer şimdiye kadar notta "Uplink: 192.0.2.1" satırı olarak duruyordu;
 * aranamıyor ve listede görünmüyordu. Göç o satırı alana TAŞIR ve nottan
 * siler. Notta tek bir geçerli "Uplink: <adres>" satırı yoksa not olduğu gibi
 * kalır — tahminle taşınmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->string('uplink', 190)->nullable()->after('host');
        });

        /* Not şifreli saklanır ('encrypted' dönüşümü): veritabanında aranamaz, satır satır çözülür. */
        DB::table('credentials')->whereNotNull('notes')->orderBy('id')->each(function (object $kayit): void {
            try {
                $not = Crypt::decryptString((string) $kayit->notes);
            } catch (DecryptException) {
                return;
            }

            $satirlar = preg_split('/\R/u', $not) ?: [];
            $uplinkler = preg_grep('/^\s*Uplink:\s*\S+\s*$/iu', $satirlar) ?: [];

            if (count($uplinkler) !== 1) {
                return;
            }

            $deger = trim((string) preg_replace('/^\s*Uplink:\s*/iu', '', (string) reset($uplinkler)));

            if (filter_var($deger, FILTER_VALIDATE_IP) === false) {
                return;
            }

            $kalan = trim(implode("\n", array_diff_key($satirlar, $uplinkler)));

            DB::table('credentials')->where('id', $kayit->id)->update([
                'uplink' => $deger,
                'notes' => $kalan === '' ? null : Crypt::encryptString($kalan),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropColumn('uplink');
        });
    }
};
