{{--
    A native <dialog>. Open with <button data-modal-open="id">, close with [data-modal-close].
    <x-modal id="confirm-refund" title="Issue the refund?"> ... <x-slot:footer>...</x-slot:footer></x-modal>
--}}
@props(['id', 'title'])
<dialog {{ $attributes->class('modal') }} id="{{ $id }}" aria-labelledby="{{ $id }}-title">
    <div class="card-body">
        <div class="card-head">
            <h2 class="card-title" id="{{ $id }}-title">{{ $title }}</h2>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><x-icon name="x" /></button>
        </div>
        {{ $slot }}
        @isset($footer)<div class="modal-foot">{{ $footer }}</div>@endisset
    </div>
</dialog>
