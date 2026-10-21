<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Credential\DestroyCredentialRequest;
use App\Http\Requests\Credential\StoreCredentialRequest;
use App\Http\Requests\Credential\UpdateCredentialRequest;
use App\Models\Credential;
use App\Services\CredentialListing;
use App\Services\CredentialWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CredentialController extends Controller implements HasMiddleware
{
    public function __construct(private CredentialWriter $writer) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,App\Models\Credential', only: ['index']),
            new Middleware('can:create,App\Models\Credential', only: ['store']),
            new Middleware('can:update,credential', only: ['update']),
        ];
    }

    /**
     * Liste ekranı.
     *
     * Ekleme ve düzenleme formları ayrı sayfa değil, bu ekranın üstünde açılan
     * penceredir: kullanıcı hangi grupta olduğunu görmeden form doldurmaz.
     */
    public function index(Request $request, CredentialListing $listing): View
    {
        return view('credentials.index', $listing->forRequest($request));
    }

    public function store(StoreCredentialRequest $request): RedirectResponse
    {
        $credential = $this->writer->create($request->payload());

        $this->writer->syncRestrictions($credential, $request->restrictedRoleIds());
        $this->writer->syncGrants($credential, $request->grantedRoleIds());
        $this->writer->syncGrants($credential, $request->konsolRoleIds(), 'connect');
        $this->writer->syncGrants($credential, $request->gormeRoleIds(), 'view');
        $this->writer->syncGrants($credential, $request->portalRoleIds(), 'session');

        return redirect()
            ->route('credentials.index', ['kayit' => $credential->uuid])
            ->with('status', "{$credential->name} eklendi.");
    }

    /**
     * Sürükle-bırak sırası: bir bölümün (grup + alt grup) satırları yeni
     * dizilimiyle gelir. Sıra elle yazılan bir sayı değil.
     *
     * Yalnızca yönetici; satırlar da tek tek yetkilendirilir. Bölümün
     * dışına taşıma yok: alt gruptaki kayıt ana gruba sürüklenemez.
     */
    public function sirala(Request $request): RedirectResponse
    {
        /* Sürükleme yalnızca yöneticinin işi. */
        abort_unless($request->user()?->isAdmin(), 403);

        $veri = $request->validate([
            'sira' => ['required', 'array', 'max:500'],
            'sira.*' => ['string', 'uuid'],
        ]);

        $kayitlar = Credential::query()->whereIn('uuid', $veri['sira'])->get();

        abort_if($kayitlar->count() !== count(array_unique($veri['sira'])), 422, 'Listede bulunmayan kayıt var.');
        abort_if($kayitlar->map(fn (Credential $c): string => $c->credential_group_id.'/'.$c->alt_grup_id)->unique()->count() !== 1,
            422, 'Sıralama tek bir bölümün içinde yapılır.');

        foreach ($kayitlar as $kayit) {
            Gate::authorize('update', $kayit);
        }

        $this->writer->sirala($kayitlar, $veri['sira']);

        return back()->with('status', 'Sıra güncellendi.');
    }

    public function update(UpdateCredentialRequest $request, Credential $credential): RedirectResponse
    {
        $this->writer->update($credential, $request->payload());

        $this->writer->syncRestrictions($credential, $request->restrictedRoleIds());
        $this->writer->syncGrants($credential, $request->grantedRoleIds());
        $this->writer->syncGrants($credential, $request->konsolRoleIds(), 'connect');
        $this->writer->syncGrants($credential, $request->gormeRoleIds(), 'view');
        $this->writer->syncGrants($credential, $request->portalRoleIds(), 'session');

        return redirect()
            ->route('credentials.index', ['kayit' => $credential->uuid])
            ->with('status', "{$credential->name} güncellendi.");
    }

    public function destroy(DestroyCredentialRequest $request, Credential $credential): RedirectResponse
    {
        $name = $credential->name;
        $grup = $credential->group?->uuid;

        $this->writer->delete($credential, $request->string('reason')->value());

        /*
         * SİLİNDİKTEN SONRA BAKILAN YERE DÖNÜLÜR.
         *
         * Yönlendirme grupsuzdu ve ilk grup açılıyordu. Önceki
         * adresteki görünüm (grup, arama, platform süzgeci) korunur; adreste
         * grup yoksa silinen kaydın grubu açılır. Silinen kaydın kendi
         * kimliği adreste bırakılmaz.
         */
        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $onceki);
        $gorunum = array_filter(
            array_intersect_key($onceki, array_flip(['grup', 'q', 'platform'])),
            fn ($v) => is_string($v) && $v !== '',
        );

        return redirect()
            ->route('credentials.index', $gorunum + ['grup' => $grup])
            ->with('status', "{$name} silindi.");
    }
}
