<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Services\Activity\ActivityChain;
use App\Services\ActivityRecorder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Denetim kaydını budar.
 *
 * Panelde silme düğmesi yoktur ve olmayacaktır: kaydı ekrandan silebilen biri
 * denetimden de kaçabilirdi. Budama yalnızca sunucuya erişebilen kişinin
 * elindedir, gerekçe ister ve kendisi de zincire yazılır — silinen satırların
 * yerinde "kim, ne zaman, neden sildi" kalır.
 *
 * İki ölçüt vardır ve birlikte de verilebilir:
 *
 *   --korumasiz  Zincirden ÖNCE yazılmış, özeti olmayan satırlar. Bunlar
 *                doğrulanamaz; değişmediklerine dair elimizde kanıt yoktur.
 *   --gun=N      N günden eski satırlar. Zincirin başı kesildiği için kalan
 *                ilk satırın "öncekisi" çapa olarak saklanır; yoksa her
 *                budamadan sonra doğrulama zinciri kırık gösterirdi.
 */
class PruneActivityLogCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'erisim:kayit-buda
                            {--korumasiz : Zincirden önce yazılmış, özetsiz satırları siler}
                            {--gun= : Bu kadar günden eski satırları siler}
                            {--gerekce= : Kayda yazılacak gerekçe}
                            {--force : Üretimde onay sorma}';

    protected $description = 'Denetim kaydındaki eski satırları siler ve zinciri yeni başlangıcına çapalar';

    public function handle(ActivityChain $chain, ActivityRecorder $recorder): int
    {
        $days = $this->option('gun');

        if (! $this->option('korumasiz') && $days === null) {
            $this->error('En az bir ölçüt verin: --korumasiz ya da --gun=N.');

            return self::INVALID;
        }

        if (! $this->confirmToProceed('Denetim kaydından satır silinecek')) {
            return self::FAILURE;
        }

        $deleted = 0;

        if ($days !== null) {
            $deleted += $this->pruneOlderThan((int) $days, $chain);
        }

        if ($this->option('korumasiz')) {
            $deleted += $this->pruneUnprotected();
        }

        if ($deleted > 0) {
            $recorder->record(
                ActivityAction::Pruned,
                "Denetim kaydından {$deleted} satır silindi.",
                reason: $this->reason($days),
            );
        }

        $this->info("Toplam {$deleted} satır silindi.");

        return self::SUCCESS;
    }

    /**
     * Saklama süresi dolan satırlar.
     *
     * Kesim TARİHE göre değil, tarihin belirlediği KİMLİĞE göre yapılır:
     * zincir kimlik sırasında yürür, araya bir satır bırakılırsa doğrulama
     * kırılır.
     */
    private function pruneOlderThan(int $days, ActivityChain $chain): int
    {
        $lastId = ActivityLog::query()
            ->where('chain', ActivityChain::AUDIT)
            ->where('created_at', '<', now()->subDays($days))
            ->max('id');

        if ($lastId === null) {
            $this->line("Son {$days} günden eski satır yok.");

            return 0;
        }

        /*
         * Silinen son ÖZETLİ satırın özeti zincirin yeni başlangıç noktasıdır.
         * Yazılmazsa kalan ilk satırın "öncekisi" veritabanında bulunmaz ve
         * doğrulama sağlam zinciri kırık gösterirdi.
         */
        $anchor = ActivityLog::query()
            ->where('chain', ActivityChain::AUDIT)
            ->where('id', '<=', $lastId)
            ->whereNotNull('hash')
            ->latest('id')
            ->value('hash');

        /*
         * Silme ve çapa AYNI zincir kilidi ve AYNI işlem altında: araya bir
         * kayıt girerse mühürdeki satır sayısı şaşardı. Çapa
         * veritabanında mühürlü tutulur; önbellekte değil.
         */
        return $chain->exclusive(fn (): int => DB::transaction(function () use ($lastId, $anchor, $chain): int {
            $ozetli = ActivityLog::query()
                ->where('chain', ActivityChain::AUDIT)
                ->where('id', '<=', $lastId)
                ->whereNotNull('hash')
                ->count();

            /* Mühür, SİLMEDEN ÖNCE mevcut zincirden türetilmiş olmalı. */
            $chain->anchorTo(null);

            $deleted = ActivityLog::prune(fn (): int => ActivityLog::query()
                ->where('chain', ActivityChain::AUDIT)
                ->where('id', '<=', $lastId)
                ->delete());

            if ($anchor !== null) {
                $chain->anchorTo($anchor, $ozetli);
            }

            $this->line("Süresi dolan {$deleted} satır silindi.");

            return $deleted;
        }));
    }

    /**
     * Zincirden önce yazılmış satırlar.
     *
     * Çapaya dokunulmaz: doğrulama özetsiz satırları zaten atlıyor, aradan
     * çıkmaları zinciri etkilemez.
     */
    private function pruneUnprotected(): int
    {
        $deleted = ActivityLog::prune(fn (): int => ActivityLog::query()
            ->where('chain', ActivityChain::AUDIT)
            ->whereNull('hash')
            ->delete());

        $this->line("Korumasız {$deleted} satır silindi.");

        return $deleted;
    }

    private function reason(?string $days): string
    {
        $reason = $this->option('gerekce');

        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        return trim(implode(' ', [
            $this->option('korumasiz') ? 'Zincirden önce yazılmış korumasız satırlar budandı.' : '',
            $days !== null ? "Saklama süresi ({$days} gün) dolan satırlar budandı." : '',
        ]));
    }
}
