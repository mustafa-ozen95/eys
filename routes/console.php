<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Zamanlanmış işler
|--------------------------------------------------------------------------
| İki iş doğası gereği periyodiktir ve elle çalıştırıldıklarında unutuluyorlar:
|
|  - Merkezdeki rol/durum değişikliğini yerele taşıyan tur. Panelde gezinen
|    kişi için bunu ara katman zaten yapıyor (KimlikDurumunuDogrula), ama
|    panele hiç uğramayan bir hesap merkezde askıya alındığında burada açık
|    kalıyordu.
|
|  - Kasa denetimi: döndürmeye hazır görünen kayıtların kimlikleri sessizce
|    eskiyebilir; panel "hazır" gösterirken kimlik geçersiz olabilir ve bunu
|    ancak denetim yakalar.
|
| Konteynerde zamanlayıcıyı supervisord çalıştırır (schedule:work). Konteyner
| dışında TEK BİR CRON SATIRI GEREKİR (site kullanıcısı, root DEĞİL — aksi
| hâlde storage ve önbellek root'a geçer ve panel 500 verir):
|
|   * * * * * cd /kurulum/dizini/eys && php artisan schedule:run >> /dev/null 2>&1
|
| Satır yokken buradaki tanımlar zararsızdır: hiçbir şey çalışmaz, komutlar
| elle çağrılmaya devam eder.
*/

/*
 * Merkezde erişimi kalkan kişi burada da kaybetsin. Beş dakika, "erişimi
 * kalkan biri en fazla ne kadar daha içeride kalabilir" sorusunun yanıtı ve
 * ara katmanın önbellek süresiyle aynı.
 */
Schedule::command('erisim:kimlik-esitle')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

/*
 * KASA DENETİMİ ZAMANLANMIYOR — bilinçli: panel hiçbir cihaza kendiliğinden
 * bağlanmasın.
 *
 * Komut yerinde duruyor ve elle çalışır: `php artisan erisim:kasa-denetle`
 * (yalnızca okur; hesap açmaz, parola değiştirmez). Zamanlandığında her gece
 * kasadaki her hazır kaydın parolasını cihazda dener. Açmak için burada yalnızca şu bloğu eklemek
 * yeter: Schedule::command('erisim:kasa-denetle')->dailyAt('03:30')
 * ->withoutOverlapping(30)->runInBackground();
 */

/*
 * Düşen konsol oturumları.
 *
 * Tarayıcı kapanışı haber veremediğinde (çökme, uyku, kopan ağ) kapanış
 * kaydı bu tur sayesinde yazılır. Dakikada bir koşar; iş yoksa tek sorgu.
 */
Schedule::command('erisim:konsol-temizle')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * YENİ SÜRÜM VAR MI — HER SABAH.
 *
 * Yalnızca bakar ve sonucu yazar; yeni sürüm varsa yönetici panelin üstünde
 * uyarı görür. Güncellemenin kendisi sunucudan yapılır. Ayarlar →
 * Güncelleme'deki düğme aynı kontrolü hemen yapar.
 */
Schedule::command('guncelleme:denetle')->dailyAt('06:50');
