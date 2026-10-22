<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Görsel test veritabanını SIFIRDAN kurar.
 *
 * NEDEN GEREKLİ: senaryolar kayıt ekliyor ve siliyor. Aynı dosya üzerinde
 * arka arkaya koşulduğunda artıklar birikiyor ve "srv-a SSH" gibi ada göre
 * arayan seçiciler birden fazla satır buluyor; takım kodla ilgisi olmayan
 * "strict mode violation" hatalarıyla düşüyor. Bu yüzden kurulum tek komut.
 *
 * ÜRETİMDE ÇALIŞMAZ: bağlantı sqlite değilse durur. `migrate:fresh`
 * çalıştıran bir komutun yanlış veritabanına bakma ihtimali kabul edilemez.
 */
class VisualPrepareCommand extends Command
{
    protected $signature = 'access:prepare-visuals';

    protected $description = 'Görsel (Playwright) testleri için sqlite veritabanını sıfırdan kurar';

    public function handle(): int
    {
        $baglanti = DB::connection();

        if ($baglanti->getDriverName() !== 'sqlite') {
            $this->error('Bu komut yalnızca sqlite üzerinde çalışır; şu an: '.$baglanti->getDriverName());

            return self::FAILURE;
        }

        $this->info('Veritabanı: '.$baglanti->getDatabaseName());

        $this->call('migrate:fresh', ['--force' => true]);
        /*
         * CredentialGroupSeeder ARTIK ÇAĞRILMIYOR.
         *
         * O seeder kasa iskeletini yerel bir dosyadan okuyor; dosya makineden
         * makineye değişir, yani görsel dünya da değişirdi ve testler
         * kimin makinesinde koştuğuna göre salınırdı. Görsel dünyayı tek
         * başına VisualTestSeeder kurar: sabit, küçük ve depoda duran.
         */
        $this->call('db:seed', ['--class' => 'VisualTestSeeder', '--force' => true]);

        $this->info('Görsel veritabanı hazır.');

        return self::SUCCESS;
    }
}
