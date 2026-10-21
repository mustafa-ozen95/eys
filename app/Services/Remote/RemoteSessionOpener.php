<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Enums\AccessType;
use App\Models\ConsoleSession;
use App\Models\Credential;
use Illuminate\Support\Str;

/**
 * Konsol oturumunu protokolüne göre açar.
 *
 * SSH tarayıcı içi terminale (ttyd), uzak masaüstü Guacamole'a gider.
 * Controller bu ayrımı bilmez; hangi protokolün nereye gittiği tek yerde
 * durur.
 *
 * PAROLA EKRANA ÇIKMAZ: kasadaki değer doğrudan sürece/Guacamole'a geçer,
 * kullanıcı onu hiç görmeden bağlanır. Konsolun varlık sebebi budur.
 */
class RemoteSessionOpener
{
    public function __construct(
        private TargetResolver $resolver,
        private TtydManager $ttyd,
        private GuacamoleService $guacamole,
        private RemoteSessionStore $sessions,
    ) {}

    /**
     * @return array{token: string, label: string, protocol: string, url: string}
     */
    public function open(Credential $credential, int $userId): array
    {
        $target = $this->resolver->resolve($credential);
        $token = Str::uuid()->toString();

        $session = $target->isSsh()
            ? $this->ttyd->start($target, $token)
            : $this->guacamole->open($target);

        // Oturumun sahibi kaydedilir: proxy bekçisi her istekte bunu sorar.
        $this->sessions->put($token, $session + [
            'label' => $target->label,
            'protocol' => $target->protocol->value,
            'credential_id' => $credential->getKey(),
            'user_id' => $userId,
        ]);

        $this->sessions->rememberForUser($userId, $target->protocol, $token);

        /*
         * DENETİM İZİ önbellekten ayrı durur: önbellekteki kayıt canlı tutma
         * kesilince sessizce düşer ve geriye "kapandı" satırı olmayan bir
         * oturum kalırdı (bkz. konsol_oturumlari göçü).
         */
        ConsoleSession::create([
            'token' => $token,
            'user_id' => $userId,
            'credential_id' => $credential->getKey(),
            'label' => $target->label,
            'protocol' => $target->protocol,
            'port' => $session['port'] ?? null,
            'opened_at' => now(),
            'last_seen_at' => now(),
        ]);

        return [
            'token' => $token,
            'label' => $target->label,
            'protocol' => $target->protocol->value,
            'url' => $session['path'],
        ];
    }

    /**
     * Kapatılan oturumun künyesi döner; controller bunu kayda yazar.
     * Zaten kapalıysa null.
     *
     * @return array{label: string, protocol: string, credential: ?Credential}|null
     */
    public function close(string $token): ?array
    {
        $session = $this->sessions->get($token);

        if ($session === null) {
            return null;
        }

        /*
         * Yalnızca ttyd'nin kapatılacak bir süreci var; Guacamole oturumu
         * kendi anahtarının süresi dolunca düşer.
         */
        if (isset($session['port'])) {
            $this->ttyd->stop((int) $session['port']);
        }

        $this->sessions->forget($token);
        $this->iziKapat($token, 'kullanici');

        return [
            'label' => $session['label'],
            'protocol' => $session['protocol'],
            'credential' => Credential::query()->find($session['credential_id']),
        ];
    }

    /** Oturumun protokolü; kapanış kaydı için. */
    public function protokol(string $deger): AccessType
    {
        return AccessType::from($deger);
    }

    /** Denetim izini kapatır. İz yoksa (eski oturum) sessizce geçilir. */
    public function iziKapat(string $token, string $sebep): void
    {
        ConsoleSession::query()
            ->where('token', $token)
            ->whereNull('closed_at')
            ->update(['closed_at' => now(), 'closed_reason' => $sebep]);
    }

    /** Canlı tutma: oturum hâlâ ayakta. */
    public function izeDokun(string $token): void
    {
        ConsoleSession::query()
            ->where('token', $token)
            ->whereNull('closed_at')
            ->update(['last_seen_at' => now()]);
    }
}
