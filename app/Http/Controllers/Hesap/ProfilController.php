<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hesap;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Services\ActivityRecorder;
use App\Services\Kimlik\MerkezIstemcisi;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Kullanıcının kendi hesabı.
 *
 * Yetkilendirme: herkes YALNIZCA kendi hesabını düzenler; kullanıcı oturumdan
 * gelir, adresten değil. Başkasının profiline giden bir yol yok, bu yüzden
 * ayrıca `can:` kuralı gerekmiyor.
 *
 * ROL, DURUM VE İZİNLER BURADA DEĞİŞMEZ. Kasanın kapısında duran bir panelde
 * "kendi profilini düzenle" yetkisi, kendini yönetici yapma yetkisi değildir.
 */
class ProfilController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('auth')];
    }

    public function index(MerkezIstemcisi $merkez, #[CurrentUser] $kullanici): View
    {
        return view('hesap.profil', ['kullanici' => $kullanici, 'epostaKilitli' => $merkez->acikMi()]);
    }

    public function update(Request $request, ActivityRecorder $recorder, MerkezIstemcisi $merkez, #[CurrentUser] $kullanici): RedirectResponse
    {
        /*
         * MERKEZLE GİRİŞ AÇIKKEN E-POSTAYI YÖNETİCİ YÖNETİR.
         *
         * Merkezdeki kimlik, bağı olmayan bir hesaba yerel e-postasıyla
         * eşleştirilir (bkz. HesapEslestirici). Kullanıcı kendi e-postasını
         * serbestçe yazabilseydi, henüz bağlanmamış bir çalışanın adresini
         * yazıp onun ilk girişini ve rolünü kendi hesabına çekebilirdi.
         * Kimlik eşleştirmesine giren bir alan kendi kendine yazılan bir
         * alan olamaz. Kapalıyken eşleştirme de yok; eskisi gibi düzenlenir.
         */
        $kurallar = ['name' => ['required', 'string', 'max:120']];

        if (! $merkez->acikMi()) {
            $kurallar['email'] = ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($kullanici)];
        }

        $veri = $request->validate($kurallar, attributes: ['name' => 'ad', 'email' => 'e-posta']);

        $eski = $kullanici->email;
        $kullanici->update($veri);

        if ($eski !== $kullanici->email) {
            $recorder->record(
                ActivityAction::Updated,
                'Kendi e-postasını değiştirdi: '.$eski.' → '.$kullanici->email,
                null,
                null,
                $kullanici,
            );
        }

        return back()->with('status', 'Bilgileriniz kaydedildi.');
    }

    public function parola(Request $request, ActivityRecorder $recorder, #[CurrentUser] $kullanici): RedirectResponse
    {
        $veri = $request->validate([
            /* Mevcut parola şart: oturumu ele geçiren biri hesabı devralamasın. */
            'mevcut' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], attributes: ['mevcut' => 'mevcut parola', 'password' => 'yeni parola']);

        $kullanici->update(['password' => $veri['password']]);

        /*
         * Parola değişince DİĞER OTURUMLAR DÜŞER.
         *
         * Parola değiştirmenin başlıca sebebi "başkası girmiş olabilir"
         * şüphesidir; eski oturumlar açık kalsaydı değişiklik o kişiyi dışarı
         * atmazdı.
         */
        Auth::logoutOtherDevices($veri['password']);
        $request->session()->regenerate();

        $recorder->record(
            ActivityAction::Updated,
            'Kendi parolasını değiştirdi',
            null,
            null,
            $kullanici,
        );

        return back()->with('status', 'Parolanız değiştirildi; diğer oturumlarınız kapatıldı.');
    }
}
