<?php

declare(strict_types=1);

use App\Enums\BadgeVariant;
use App\Models\Role;
use App\Policies\CredentialPolicy;
use Illuminate\Database\Migrations\Migration;

/**
 * Yerleşik rollerin varsayılan yetkileri.
 *
 * Rol canlı okunur: buradaki liste, o roldeki herkesin ne yapabildiği
 * demektir. Ürünün varsayılan şablonu en az yetki ilkesine göre kurulur:
 * görme yetkisi parolayı açmayı kapsamaz; parolayı açma ve oturum başlatma
 * ayrı verilir; kasayı değiştiren işler (ekleme, düzenleme, silme, döndürme)
 * yalnızca yöneticide kalır; varsayılan yetkisi olmayan rol, gereken kaydı
 * kişiye özel istisnayla alır.
 *
 * Yöneticinin şablonu yoktur: bütün kontrolleri zaten geçer.
 *
 * KİŞİSEL İSTİSNALARA DOKUNULMAZ. Bu göç yalnızca rolün varsayılanını yazar;
 * birine tek tek verilmiş izin ya da yasak yerinde kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        $goruntuleme = ['view', 'field.host', 'field.port', 'field.url', 'field.username', 'field.notes'];

        $this->yaz('teknik', $goruntuleme);
        $this->yaz('network', [...$goruntuleme, 'field.password', 'session']);

        /* Rol YOKSA açılır: merkezden gelen her rolün burada birebir
         * karşılığı olmalı; karşılığı olmayan rol varsayılan role düşer ve
         * gereğinden geniş yetki alabilir. */
        if (Role::query()->where('slug', 'muhasebe')->exists()) {
            return;
        }

        /*
         * Toplu atama KORUMALI alanları (uuid, is_system) atlar; göç bunları
         * doğrudan yazar.
         */
        $muhasebe = new Role([
            'label' => 'Muhasebe',
            'description' => 'Kasada varsayılan yetkisi yoktur; gereken kayıtlar kişiye özel verilir.',
            'badge' => BadgeVariant::Cyan,
            'is_admin' => false,
            'is_default' => false,
            'position' => 3,
            'template' => [],
        ]);

        $muhasebe->slug = 'muhasebe';
        $muhasebe->is_system = true;
        $muhasebe->save();
    }

    public function down(): void
    {
        // Kasıtlı olarak boş: yetki daraltan bir göçün geri alınması yetki üretirdi.
    }

    /** @param  list<string>  $aksiyonlar */
    private function yaz(string $slug, array $aksiyonlar): void
    {
        Role::query()->where('slug', $slug)->where('is_admin', false)->each(
            fn (Role $rol) => $rol->update([
                'template' => [CredentialPolicy::RESOURCE => ['all' => $aksiyonlar, 'groups' => []]],
            ]),
        );
    }
};
