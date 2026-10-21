@props(['id', 'title', 'wide' => false, 'open' => false])

{{--
    Genel amaçlı pencere. Açmak için herhangi bir düğmeye
    data-modal-open="{{ $id }}" eklemek yeterlidir.

    :open="true" sunucudan açık gelen penceredir: doğrulama hatasından sonra
    kullanıcı formunu geri açmak için tıklamak zorunda kalmaz.
--}}
<div class="modal" id="{{ $id }}" data-modal hidden role="dialog" aria-modal="true"
     aria-labelledby="{{ $id }}-baslik" @if ($open) data-modal-acik @endif>
    <div @class(['modal__box', 'modal__box--wide' => $wide])>
        <header class="modal__header">
            <h2 class="modal__title" id="{{ $id }}-baslik">{{ $title }}</h2>
            <button type="button" class="modal__close" data-modal-close aria-label="Kapat">&times;</button>
        </header>

        {{ $slot }}
    </div>
</div>
