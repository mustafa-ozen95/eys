<div class="toast-host"
     data-flash="{{ session('status') ?? session('error') ?? '' }}"
     data-flash-variant="{{ session('error') ? 'danger' : 'success' }}"></div>
