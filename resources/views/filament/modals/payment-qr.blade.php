@php
    $setting = \App\Models\PaymentSetting::current();
@endphp

<div class="space-y-4">
    @if($setting->qr_image_path)
        <div class="flex justify-center">
            <img
                src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($setting->qr_image_path) }}"
                alt="Payment QR Code"
                class="h-56 w-56 rounded-lg border border-gray-200 object-contain dark:border-gray-700"
            />
        </div>
    @else
        <p class="text-center text-sm text-gray-500">
            No QR code has been uploaded yet. Please contact the office for payment details.
        </p>
    @endif

    @if($setting->instructions)
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $setting->instructions }}</p>
    @endif
</div>
