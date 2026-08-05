<?php

use App\Domains\WinMan\Support\WinManConnection;
use App\Models\ProductMapping;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Product Mapping')] class extends Component {
    public int $limit = 500;

    public string $excludeProductId1 = '3001%';

    public string $excludeProductId2 = '9900%';

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @var array<int, array<string, mixed>> */
    public array $resolvedRows = [];

    /** @var array<string, int> */
    public array $summary = [
        'mapped_products' => 0,
        'stored_rows' => 0,
        'resolved_recipes' => 0,
        'mapping_issues' => 0,
    ];

    public ?string $flash = null;

    public ?string $error = null;

    public ?string $info = null;

    public ?string $lastSyncedAt = null;

    public function mount(): void
    {
        $this->loadStoredMappings();
    }

    public function syncMappings(): void
    {
        if (! $this->productMappingsTableExists()) {
            throw ValidationException::withMessages([
                'limit' => 'Product mapping table is missing. Run: php artisan migrate',
            ]);
        }

        $this->validateInput();

        try {
            $sql = "SELECT TOP (?)
                    S.Product AS StructureProduct,
                    P.ProductId AS StructureProductId,
                    P.Classification AS StructureClassification,
                    P.ProductDescription AS StructureProductDescription,
                    U.UnitOfMeasureDescription AS StructureUnitOfMeasureDescription,
                    P.BoxesPerPallet AS StructureBoxesPerPallet,
                    P.DC_008_DEC AS StructureBatchSizeKG,
                    S.Component AS ComponentProduct,
                    C.Classification AS ComponentClassification,
                    C.ProductId AS ComponentProductId,
                    C.ProductDescription AS ComponentProductDescription,
                    S.Quantity AS QuantityPerUnit,
                    S.FinishingEffectivityDate AS ComponentValidFrom,
                    S.FinishingEffectivityDate AS ComponentValidTo,
                    S.StructureLevel
                FROM Structures AS S
                INNER JOIN Products AS P
                    ON P.Product = S.Product
                LEFT JOIN UnitsOfMeasure AS U
                    ON P.UnitOfMeasure = U.UnitOfMeasure
                INNER JOIN Products AS C
                    ON C.Product = S.Component
                WHERE P.Classification IN ('29', '30')
                  AND C.Classification IN ('29', '30')
                  AND S.Type = 'C'
                  AND P.ProductId NOT LIKE ?
                  AND P.ProductId NOT LIKE ?
                ORDER BY P.ProductId, S.StructureLevel, C.ProductId";

            $sourceRows = app(WinManConnection::class)
                ->connection()
                ->select($sql, [$this->limit, $this->excludeProductId1, $this->excludeProductId2]);

            $syncedAt = now();

            $payload = array_map(function (object $row) use ($syncedAt): array {
                return [
                    'structure_product' => isset($row->StructureProduct) ? (int) $row->StructureProduct : null,
                    'structure_product_id' => trim((string) $row->StructureProductId),
                    'structure_classification' => isset($row->StructureClassification) ? trim((string) $row->StructureClassification) : null,
                    'structure_product_description' => trim((string) $row->StructureProductDescription),
                    'structure_unit_of_measure_description' => isset($row->StructureUnitOfMeasureDescription) ? trim((string) $row->StructureUnitOfMeasureDescription) : null,
                    'structure_boxes_per_pallet' => isset($row->StructureBoxesPerPallet) ? (int) $row->StructureBoxesPerPallet : null,
                    'structure_batch_size_kg' => isset($row->StructureBatchSizeKG) ? (float) $row->StructureBatchSizeKG : null,
                    'component_product' => isset($row->ComponentProduct) ? (int) $row->ComponentProduct : null,
                    'component_classification' => isset($row->ComponentClassification) ? trim((string) $row->ComponentClassification) : null,
                    'component_product_id' => trim((string) $row->ComponentProductId),
                    'component_product_description' => trim((string) $row->ComponentProductDescription),
                    'quantity_per_unit' => (float) $row->QuantityPerUnit,
                    'component_valid_from' => $this->normalizeDateTime($row->ComponentValidFrom ?? null),
                    'component_valid_to' => $this->normalizeDateTime($row->ComponentValidTo ?? null),
                    'structure_level' => (int) $row->StructureLevel,
                    'synced_at' => $syncedAt,
                    'created_at' => $syncedAt,
                    'updated_at' => $syncedAt,
                ];
            }, $sourceRows);

            DB::transaction(function () use ($payload): void {
                ProductMapping::query()->delete();

                foreach (array_chunk($payload, 250) as $chunk) {
                    ProductMapping::query()->insert($chunk);
                }
            });

            $this->flash = 'Product mappings synced: '.count($payload).' rows stored.';
            $this->error = null;
            $this->loadStoredMappings();
        } catch (\Throwable $e) {
            $this->flash = null;
            $this->error = 'Unable to sync live WinMan product mappings: '.$e->getMessage();
        }
    }

    private function validateInput(): void
    {
        if ($this->limit < 1 || $this->limit > 5000) {
            throw ValidationException::withMessages([
                'limit' => 'Limit must be between 1 and 5000.',
            ]);
        }

        if (trim($this->excludeProductId1) === '' || trim($this->excludeProductId2) === '') {
            throw ValidationException::withMessages([
                'excludeProductId1' => 'Both exclude filters are required.',
            ]);
        }
    }

    private function loadStoredMappings(): void
    {
        if (! $this->productMappingsTableExists()) {
            $this->rows = [];
            $this->resolvedRows = [];
            $this->summary = [
                'mapped_products' => 0,
                'stored_rows' => 0,
                'resolved_recipes' => 0,
                'mapping_issues' => 0,
            ];
            $this->lastSyncedAt = null;
            $this->info = 'Product mapping table is not created yet. Run: php artisan migrate';

            return;
        }

        $storedRows = ProductMapping::query()
            ->orderBy('structure_product_id')
            ->orderBy('structure_level')
            ->orderBy('component_product_id')
            ->get();

        $this->lastSyncedAt = ProductMapping::query()->max('synced_at');

        $this->rows = $storedRows->map(function (ProductMapping $mapping): array {
            return [
                'structure_product' => $mapping->structure_product,
                'structure_product_id' => (string) $mapping->structure_product_id,
                'structure_classification' => (string) ($mapping->structure_classification ?? ''),
                'structure_product_description' => (string) $mapping->structure_product_description,
                'structure_unit_of_measure_description' => (string) ($mapping->structure_unit_of_measure_description ?? ''),
                'structure_boxes_per_pallet' => $mapping->structure_boxes_per_pallet,
                'structure_batch_size_kg' => $mapping->structure_batch_size_kg !== null ? (float) $mapping->structure_batch_size_kg : null,
                'component_product' => $mapping->component_product,
                'component_classification' => (string) ($mapping->component_classification ?? ''),
                'component_product_id' => (string) $mapping->component_product_id,
                'component_product_description' => (string) $mapping->component_product_description,
                'quantity_per_unit' => (float) $mapping->quantity_per_unit,
                'component_valid_from' => $mapping->component_valid_from?->format('Y-m-d H:i:s'),
                'component_valid_to' => $mapping->component_valid_to?->format('Y-m-d H:i:s'),
                'structure_level' => (int) $mapping->structure_level,
            ];
        })->all();

        $groupedByStructure = collect($this->rows)->groupBy('structure_product_id');

        $this->resolvedRows = $groupedByStructure->map(function ($group, string $structureProductId) use ($groupedByStructure): array {
            $first = $group->first();

            $directRecipeRow = collect($group)->first(fn (array $row): bool => $this->isRecipeProductId((string) $row['component_product_id']));
            $intermediateRow = collect($group)->first(fn (array $row): bool => $this->isIntermediateProductId((string) $row['component_product_id']));

            $resolvedRecipeRow = $directRecipeRow;
            $secondHopRecipeRow = null;

            if ($resolvedRecipeRow === null && $intermediateRow !== null) {
                $secondHopRecipeRow = collect($groupedByStructure->get((string) $intermediateRow['component_product_id'], []))
                    ->first(fn (array $row): bool => $this->isRecipeProductId((string) $row['component_product_id']));

                $resolvedRecipeRow = $secondHopRecipeRow;
            }

            $recipeProductId = $resolvedRecipeRow['component_product_id'] ?? null;
            $boxesPerPallet = $first['structure_boxes_per_pallet'] ?? null;
            $batchSizeKg = $first['structure_batch_size_kg'] ?? null;

            $mappingIssues = [];

            if ($recipeProductId === null || trim((string) $recipeProductId) === '') {
                $mappingIssues[] = 'Recipe not matched';
            }

            if ($boxesPerPallet !== null && (int) $boxesPerPallet === 0) {
                $mappingIssues[] = 'Boxes per pallet is 0';
            }

            if ($batchSizeKg !== null && (float) $batchSizeKg == 0.0) {
                $mappingIssues[] = 'Batch size KG is 0';
            }

            return [
                'structure_product' => (int) ($first['structure_product'] ?? 0),
                'structure_product_id' => $structureProductId,
                'structure_classification' => (string) ($first['structure_classification'] ?? ''),
                'structure_product_description' => (string) ($first['structure_product_description'] ?? ''),
                'structure_unit_of_measure_description' => (string) ($first['structure_unit_of_measure_description'] ?? ''),
                'structure_boxes_per_pallet' => $boxesPerPallet,
                'structure_batch_size_kg' => $batchSizeKg,
                'recipe_product_id' => $recipeProductId,
                'recipe_product_description' => $resolvedRecipeRow['component_product_description'] ?? null,
                'intermediate_product_id' => $intermediateRow['component_product_id'] ?? null,
                'has_mapping_issue' => $mappingIssues !== [],
                'mapping_issue_reasons' => $mappingIssues,
            ];
        })->sortBy('structure_product_id')->values()->all();

        $this->summary = [
            'mapped_products' => count($this->resolvedRows),
            'stored_rows' => count($this->rows),
            'resolved_recipes' => count(array_filter(
                $this->resolvedRows,
                static fn (array $row): bool => isset($row['recipe_product_id']) && $row['recipe_product_id'] !== null && $row['recipe_product_id'] !== '',
            )),
            'mapping_issues' => count(array_filter(
                $this->resolvedRows,
                static fn (array $row): bool => (bool) ($row['has_mapping_issue'] ?? false),
            )),
        ];

        $this->info = $this->rows === []
            ? 'No product mapping snapshot is stored yet. Use Sync from WinMan to capture the current structure-to-recipe mapping.'
            : 'Stored snapshots are for later reference only. Manufacturing-order creation continues to use the live WinMan BOM.';
    }

    private function isRecipeProductId(string $productId): bool
    {
        return str_starts_with($productId, '3001') || str_starts_with($productId, '9900');
    }

    private function isIntermediateProductId(string $productId): bool
    {
        return str_starts_with($productId, '5001');
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function productMappingsTableExists(): bool
    {
        try {
            return Schema::hasTable('product_mappings');
        } catch (\Throwable) {
            return false;
        }
    }
}; ?>

