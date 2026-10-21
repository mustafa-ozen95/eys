<?php

declare(strict_types=1);

namespace App\View\Components\Credentials;

use App\Enums\OperatingSystem;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Kayıt formu (ekleme ve düzenleme aynı formu kullanır). Koşullu alan
 * kuralları, platform → erişim türü haritası ve "Gelişmiş" bölümünün açık
 * gelip gelmeyeceği burada hesaplanır; şablon yalnızca hazır değeri çizer.
 */
class Form extends Component
{
    /*
     * KİLİTLİ HEDEF ALANI: değer görünür ama kutu SALT OKUNUR.
     * Kayıtta parola/döndürme kimliği var ve kullanıcının parola yetkisi
     * yok; adresi değiştirmek sırrı başka yere göndermek olurdu. Asıl
     * koruma sunucudadır (CredentialRequest::gorulmeyeniKoru).
     */
    public string $kilitNotu = 'Kayıtta parola var; adresi yalnızca parolayı görebilenler değiştirebilir.';

    /*
     * KOŞULLU ALANLAR (bkz. kosullu-alan.js).
     *
     * Form her alanı her kayıtta gösteriyordu: uzak masaüstü kaydında portal
     * formu, üyelik kaydında port, SSH kaydında RDP kipi. Gereksiz alan
     * kafa karıştırır; alan, açılır listedeki seçime ya da işaretlenen
     * kutuya göre görünür.
     *
     * Kurallar alanların hangi türde dolu olduğuna göre kuruldu: RDP kipi
     * yalnızca RDP'de, bağlantı adresi SSH/RDP/VNC dışında, portal alanları
     * yalnızca web ve panelde, adres ve port üyelik/lisans dışında dolu.
     * Yani bu kurallarla hiçbir dolu alan gizlenmiyor — ve betik ayrıca
     * DOLU ya da HATALI alanı hiçbir kuralla gizlemiyor.
     *
     * Sözdizimi: "alan:değer,değer" · "alan:!değer" (değilse) · "alan:*"
     * (doluysa / işaretliyse) · koşullar "|" ile VEYA'lanır.
     */
    public string $konsolTurleri = 'ssh,rdp,vnc';

    public string $adressizTurler = 'membership,license';

    public string $portalTurleri = 'panel,web';

    /** Parola güncelleme koşulu. */
    public string $guncellemeKosulu;

    /**
     * Platform => o platformda anlamlı erişim türleri (en olası önce).
     *
     * @var array<string, array<int, string>>
     */
    public array $erisimHaritasi;

    /** Rol istisnalarından biri işaretliyse "Gelişmiş" açık gelir: kısıt gizli kalmamalı. */
    public bool $gelismisAcik;

    /** Seçili güncelleme sürücüsü ve kasa hesabı (eski girdi önceliklidir). */
    public mixed $surucuSecimi;

    public mixed $kasaSecimi;

    public Collection $restrictableRoles;

    public function __construct(
        public mixed $credential = null,
        public mixed $groupOptions = [],
        public mixed $altGrupOptions = [],
        public mixed $altGrupHaritasi = [],
        public mixed $accessTypes = [],
        public mixed $operatingSystems = [],
        public mixed $rotationDrivers = [],
        public mixed $rotationCredentialOptions = [],
        public mixed $makineHesabiSurucileri = [],
        public mixed $selectedGroup = null,
        public mixed $visibleFields = [],
        /* Görünen ama değiştirilemeyen hedef alanları (Credential::kilitliHedefAlanlari). */
        public mixed $lockedFields = [],
        /* Kayıt bazında rol kısıtı ve ek izin: yalnızca yöneticiye gösterilir. */
        public bool $canRestrict = false,
        ?Collection $restrictableRoles = null,
        public mixed $restrictedRoleUuids = [],
        public mixed $grantedRoleUuids = [],
        public mixed $konsolRoleUuids = [],
        public mixed $gormeRoleUuids = [],
        public mixed $rolGorunum = [],
        public mixed $portalRoleUuids = [],
    ) {
        $this->restrictableRoles = $restrictableRoles ?? collect();

        /*
         * Parola güncelleme: platformu döndürülebilen kayıtlar, web ve panel
         * (Plesk, mailcow, rspamd sürücüleri uygulama hesabı üzerinde çalışır)
         * ve sürücüsü elle seçilmiş her kayıt.
         */
        $this->guncellemeKosulu = 'operating_system:'.collect(OperatingSystem::cases())
            ->filter->rotatable()->map->value->implode(',')
            .'|access_type:'.$this->portalTurleri.'|rotation_driver:*';

        $this->erisimHaritasi = collect(OperatingSystem::cases())
            ->mapWithKeys(fn ($os) => [$os->value => array_map(fn ($t) => $t->value, $os->accessTypes())])
            ->all();

        $this->gelismisAcik = old('restricted_roles', $restrictedRoleUuids) !== []
            || old('granted_roles', $grantedRoleUuids) !== []
            || old('konsol_roles', $konsolRoleUuids) !== []
            || old('gorme_roles', $gormeRoleUuids) !== []
            || old('portal_roles', $portalRoleUuids) !== [];

        $this->surucuSecimi = old('rotation_driver', $credential?->rotation_driver ?? '');
        $this->kasaSecimi = old('rotation_credential_id', $credential?->rotation_credential_id ?? '');
    }

    public function render(): View
    {
        return view('components.credentials.form');
    }
}
