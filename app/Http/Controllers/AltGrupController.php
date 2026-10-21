<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\Group\AltGrupRequest;
use App\Models\AltGrup;
use App\Models\CredentialGroup;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Alt grup yönetimi — Gruplar sayfasında, grubun bölümünün içinde.
 *
 * Ayrı ekranı yok: bölmeler grubun bilgileridir ve grubun yanında
 * yönetilir. Ekleme ve düzenleme pencerede (bkz.
 * groups/partials/alt-penceresi), sıra sürükleyerek.
 *
 * SİLMEK KAYIT SİLMEZ: bölme kaldırılınca kayıtlar grupta, başlıksız kalır
 * (bkz. alt_gruplar göçü, nullOnDelete). Yine de onay sorulur — otuz kaydın
 * başlığını kazara kaldırmak geri alınması zahmetli bir iştir.
 */
class AltGrupController extends Controller implements HasMiddleware
{
    public function __construct(private ActivityRecorder $activity) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:update,group')];
    }

    public function store(AltGrupRequest $request, CredentialGroup $group): RedirectResponse
    {
        /* Yeni bölme listenin SONUNA girer; yerini sürükleyerek verirsiniz. */
        $altGrup = $group->altGruplar()->create([
            ...$request->payload(),
            'position' => (int) $group->altGruplar()->max('position') + 1,
        ]);

        $this->activity->record(ActivityAction::Created, "Alt grup açıldı: {$group->name} · {$altGrup->name}", $group);

        return redirect()->route('groups.index', ['acik' => $group->uuid])->with('status', "{$altGrup->name} eklendi.");
    }

    public function update(AltGrupRequest $request, CredentialGroup $group, AltGrup $altGrup): RedirectResponse
    {
        $this->grubunOlmali($group, $altGrup);

        $altGrup->update($request->payload());

        $this->activity->record(ActivityAction::Updated, "Alt grup güncellendi: {$group->name} · {$altGrup->name}", $group);

        return redirect()->route('groups.index', ['acik' => $group->uuid])->with('status', "{$altGrup->name} güncellendi.");
    }

    /** Bölmeleri sürükleyerek sıralar (bkz. siralama.js). */
    public function sirala(Request $request, CredentialGroup $group): RedirectResponse
    {
        $veri = $request->validate([
            'sira' => ['required', 'array'],
            'sira.*' => ['string', 'uuid'],
        ]);

        /*
         * Gelen liste grubun TÜM bölmeleri olmayabilir: kayıt listesi boş
         * bölmeyi çizmiyor. Gelmeyenler yerinde kalır, gelenler kendi eski
         * yuvalarına yeni sırayla oturur; sonra hepsi baştan numaralanır.
         */
        $bolmeler = $group->altGruplar()->pluck('id', 'uuid');
        $gelen = array_values(array_filter(array_unique($veri['sira']), fn (string $uuid): bool => $bolmeler->has($uuid)));
        $sira = $gelen;
        $dizilim = $bolmeler->keys()->map(function (string $uuid) use ($gelen, &$sira): string {
            return in_array($uuid, $gelen, true) ? array_shift($sira) : $uuid;
        });

        DB::transaction(function () use ($dizilim, $bolmeler): void {
            foreach ($dizilim->values() as $i => $uuid) {
                AltGrup::query()->whereKey($bolmeler[$uuid])->update(['position' => $i + 1]);
            }
        });

        $this->activity->record(ActivityAction::Updated, "Alt gruplar yeniden sıralandı: {$group->name}", $group);

        return back()->with('status', "{$group->name} alt grup sırası güncellendi.");
    }

    public function destroy(Request $request, CredentialGroup $group, AltGrup $altGrup): RedirectResponse
    {
        $this->grubunOlmali($group, $altGrup);

        $name = $altGrup->name;
        $count = $altGrup->credentials()->count();

        $altGrup->delete();

        $this->activity->record(
            ActivityAction::Deleted,
            "Alt grup kaldırıldı: {$group->name} · {$name} ({$count} kayıt grupta kaldı)",
            $group,
            $request->string('reason')->value() ?: null,
        );

        return redirect()->route('groups.index', ['acik' => $group->uuid])->with('status', "{$name} kaldırıldı; kayıtları grupta duruyor.");
    }

    /**
     * Adresteki bölme adresteki gruba ait olmalı.
     *
     * İki uuid ayrı ayrı çözülüyor; başka grubun bölmesi bu grubun adresiyle
     * düzenlenebilseydi yetki kontrolü yanlış gruba sorulmuş olurdu.
     */
    private function grubunOlmali(CredentialGroup $group, AltGrup $altGrup): void
    {
        if ($altGrup->credential_group_id !== $group->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
