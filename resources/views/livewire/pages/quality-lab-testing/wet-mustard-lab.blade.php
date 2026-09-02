<?php

use App\Models\ManufacturingOrder;
use App\Models\User;
use App\Models\Wm005LabTestingEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('WM005 Wet Mustard Lab Testing')] class extends Component {
    public string $tested_date = '';
    public string $part_number = '';
    public string $product = '';
    public string $mo_number = '';
    public ?int $selected_manufacturing_order_id = null;
    public string $tested_time = '';
    public string $batch_number = '';
    public string $analytical_specification = '';
    public string $ph = '';
    public string $acidity_acetic = '';
    public string $acidity_citric = '';
    public string $salt = '';
    public string $viscosity_brookfield = '';
    public string $viscosity_bostwick = '';
    public string $aw = '';
    public string $solids = '';
    public string $appearance = '';
    public ?int $tested_by_user_id = null;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->tested_date = now()->toDateString();
        $this->tested_time = now()->format('H:i');
        $this->tested_by_user_id = auth()->id();
    }

    #[Computed]
    public function operators()
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function recent()
    {
        return Wm005LabTestingEntry::query()->latest('tested_date')->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function activeManufacturingOrders()
    {
        return ManufacturingOrder::query()
            ->with('product:id,product_name')
            ->where('status', 'selected')
            ->where('quantity_outstanding', '>', 0)
            ->orderByDesc('id')
            ->limit(300)
            ->get([
                'id',
                'mo_number',
                'winman_manufacturing_order_id',
                'winman_product_id',
                'product_id',
                'quantity_outstanding',
            ]);
    }

    public function updatedSelectedManufacturingOrderId($value): void
    {
        $this->mo_number = '';
        $this->part_number = '';
        $this->product = '';

        if (! $value) {
            return;
        }

        $order = ManufacturingOrder::query()
            ->with('product:id,product_name')
            ->find((int) $value);

        if (! $order) {
            return;
        }

        $this->mo_number = (string) ($order->winman_manufacturing_order_id ?: $order->mo_number ?: '');
        $this->part_number = (string) ($order->winman_product_id ?: '');
        $this->product = (string) ($order->product?->product_name ?: '');
    }

    public function save(): void
    {
        $data = $this->validate([
            'tested_date' => ['required', 'date'],
            'selected_manufacturing_order_id' => ['nullable', 'integer', 'exists:manufacturing_orders,id'],
            'part_number' => ['nullable', 'string', 'max:120'],
            'product' => ['nullable', 'string', 'max:255'],
            'mo_number' => ['nullable', 'string', 'max:120'],
            'tested_time' => ['nullable', 'date_format:H:i'],
            'batch_number' => ['nullable', 'string', 'max:120'],
            'analytical_specification' => ['nullable', 'string', 'max:255'],
            'ph' => ['nullable', 'numeric'],
            'acidity_acetic' => ['nullable', 'numeric'],
            'acidity_citric' => ['nullable', 'numeric'],
            'salt' => ['nullable', 'numeric'],
            'viscosity_brookfield' => ['nullable', 'numeric'],
            'viscosity_bostwick' => ['nullable', 'numeric'],
            'aw' => ['nullable', 'numeric'],
            'solids' => ['nullable', 'numeric'],
            'appearance' => ['nullable', 'string', 'max:255'],
            'tested_by_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $testedBy = User::query()->find((int) $data['tested_by_user_id']);
        if (! $testedBy) {
            $this->addError('tested_by_user_id', 'Selected operator is no longer available.');

            return;
        }

        Wm005LabTestingEntry::query()->create([
            'tested_date' => $data['tested_date'],
            'part_number' => trim((string) $data['part_number']) !== '' ? trim((string) $data['part_number']) : null,
            'product' => trim((string) $data['product']) !== '' ? trim((string) $data['product']) : null,
            'mo_number' => trim((string) $data['mo_number']) !== '' ? trim((string) $data['mo_number']) : null,
            'tested_time' => $data['tested_time'] !== '' ? $data['tested_time'].':00' : null,
            'batch_number' => trim((string) $data['batch_number']) !== '' ? trim((string) $data['batch_number']) : null,
            'analytical_specification' => trim((string) $data['analytical_specification']) !== '' ? trim((string) $data['analytical_specification']) : null,
            'ph' => $data['ph'] !== '' ? (float) $data['ph'] : null,
            'acidity_acetic' => $data['acidity_acetic'] !== '' ? (float) $data['acidity_acetic'] : null,
            'acidity_citric' => $data['acidity_citric'] !== '' ? (float) $data['acidity_citric'] : null,
            'salt' => $data['salt'] !== '' ? (float) $data['salt'] : null,
            'viscosity_brookfield' => $data['viscosity_brookfield'] !== '' ? (float) $data['viscosity_brookfield'] : null,
            'viscosity_bostwick' => $data['viscosity_bostwick'] !== '' ? (float) $data['viscosity_bostwick'] : null,
            'aw' => $data['aw'] !== '' ? (float) $data['aw'] : null,
            'solids' => $data['solids'] !== '' ? (float) $data['solids'] : null,
            'appearance' => trim((string) $data['appearance']) !== '' ? trim((string) $data['appearance']) : null,
            'tested_by' => trim((string) $testedBy->name),
            'recorded_by_user_id' => auth()->id(),
        ]);

        $this->flash = 'WM005 row saved.';
        unset($this->recent);
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                        <img src="{{ asset('lab-testing-icon.png') }}" alt="Wet Mustard Lab Testing" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                    </div>

                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">WM005 WET MUSTARD LAB TESTING</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">ANALYTICAL CHECKS PER PRODUCED BATCH</div>
                    </div>

                    <a href="{{ route('quality.lab-testing') }}" wire:navigate style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;text-decoration:none;">
                        Back to Quality &amp; Lab Testing
                    </a>
                </div>
            </div>

            <div style="padding:24px 26px;">

        @if ($flash)
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 mb-6">{{ $flash }}</div>
        @endif

        <form wire:submit="save" class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div style="background:#2d3f8f;" class="px-5 py-3 text-white font-semibold">New lab test row</div>
            <div class="p-5 grid gap-3 md:grid-cols-4">
                <div><label class="block text-xs text-gray-600 mb-1">Date</label><input type="date" wire:model.defer="tested_date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Time</label><input type="time" wire:model.defer="tested_time" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">Active manufacturing order</label>
                    <select wire:model.live="selected_manufacturing_order_id" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="">- select active MO -</option>
                        @foreach ($this->activeManufacturingOrders as $order)
                            <option value="{{ $order->id }}">
                                {{ $order->winman_manufacturing_order_id ?: $order->mo_number }} | Product ID: {{ $order->winman_product_id ?: '—' }} | Outstanding: {{ number_format((float) $order->quantity_outstanding, 3, '.', '') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div><label class="block text-xs text-gray-600 mb-1">MO number</label><input wire:model.defer="mo_number" readonly class="w-full rounded-md border-gray-300 bg-slate-50 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Product ID</label><input wire:model.defer="part_number" readonly class="w-full rounded-md border-gray-300 bg-slate-50 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Product</label><input wire:model.defer="product" readonly class="w-full rounded-md border-gray-300 bg-slate-50 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Batch number</label><input wire:model.defer="batch_number" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Analytical spec</label><input wire:model.defer="analytical_specification" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Appearance</label><input wire:model.defer="appearance" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">pH</label><input type="number" step="0.001" wire:model.defer="ph" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Acidity (acetic)</label><input type="number" step="0.001" wire:model.defer="acidity_acetic" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Acidity (citric)</label><input type="number" step="0.001" wire:model.defer="acidity_citric" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Salt</label><input type="number" step="0.001" wire:model.defer="salt" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Viscosity (Brookfield)</label><input type="number" step="0.001" wire:model.defer="viscosity_brookfield" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Viscosity (Bostwick)</label><input type="number" step="0.001" wire:model.defer="viscosity_bostwick" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">aW</label><input type="number" step="0.001" wire:model.defer="aw" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Solids</label><input type="number" step="0.001" wire:model.defer="solids" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Tested by</label>
                    <select wire:model.defer="tested_by_user_id" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="">- select operator -</option>
                        @foreach ($this->operators as $operator)
                            <option value="{{ $operator->id }}">{{ $operator->name }}</option>
                        @endforeach
                    </select>
                    @error('tested_by_user_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="px-5 pb-5"><x-primary-button type="submit">Save WM005</x-primary-button></div>
        </form>

        <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden mt-6">
            <div style="background:#2d3f8f;" class="px-4 py-2 text-sm font-semibold text-white">Recent WM005 entries</div>
            <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr style="background:#2d3f8f;color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;"><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Time</th><th class="px-3 py-2 text-left">Batch</th><th class="px-3 py-2 text-left">pH</th><th class="px-3 py-2 text-left">Salt</th><th class="px-3 py-2 text-left">Tested by</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($this->recent as $row)
                    <tr>
                        <td class="px-3 py-2">{{ $row->tested_date?->toDateString() }}</td>
                        <td class="px-3 py-2">{{ $row->tested_time ? \Illuminate\Support\Carbon::parse($row->tested_time)->format('H:i') : '—' }}</td>
                        <td class="px-3 py-2">{{ $row->batch_number ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row->ph ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row->salt ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row->tested_by }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-3 text-slate-500">No entries yet.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
            </div>
        </div>
    </div>
</div>