<div class="py-8">
    <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">Product Mapping</h2>
            <p class="mt-1 text-sm text-slate-600">Stored WinMan structure-to-recipe mapping snapshot for classifications 29 and 30, structure type C. Sync stores which products map to which recipes for later reference.</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('settings.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.admin') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">General</a>
                <a href="{{ route('settings.recipes') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.recipes') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Recipes</a>
                <a href="{{ route('settings.product-mapping') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.product-mapping') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Product Mapping</a>
                <a href="{{ route('settings.operator-sync') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.operator-sync') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Operator Sync</a>
            </div>
        </div>

        @if ($error)
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $error }}</div>
        @endif

        @if ($flash)
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $flash }}</div>
        @endif

        @if ($info)
            <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">{{ $info }}</div>
        @endif

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-sky-700">Mapped Products</div>
                <div class="mt-1 text-2xl font-semibold text-sky-900">{{ $summary['mapped_products'] }}</div>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Resolved Recipes</div>
                <div class="mt-1 text-2xl font-semibold text-emerald-900">{{ $summary['resolved_recipes'] }}</div>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-amber-700">Stored Structure Rows</div>
                <div class="mt-1 text-2xl font-semibold text-amber-900">{{ $summary['stored_rows'] }}</div>
            </div>
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-red-700">Mapping Issues</div>
                <div class="mt-1 text-2xl font-semibold text-red-900">{{ $summary['mapping_issues'] }}</div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-medium text-slate-900">Resolved Product to Recipe Mapping</h3>
                        <p class="mt-1 text-sm text-slate-600">This summary resolves each stored structure ProductId to its recipe ProductId for later reference.</p>
                        <p class="mt-1 text-xs text-slate-500">
                            @if ($lastSyncedAt)
                                Last synced: {{ $lastSyncedAt }}
                            @else
                                No sync has been stored yet.
                            @endif
                        </p>
                    </div>
                    <x-primary-button type="button" wire:click="syncMappings">Sync from WinMan</x-primary-button>
                </div>
            </div>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-3 py-3">Structure ProductId</th>
                        <th class="px-3 py-3">Product Description</th>
                        <th class="px-3 py-3">Unit Of Measure</th>
                        <th class="px-3 py-3">Boxes / Pallet</th>
                        <th class="px-3 py-3">Batch Size KG</th>
                        <th class="px-3 py-3">Recipe ProductId</th>
                        <th class="px-3 py-3">Recipe Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white text-slate-700">
                    @forelse ($resolvedRows as $row)
                        <tr class="{{ ($row['has_mapping_issue'] ?? false) ? 'animate-pulse bg-red-100 text-red-900' : '' }}">
                            <td class="px-3 py-2 font-medium text-slate-900">{{ $row['structure_product_id'] }}</td>
                            <td class="px-3 py-2">{{ $row['structure_product_description'] }}</td>
                            <td class="px-3 py-2">{{ $row['structure_unit_of_measure_description'] !== '' ? $row['structure_unit_of_measure_description'] : '—' }}</td>
                            <td class="px-3 py-2">{{ $row['structure_boxes_per_pallet'] !== null ? $row['structure_boxes_per_pallet'] : '—' }}</td>
                            <td class="px-3 py-2">{{ $row['structure_batch_size_kg'] !== null ? number_format((float) $row['structure_batch_size_kg'], 5, '.', '') : '—' }}</td>
                            <td class="px-3 py-2">{{ $row['recipe_product_id'] ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $row['recipe_product_description'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">No stored product mappings yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    </div>
</div>
