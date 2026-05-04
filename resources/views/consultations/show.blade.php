@extends('layouts.app')
@section('title', 'Detail Lead - ' . $consultation->client_name)

@section('content')
<div class="flex flex-col sm:flex-row justify-between sm:items-end gap-6 mb-8">
    <div class="flex items-center gap-4">
        <a href="{{ route('consultations.index') }}" class="w-10 h-10 rounded-xl bg-surface-container flex items-center justify-center text-on-surface-variant hover:bg-surface-container-high transition-all active:scale-90 shrink-0">
            <x-icon name="arrow_back" class="w-5 h-5" />
        </a>
        <div class="min-w-0">
            <h2 class="text-2xl sm:text-3xl font-extrabold text-on-surface tracking-tight font-headline truncate pr-4">Detail Konsultasi</h2>
            <div class="flex items-center gap-2 mt-1.5 overflow-hidden">
                <span class="text-[10px] sm:text-xs font-bold text-on-surface-variant uppercase tracking-widest shrink-0">ID:</span>
                <span class="font-mono bg-primary-container/30 text-primary px-2 py-0.5 rounded-lg text-sm truncate">{{ $consultation->consultation_id }}</span>
            </div>
        </div>
    </div>
    <div class="flex gap-3 no-print">
        <a href="{{ route('consultations.edit', $consultation) }}" class="flex-1 sm:flex-none bg-surface-container-high text-on-surface px-6 py-3 rounded-xl text-sm font-bold flex items-center justify-center gap-2 hover:bg-surface-variant transition-all hover:scale-[1.02] active:scale-[0.98]">
            <x-icon name="edit" class="w-4 h-4" />
            <span>Ubah Profil</span>
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 sm:gap-8 items-start">
    {{-- User Profile Card --}}
    <div class="lg:col-span-4 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm space-y-8 border border-surface-container-low">
        <div class="flex flex-col items-center text-center gap-4">
            <div class="w-20 h-20 rounded-full bg-primary-container text-primary flex items-center justify-center font-bold text-2xl uppercase shadow-lg ring-4 ring-white shrink-0">
                {{ substr($consultation->client_name, 0, 2) }}
            </div>
            <div class="min-w-0 w-full">
                <h3 class="font-bold text-xl text-on-surface truncate px-2">{{ $consultation->client_name }}</h3>
                <div class="flex items-center justify-center gap-1.5 text-on-surface-variant text-xs mt-1">
                    <x-icon name="location_on" class="w-3.5 h-3.5" />
                    <span class="truncate">
                        @php
                            $loc = array_filter([
                                $consultation->city,
                                $consultation->district ? 'Kec. '.$consultation->district : null,
                                $consultation->province
                            ]);
                        @endphp
                        {{ implode(', ', $loc) ?: '-' }}
                    </span>
                </div>
            </div>
        </div>
        
        <div class="space-y-6 pt-6 border-t border-surface-container-low">
            <div class="space-y-1">
                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Status Saat Ini</p>
                <div class="inline-block px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider shadow-sm"
                     style="{{ $consultation->statusCategory?->chip_style ?? 'background-color: rgba(115, 124, 127, 0.14); color: #737C7F;' }}">
                    {{ $consultation->statusCategory?->name ?? 'Belum Ada Status' }}
                </div>
            </div>
            <div class="space-y-1">
                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Kontak Person</p>
                <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $consultation->phone) }}" target="_blank" class="text-primary font-bold text-sm flex items-center gap-2 hover:bg-primary-container/10 p-2 rounded-lg transition-all w-fit -ml-2 group">
                    <x-icon name="call" class="w-[18px] h-[18px]" /> 
                    <span class="group-hover:underline">{{ $consultation->phone }}</span>
                </a>
            </div>
            <div class="space-y-1">
                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Nama Produk</p>
                @forelse($consultation->productCategories() as $product)
                    <span class="inline-block text-on-surface text-sm font-bold bg-surface px-3 py-2 rounded-lg border border-surface-container shadow-inner mr-2 mb-2">{{ $product->name }}</span>
                @empty
                    <p class="text-on-surface text-sm font-bold bg-surface px-3 py-2 rounded-lg border border-surface-container shadow-inner">-</p>
                @endforelse
            </div>

            @if($consultation->product_details)
            <div class="space-y-1">
                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Detail Kebutuhan</p>
                <p class="text-on-surface-variant text-sm bg-surface px-3 py-2 rounded-lg border border-surface-container shadow-inner whitespace-pre-wrap">{{ $consultation->product_details }}</p>
            </div>
            @endif

            {{-- Alamat Lengkap --}}
            @if($consultation->address)
            <div class="space-y-1">
                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Alamat Lengkap</p>
                <p class="text-on-surface-variant text-sm bg-surface px-3 py-2 rounded-lg border border-surface-container shadow-inner leading-relaxed whitespace-pre-wrap">{{ $consultation->address }}</p>
            </div>
            @endif

            {{-- Note dari Admin --}}
            @if($consultation->notes)
            <div class="space-y-1">
                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Note Dari Admin</p>
                <p class="text-on-surface-variant text-sm bg-surface px-3 py-2 rounded-lg border border-surface-container shadow-inner leading-relaxed whitespace-pre-wrap">{{ $consultation->notes }}</p>
            </div>
            @endif

            @if(auth()->user()->isSuperAdmin())
            <div class="space-y-1">
                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Akun Terhubung</p>
                <div class="flex items-center gap-2 text-on-surface-variant bg-surface px-3 py-2 rounded-lg border border-surface-container">
                    <x-icon name="studio_point" class="w-3.5 h-3.5" />
                    <span class="text-sm font-bold">{{ $consultation->account?->name ?? 'Pusat' }}</span>
                </div>
            </div>
            @endif
        </div>
    </div>

    {{-- Tabs Content (Notes & Reminders) --}}
    <div class="lg:col-span-8 space-y-6" x-data="{ tab: 'notes' }">
        <div class="grid grid-cols-2 border-b border-surface-container mb-6 relative no-print">
            <button @click="tab = 'notes'" :class="tab === 'notes' ? 'text-primary' : 'text-on-surface-variant hover:text-on-surface'" class="pb-4 px-4 sm:px-6 font-bold transition-all duration-300 relative z-10 whitespace-nowrap text-sm sm:text-base text-center">
                Catatan
            </button>
            <button @click="tab = 'reminders'" :class="tab === 'reminders' ? 'text-primary' : 'text-on-surface-variant hover:text-on-surface'" class="pb-4 px-4 sm:px-6 font-bold transition-all duration-300 relative z-10 flex items-center justify-center gap-2 whitespace-nowrap text-sm sm:text-base text-center">
                <span>Keterangan</span>
                @if($consultation->reminders->where('is_read', false)->count() > 0)
                <span class="w-5 h-5 rounded-full bg-error text-on-error text-[10px] flex items-center justify-center font-bold shadow-lg shadow-error/20 animate-pulse-soft">{{ $consultation->reminders->where('is_read', false)->count() }}</span>
                @endif
            </button>
            {{-- Animated underline indicator --}}
            <div class="absolute bottom-0 left-0 h-[3px] w-1/2 bg-primary transition-all duration-300 ease-in-out rounded-full shadow-[0_-2px_6px_rgba(77,68,227,0.3)]"
                 :class="tab === 'notes' ? 'translate-x-0' : 'translate-x-full'">
            </div>
        </div>

        {{-- NOTES TAB --}}
        <div x-show="tab === 'notes'"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform translate-y-2"
             x-transition:enter-end="opacity-100 transform translate-y-0"
             class="space-y-6">
            {{-- Add Note Form --}}
            <form action="{{ route('consultations.notes.store', $consultation) }}" method="POST" class="bg-surface-container-lowest p-5 sm:p-6 rounded-2xl shadow-sm border border-surface-container-low no-print">
                @csrf
                <textarea name="body" rows="3" required class="w-full bg-surface border-0 rounded-xl p-4 text-sm focus:ring-2 focus:ring-primary/20 placeholder:text-on-surface-variant shadow-inner" placeholder="Tulis hasil follow-up hari ini..."></textarea>
                <div class="flex justify-end mt-4">
                    <button type="submit" class="bg-primary text-on-primary px-8 py-2.5 rounded-xl text-sm font-bold shadow-xl shadow-primary/20 hover:bg-primary-dim transition-all hover:scale-[1.02] active:scale-[0.98] flex items-center gap-2">
                        <x-icon name="send_and_archive" class="w-4 h-4" /> 
                        <span>Simpan Catatan</span>
                    </button>
                </div>
            </form>

            {{-- Timeline --}}
            <div class="space-y-6 relative before:absolute before:left-[19px] before:top-4 before:bottom-4 before:w-[2px] before:bg-surface-container-high overflow-hidden">
                @forelse($consultation->timelineNotes as $note)
                <div class="relative pl-12 group">
                    <div class="absolute left-0 top-1 w-10 h-10 rounded-full bg-white border-2 border-surface-container-high flex items-center justify-center z-10 text-[10px] font-bold text-primary shadow-sm group-hover:border-primary transition-colors">
                        {{ strtoupper(substr($note->user?->name ?? '?', 0, 2)) }}
                    </div>
                    <div class="bg-surface-container-lowest p-5 rounded-2xl shadow-sm border border-surface-container-low group-hover:border-primary/20 transition-all">
                        <div class="flex flex-col sm:flex-row justify-between sm:items-center mb-3 gap-2">
                            <span class="font-bold text-sm text-on-surface px-1">{{ $note->user?->name ?? 'System' }}</span>
                            <div class="flex items-center gap-2 overflow-hidden">
                                <span class="text-[10px] font-bold text-on-surface-variant tracking-wider bg-surface px-2 py-1 rounded-lg shrink-0">{{ $note->created_at->format('d M, H:i') }}</span>
                                @if($note->user_id === auth()->id() || auth()->user()->isSuperAdmin())
                                <form id="delete-note-{{ $note->id }}" method="POST" action="{{ route('consultations.notes.destroy', [$consultation, $note]) }}" class="shrink-0">
                                    @csrf @method('DELETE')
                                    <button type="button" onclick="confirmDeleteNote('delete-note-{{ $note->id }}')"
                                            class="w-8 h-8 rounded-lg hover:bg-error/10 flex items-center justify-center text-outline-variant hover:text-error transition-all active:scale-90"
                                            title="Hapus">
                                        <x-icon name="delete" class="w-[18px] h-[18px]" />
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                        <p class="text-sm text-on-surface-variant whitespace-pre-wrap leading-relaxed px-1">{{ $note->body }}</p>
                    </div>
                </div>
                @empty
                <div class="pl-12 py-10">
                    <div class="flex flex-col items-center opacity-40">
                        <x-icon name="history_edu" class="w-12 h-12 mb-2" />
                        <p class="text-sm font-bold">Belum ada aktivitas follow-up.</p>
                    </div>
                </div>
                @endforelse
            </div>
        </div>

        {{-- REMINDERS TAB --}}
        <div x-show="tab === 'reminders'" x-cloak
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform translate-y-2"
             x-transition:enter-end="opacity-100 transform translate-y-0"
             class="space-y-6">
            <form action="{{ route('consultations.reminders.store', $consultation) }}" method="POST" class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border border-surface-container-low no-print flex flex-col xl:flex-row gap-4">
                @csrf
                <div class="flex-1">
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-on-surface-variant mb-2 px-1">Apa perannya?</label>
                    <input type="text" name="message" required placeholder="Contoh: Survey lokasi atau Follow-up DP" class="w-full bg-surface border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner">
                </div>
                <div class="xl:w-64">
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-on-surface-variant mb-2 px-1">Kapan?</label>
                    <input type="datetime-local" name="remind_at" required class="w-full bg-surface border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner">
                </div>
                <div class="self-end w-full xl:w-auto">
                    <button type="submit" class="w-full xl:w-auto bg-tertiary text-on-tertiary px-8 py-3 rounded-xl text-sm font-bold shadow-xl shadow-tertiary/20 hover:bg-tertiary-dim transition-all active:scale-[0.98]">
                        Buat Pengingat
                    </button>
                </div>
            </form>

            <div class="space-y-4">
                @forelse($consultation->reminders as $reminder)
                <div class="bg-surface-container-lowest p-5 rounded-2xl shadow-sm border border-surface-container-low flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 relative overflow-hidden group">
                    <div class="absolute left-0 top-0 bottom-0 w-1.5 {{ $reminder->is_read ? 'bg-outline-variant/30' : 'bg-primary' }}"></div>
                    <div class="min-w-0 flex-1 px-2">
                        <p class="text-sm font-bold text-on-surface {{ $reminder->is_read ? 'line-through opacity-50' : '' }} pr-4 truncate">{{ $reminder->message }}</p>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2">
                            <p class="text-[10px] font-bold text-on-surface-variant flex items-center gap-1.5 bg-surface px-2 py-1 rounded-lg">
                                <x-icon name="schedule" class="w-3.5 h-3.5" /> 
                                {{ $reminder->remind_at->format('d M Y, H:i') }}
                            </p>
                            <p class="text-[10px] font-bold text-outline-variant flex items-center gap-1.5">
                                <x-icon name="person" class="w-3.5 h-3.5" /> 
                                {{ $reminder->user?->name ?? 'System' }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 no-print self-end sm:self-center shrink-0">
                        @if(!$reminder->is_read && ($reminder->user_id === auth()->id() || auth()->user()->isSuperAdmin()))
                        <form action="{{ route('reminders.read', $reminder) }}" method="POST">
                            @csrf
                            <button type="submit" class="w-10 h-10 rounded-xl bg-tertiary text-on-tertiary flex items-center justify-center shadow-lg shadow-tertiary/10 hover:scale-105 active:scale-95 transition-all" title="Selesaikan">
                                <x-icon name="check" class="w-5 h-5" />
                            </button>
                        </form>
                        @endif
                        @if($reminder->user_id === auth()->id() || auth()->user()->isSuperAdmin())
                        <form id="delete-reminder-{{ $reminder->id }}" method="POST" action="{{ route('consultations.reminders.destroy', [$consultation, $reminder]) }}">
                            @csrf @method('DELETE')
                            <button type="button" onclick="confirmDeleteReminder('delete-reminder-{{ $reminder->id }}')"
                                    class="w-10 h-10 rounded-xl bg-surface-container-high hover:bg-error/10 text-on-surface-variant hover:text-error flex items-center justify-center transition-all group-hover:scale-100 sm:scale-95 group-hover:opacity-100 sm:opacity-0 active:scale-90"
                                    title="Hapus">
                                <x-icon name="delete" class="w-5 h-5" />
                            </button>
                        </form>
                        @endif
                    </div>
                </div>
                @empty
                <div class="bg-surface-container-lowest/50 p-12 rounded-2xl border-2 border-dashed border-surface-container-high text-center">
                    <x-icon name="notifications_off" class="w-12 h-12 text-outline-variant/30 mb-2" />
                    <p class="text-sm font-bold text-on-surface-variant">Tidak ada pengingat terjadwal.</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function confirmDeleteNote(formId) {
        window.withSwal(async (Swal) => {
            const result = await Swal.fire({
                title: 'Hapus riwayat follow-up?',
                text: 'Tindakan ini tidak dapat dibatalkan.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#9f403d',
                cancelButtonColor: '#737c7f',
                confirmButtonText: 'Ya, hapus!',
                cancelButtonText: 'Batal',
                customClass: {
                    popup: 'rounded-2xl',
                    confirmButton: 'rounded-xl px-6',
                    cancelButton: 'rounded-xl px-6'
                }
            });

            if (result.isConfirmed) {
                document.getElementById(formId).submit();
            }
        });
    }

    function confirmDeleteReminder(formId) {
        window.withSwal(async (Swal) => {
            const result = await Swal.fire({
                title: 'Hapus pengingat?',
                text: 'Pengingat ini akan dihentikan secara permanen.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#9f403d',
                cancelButtonColor: '#737c7f',
                confirmButtonText: 'Ya, hapus!',
                cancelButtonText: 'Batal',
                customClass: {
                    popup: 'rounded-2xl',
                    confirmButton: 'rounded-xl px-6',
                    cancelButton: 'rounded-xl px-6'
                }
            });

            if (result.isConfirmed) {
                document.getElementById(formId).submit();
            }
        });
    }
</script>
@endpush
@endsection
