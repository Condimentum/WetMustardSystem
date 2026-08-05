<?php

use App\Domains\WinMan\Support\WinManConnection;
use App\Models\Recipe;
use App\Models\RecipeCard;
use App\Models\RecipeIngredient;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Recipes')] class extends Component {
    public string $searchProductId = '3001%';

    public int $limit = 500;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @var array<int, array<string, mixed>> */
    public array $recipeRows = [];

    /** @var array<string, int> */
    public array $summary = [
        'structure_products' => 0,
        'components' => 0,
    ];

    public bool $showRecipeModal = false;

    public ?string $selectedRecipeCode = null;

    public string $selectedRecipeDescription = '';

    /** @var array<int, string> */
    public array $batchSizeInputs = [''];

    public string $plcRecipeNumber = '';

    public string $documentReference = '';

    public string $revisionNo = '';

    public string $issueDate = '';

    public string $reasonForIssue = '';

    /** @var array<int, string> */
    public array $stepInputs = [''];

    /** @var array<int, array<string, mixed>> */
    public array $selectedRecipeComponents = [];

    public ?string $modalFlash = null;

    public ?string $flash = null;

    public ?string $error = null;

    public ?string $info = null;

    public function mount(): void
    {
        $this->flash = session('status');
        $this->loadRecipes();
    }

    public function refreshRecipes(): void
    {
        $this->validateInput();
        $this->loadRecipes();
    }

    private function validateInput(): void
    {
        if ($this->limit < 1 || $this->limit > 5000) {
            throw ValidationException::withMessages([
                'limit' => 'Limit must be between 1 and 5000.',
            ]);
        }
    }

    private function loadRecipes(): void
    {
        $this->error = null;
        $this->info = null;

        $pattern = trim($this->searchProductId) !== '' ? trim($this->searchProductId) : '3001%';

        if (! str_contains($pattern, '%') && ! str_contains($pattern, '_')) {
            $pattern .= '%';
        }

        try {
            $sql = "SELECT TOP (?)
                    S.Product AS StructureProduct,
                    P.ProductId AS StructureProductId,
                    P.Classification AS StructureClassification,
                    P.ProductDescription AS StructureProductDescription,
                    S.Component AS ComponentProduct,
                    C.ProductId AS ComponentProductId,
                    C.ProductDescription AS ComponentProductDescription,
                    S.Quantity AS QuantityPerUnit,
                    S.FinishingEffectivityDate AS ComponentValidFrom,
                    S.FinishingEffectivityDate AS ComponentValidTo,
                    S.StructureLevel
                FROM Structures AS S
                INNER JOIN Products AS P ON P.Product = S.Product
                INNER JOIN Products AS C ON C.Product = S.Component
                WHERE P.Classification = ?
                  AND S.Type = ?
                  AND P.ProductId LIKE ?
                ORDER BY P.ProductId, S.StructureLevel, C.ProductId";

            $rows = app(WinManConnection::class)
                ->connection()
                ->select($sql, [$this->limit, 30, 'C', $pattern]);

            $this->rows = array_map(static function (object $row): array {
                return [
                    'structure_product' => (int) $row->StructureProduct,
                    'structure_product_id' => (string) $row->StructureProductId,
                    'structure_classification' => (int) $row->StructureClassification,
                    'structure_product_description' => (string) $row->StructureProductDescription,
                    'component_product' => (int) $row->ComponentProduct,
                    'component_product_id' => (string) $row->ComponentProductId,
                    'component_product_description' => (string) $row->ComponentProductDescription,
                    'quantity_per_unit' => (float) $row->QuantityPerUnit,
                    'component_valid_from' => $row->ComponentValidFrom !== null ? (string) $row->ComponentValidFrom : null,
                    'component_valid_to' => $row->ComponentValidTo !== null ? (string) $row->ComponentValidTo : null,
                    'component_valid_from_display' => $row->ComponentValidFrom !== null ? substr((string) $row->ComponentValidFrom, 0, 10) : null,
                    'component_valid_to_display' => $row->ComponentValidTo !== null ? substr((string) $row->ComponentValidTo, 0, 10) : null,
                    'structure_level' => (int) $row->StructureLevel,
                ];
            }, $rows);

            $cardsByCode = collect();
            if ($this->recipeCardsTableExists()) {
                $cardsByCode = RecipeCard::query()
                    ->whereIn('recipe_code', array_values(array_unique(array_map(static fn (array $r): string => $r['structure_product_id'], $this->rows))))
                    ->get()
                    ->keyBy('recipe_code');
            } else {
                $this->info = 'Recipe-card metadata table is not created yet. Run: php artisan migrate';
            }

            $this->rows = array_map(function (array $row) use ($cardsByCode): array {
                $card = $cardsByCode->get($row['structure_product_id']);

                $row['has_local_card'] = $card !== null;
                $row['local_revision'] = $card?->revision_no;
                $row['local_issue_date'] = $card?->issue_date?->format('Y-m-d');

                return $row;
            }, $this->rows);

            $this->recipeRows = collect($this->rows)
                ->groupBy('structure_product_id')
                ->map(function ($group, $recipeCode): array {
                    $first = $group->first();

                    return [
                        'structure_product' => (int) $first['structure_product'],
                        'structure_product_id' => (string) $recipeCode,
                        'structure_product_description' => (string) $first['structure_product_description'],
                        'has_local_card' => (bool) ($first['has_local_card'] ?? false),
                    ];
                })
                ->sortBy('structure_product_id')
                ->values()
                ->all();

            $this->summary = [
                'structure_products' => count($this->recipeRows),
                'components' => count($this->rows),
            ];
        } catch (\Throwable $e) {
            $this->rows = [];
            $this->recipeRows = [];
            $this->summary = ['structure_products' => 0, 'components' => 0];
            $this->error = 'Unable to read live WinMan structures: '.$e->getMessage();
        }
    }

    public function openRecipeModal(string $recipeCode): void
    {
        $this->resetValidation();
        $this->modalFlash = null;

        if (! $this->recipeCardsTableExists()) {
            $this->error = 'Recipe card metadata is not available yet. Run: php artisan migrate';

            return;
        }

        $recipeCode = trim($recipeCode);
        $row = collect($this->recipeRows)->firstWhere('structure_product_id', $recipeCode);

        $this->selectedRecipeCode = $recipeCode;
        $this->selectedRecipeDescription = is_array($row)
            ? (string) ($row['structure_product_description'] ?? '')
            : '';

        $card = RecipeCard::query()->where('recipe_code', $recipeCode)->first();

        $storedBatchSizes = [];
        if ($card !== null) {
            $storedBatchSizes = is_array($card->batch_sizes_kg) ? $card->batch_sizes_kg : [];

            if ($storedBatchSizes === [] && $card->batch_size_kg !== null && (float) $card->batch_size_kg > 0) {
                $storedBatchSizes = [(float) $card->batch_size_kg];
            }
        }

        $this->batchSizeInputs = $storedBatchSizes !== []
            ? array_map(fn ($size): string => $this->formatBatchSize((float) $size), $storedBatchSizes)
            : [''];
        $this->plcRecipeNumber = (string) ($card?->plc_recipe_number ?? '');
        $this->documentReference = (string) ($card?->document_reference ?? '');
        $this->revisionNo = (string) ($card?->revision_no ?? '');
        $this->issueDate = $card?->issue_date?->format('Y-m-d') ?? '';
        $this->reasonForIssue = (string) ($card?->reason_for_issue ?? '');
        $this->stepInputs = $card !== null && is_array($card->steps) && $card->steps !== []
            ? array_values(array_map(static fn ($step): string => (string) $step, $card->steps))
            : [''];

        $this->selectedRecipeComponents = $this->loadRecipeIngredientsForDisplay($recipeCode);

        $this->showRecipeModal = true;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadRecipeIngredientsForDisplay(string $recipeCode): array
    {
        $recipe = Recipe::query()->where('recipe_code', $recipeCode)->first();
        if ($recipe !== null) {
            $ingredients = RecipeIngredient::query()
                ->where('recipe_id', $recipe->id)
                ->whereNull('variant_id')
                ->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')
                ->orderBy('sequence')
                ->orderBy('material_code')
                ->get();

            if ($ingredients->isEmpty()) {
                $ingredients = RecipeIngredient::query()
                    ->where('recipe_id', $recipe->id)
                    ->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('sequence')
                    ->orderBy('material_code')
                    ->get();
            }

            if ($ingredients->isNotEmpty()) {
                return $ingredients->map(static function (RecipeIngredient $ingredient): array {
                    return [
                        'material_code' => (string) $ingredient->material_code,
                        'material_description' => (string) ($ingredient->material_description ?? ''),
                        'percentage' => $ingredient->percentage !== null ? (float) $ingredient->percentage : null,
                        'required_quantity' => $ingredient->required_quantity !== null ? (float) $ingredient->required_quantity : null,
                        'uom' => (string) ($ingredient->uom ?? 'KG'),
                        'sequence' => $ingredient->sequence,
                        'variant_id' => $ingredient->variant_id,
                        'source' => 'recipe',
                    ];
                })->values()->all();
            }
        }

        $displayBatchSizeKg = $this->resolveDisplayBatchSizeKg();

        return collect($this->rows)
            ->filter(static fn (array $component): bool => (string) $component['structure_product_id'] === $recipeCode)
            ->sortBy([
                ['structure_level', 'asc'],
                ['component_product_id', 'asc'],
            ])
            ->map(static function (array $component) use ($displayBatchSizeKg): array {
                $factor = isset($component['quantity_per_unit']) ? (float) $component['quantity_per_unit'] : 0;

                return [
                    'material_code' => (string) ($component['component_product_id'] ?? ''),
                    'material_description' => (string) ($component['component_product_description'] ?? ''),
                    'percentage' => $factor > 0 ? $factor * 100 : null,
                    'required_quantity' => $displayBatchSizeKg !== null ? $factor * $displayBatchSizeKg : ($factor > 0 ? $factor : null),
                    'uom' => 'KG',
                    'sequence' => (int) ($component['structure_level'] ?? 99999),
                    'variant_id' => null,
                    'source' => 'winman',
                ];
            })
            ->values()
            ->all();
    }

    private function resolveDisplayBatchSizeKg(): ?float
    {
        foreach ($this->batchSizeInputs as $size) {
            if (! is_numeric($size)) {
                continue;
            }

            $numeric = round((float) $size, 3);
            if ($numeric > 0) {
                return $numeric;
            }
        }

        return null;
    }

    public function closeRecipeModal(): void
    {
        $this->showRecipeModal = false;
    }

    public function saveRecipeModal(): void
    {
        if (! $this->recipeCardsTableExists()) {
            throw ValidationException::withMessages([
                'selectedRecipeCode' => 'Recipe card metadata table is missing. Run: php artisan migrate',
            ]);
        }

        if ($this->selectedRecipeCode === null || trim($this->selectedRecipeCode) === '') {
            throw ValidationException::withMessages([
                'selectedRecipeCode' => 'Select a recipe before saving.',
            ]);
        }

        $validated = $this->validate([
            'batchSizeInputs' => ['array', 'max:20'],
            'batchSizeInputs.*' => ['nullable', 'numeric', 'gt:0'],
            'plcRecipeNumber' => ['nullable', 'string', 'max:255'],
            'documentReference' => ['nullable', 'string', 'max:255'],
            'revisionNo' => ['nullable', 'string', 'max:255'],
            'issueDate' => ['nullable', 'date'],
            'reasonForIssue' => ['nullable', 'string', 'max:4000'],
            'stepInputs' => ['array', 'max:40'],
            'stepInputs.*' => ['nullable', 'string', 'max:500'],
        ]);

        $batchSizes = $this->parseBatchSizesFromInputs($validated['batchSizeInputs'] ?? []);

        $steps = array_values(array_filter(array_map(
            static fn ($line): string => trim((string) $line),
            $validated['stepInputs'] ?? [],
        ), static fn (string $line): bool => $line !== ''));

        $updateData = [
            'batch_size_kg' => $batchSizes !== [] ? $batchSizes[0] : null,
            'batch_sizes_kg' => $batchSizes !== [] ? $batchSizes : null,
            'plc_recipe_number' => trim((string) ($validated['plcRecipeNumber'] ?? '')) !== '' ? trim((string) $validated['plcRecipeNumber']) : null,
            'revision_no' => trim((string) ($validated['revisionNo'] ?? '')) !== '' ? trim((string) $validated['revisionNo']) : null,
            'issue_date' => ($validated['issueDate'] ?? '') !== '' ? $validated['issueDate'] : null,
            'reason_for_issue' => trim((string) ($validated['reasonForIssue'] ?? '')) !== '' ? trim((string) $validated['reasonForIssue']) : null,
            'steps' => $steps === [] ? null : $steps,
            'layout_config' => null,
        ];

        $documentReferenceSaved = false;
        if ($this->recipeCardsColumnExists('document_reference')) {
            $updateData['document_reference'] = trim((string) ($validated['documentReference'] ?? '')) !== ''
                ? strtoupper(trim((string) $validated['documentReference']))
                : null;
            $documentReferenceSaved = true;
        }

        RecipeCard::query()->updateOrCreate(
            ['recipe_code' => $this->selectedRecipeCode],
            $updateData,
        );

        session()->flash('status', $documentReferenceSaved
            ? 'Recipe card information saved.'
            : 'Recipe card information saved. Document Reference will be saved after running migrations.');
        $this->showRecipeModal = false;
        $this->redirectRoute('settings.recipes', navigate: true);
    }

    private function recipeCardsColumnExists(string $column): bool
    {
        try {
            return Schema::hasColumn('recipe_cards', $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function recipeCardsTableExists(): bool
    {
        try {
            return Schema::hasTable('recipe_cards');
        } catch (\Throwable) {
            return false;
        }
    }

    public function addBatchSizeInput(): void
    {
        if (count($this->batchSizeInputs) >= 20) {
            return;
        }

        $this->batchSizeInputs[] = '';
    }

    public function removeBatchSizeInput(int $index): void
    {
        if (! array_key_exists($index, $this->batchSizeInputs)) {
            return;
        }

        unset($this->batchSizeInputs[$index]);
        $this->batchSizeInputs = array_values($this->batchSizeInputs);

        if ($this->batchSizeInputs === []) {
            $this->batchSizeInputs = [''];
        }
    }

    public function addStepInput(): void
    {
        if (count($this->stepInputs) >= 40) {
            return;
        }

        $this->stepInputs[] = '';
    }

    public function removeStepInput(int $index): void
    {
        if (! array_key_exists($index, $this->stepInputs)) {
            return;
        }

        unset($this->stepInputs[$index]);
        $this->stepInputs = array_values($this->stepInputs);

        if ($this->stepInputs === []) {
            $this->stepInputs = [''];
        }
    }

    /** @param array<int, mixed> $inputs
     *  @return array<int, float>
     */
    private function parseBatchSizesFromInputs(array $inputs): array
    {
        $sizes = [];
        foreach ($inputs as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }

            if (! is_numeric($trimmed)) {
                throw ValidationException::withMessages([
                    'batchSizeInputs' => 'Batch sizes must be numeric values.',
                ]);
            }

            $size = round((float) $trimmed, 3);
            if ($size <= 0) {
                throw ValidationException::withMessages([
                    'batchSizeInputs' => 'Batch sizes must be greater than 0.',
                ]);
            }

            $sizes[] = $size;
        }

        $sizes = array_values(array_unique($sizes, SORT_NUMERIC));
        sort($sizes, SORT_NUMERIC);

        return $sizes;
    }

    private function formatBatchSize(float $size): string
    {
        $formatted = number_format($size, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}; ?>

<div class="py-8">
    <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">Recipes</h2>
            <p class="mt-1 text-sm text-slate-600">Live WinMan recipe structures for mustard products (classification 30, structure type C). This replaces static batch-card handling.</p>
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
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $info }}</div>
        @endif

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-4">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Structure ProductId Filter</label>
                    <input type="text" wire:model.defer="searchProductId" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="3001%" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Max Rows</label>
                    <input type="number" min="1" max="5000" wire:model.defer="limit" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500" />
                    @error('limit')
                        <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>
                <div class="flex items-end justify-end">
                    <x-primary-button type="button" wire:click="refreshRecipes">Refresh</x-primary-button>
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-sky-700">Structure Products</div>
                <div class="mt-1 text-2xl font-semibold text-sky-900">{{ $summary['structure_products'] }}</div>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-amber-700">Component Rows</div>
                <div class="mt-1 text-2xl font-semibold text-amber-900">{{ $summary['components'] }}</div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-3 py-3">Structure ID</th>
                        <th class="px-3 py-3">Recipe ID</th>
                        <th class="px-3 py-3">Recipe Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white text-slate-700">
                    @forelse ($recipeRows as $row)
                        <tr>
                            <td class="px-3 py-2">{{ $row['structure_product'] }}</td>
                            <td class="px-3 py-2 font-medium text-slate-900">
                                <button type="button" wire:click="openRecipeModal('{{ $row['structure_product_id'] }}')" class="text-sky-700 hover:text-sky-900 hover:underline">
                                    {{ $row['structure_product_id'] }}
                                </button>
                            </td>
                            <td class="px-3 py-2">{{ $row['structure_product_description'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-slate-500">No structures found for the current filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($showRecipeModal)
            <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-2 sm:p-4">
                <div class="my-2 flex max-h-[94vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl lg:max-w-4xl">
                    <div class="flex items-start justify-between border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Recipe Card - {{ $selectedRecipeCode }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $selectedRecipeDescription !== '' ? $selectedRecipeDescription : 'Store additional recipe document details.' }}</p>
                        </div>
                        <button type="button" wire:click="closeRecipeModal" class="rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-700">Close</button>
                    </div>

                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-3 sm:px-5 sm:py-4">
                        @if ($modalFlash)
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ $modalFlash }}</div>
                        @endif

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Batch Sizes (kg)</label>
                                <div class="space-y-2">
                                    @foreach ($batchSizeInputs as $index => $value)
                                        <div class="flex items-center gap-2">
                                            <input type="number" step="0.001" min="0.001" wire:model.defer="batchSizeInputs.{{ $index }}" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="e.g. 500" />
                                            <button type="button" wire:click="removeBatchSizeInput({{ $index }})" class="inline-flex items-center rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">Remove</button>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="mt-2 flex items-center gap-2">
                                    <button type="button" wire:click="addBatchSizeInput" class="inline-flex items-center rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Add batch size</button>
                                    <span class="text-xs text-slate-500">Up to 20 sizes.</span>
                                </div>
                                <div class="mt-1 text-xs text-slate-500">Manufacturing Orders will ask the operator to choose when multiple sizes are configured.</div>
                                @error('batchSizeInputs')<div class="mt-1 text-xs text-red-600">{{ $message }}</div>@enderror
                                @error('batchSizeInputs.*')<div class="mt-1 text-xs text-red-600">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">PLC Recipe Number</label>
                                <input type="text" wire:model.defer="plcRecipeNumber" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500" />
                                @error('plcRecipeNumber')<div class="mt-1 text-xs text-red-600">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Document Reference (e.g. WM023)</label>
                                <input type="text" wire:model.defer="documentReference" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="WM023" />
                                @error('documentReference')<div class="mt-1 text-xs text-red-600">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Revision No</label>
                                <input type="text" wire:model.defer="revisionNo" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500" />
                                @error('revisionNo')<div class="mt-1 text-xs text-red-600">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Issue Date</label>
                                <input type="date" wire:model.defer="issueDate" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500" />
                                @error('issueDate')<div class="mt-1 text-xs text-red-600">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Reason for Issue</label>
                            <textarea wire:model.defer="reasonForIssue" rows="3" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"></textarea>
                            @error('reasonForIssue')<div class="mt-1 text-xs text-red-600">{{ $message }}</div>@enderror
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-600">Ingredients (Recipe)</div>
                            <div class="hidden max-h-72 overflow-auto md:block">
                                <table class="min-w-full divide-y divide-slate-200 text-xs">
                                    <thead class="bg-white text-left uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th class="px-2 py-2">Material Code</th>
                                            <th class="px-2 py-2">Description</th>
                                            <th class="px-2 py-2">Required Qty</th>
                                            <th class="px-2 py-2">%</th>
                                            <th class="px-2 py-2">UoM</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 bg-white text-slate-700">
                                        @forelse ($selectedRecipeComponents as $component)
                                            <tr>
                                                <td class="whitespace-nowrap px-2 py-2 align-top">{{ $component['material_code'] }}</td>
                                                <td class="max-w-xs break-words px-2 py-2 align-top">{{ $component['material_description'] }}</td>
                                                <td class="whitespace-nowrap px-2 py-2 align-top">{{ $component['required_quantity'] !== null ? number_format((float) $component['required_quantity'], 3, '.', '') : '—' }}</td>
                                                <td class="whitespace-nowrap px-2 py-2 align-top">{{ $component['percentage'] !== null ? number_format((float) $component['percentage'], 3, '.', '') : '—' }}</td>
                                                <td class="whitespace-nowrap px-2 py-2 align-top">{{ $component['uom'] !== '' ? $component['uom'] : 'KG' }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="5" class="px-2 py-3 text-center text-slate-500">No recipe ingredients found for this recipe.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3 space-y-2 md:hidden">
                                @forelse ($selectedRecipeComponents as $component)
                                    <div class="rounded-md border border-slate-200 bg-white p-2">
                                        <div class="text-xs font-semibold text-slate-700">{{ $component['material_code'] }} - {{ $component['material_description'] }}</div>
                                        <div class="mt-1 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-slate-600">
                                            <div>Required Qty: {{ $component['required_quantity'] !== null ? number_format((float) $component['required_quantity'], 3, '.', '') : '—' }}</div>
                                            <div>%: {{ $component['percentage'] !== null ? number_format((float) $component['percentage'], 3, '.', '') : '—' }}</div>
                                            <div>UoM: {{ $component['uom'] !== '' ? $component['uom'] : 'KG' }}</div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-md border border-slate-200 bg-white px-2 py-3 text-center text-xs text-slate-500">No recipe ingredients found for this recipe.</div>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <div class="mb-1 flex items-center justify-between gap-2">
                                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Steps Involved</label>
                                <button type="button" wire:click="addStepInput" class="inline-flex items-center rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">Add step</button>
                            </div>

                            <div class="space-y-2">
                                @foreach ($stepInputs as $index => $step)
                                    <div class="flex items-center gap-2">
                                        <div class="w-12 shrink-0 rounded-md border border-slate-200 bg-slate-50 px-2 py-2 text-center text-xs font-semibold text-slate-600">{{ $index + 1 }}</div>
                                        <input type="text" wire:model.defer="stepInputs.{{ $index }}" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="Enter step {{ $index + 1 }}" />
                                        <button type="button" wire:click="removeStepInput({{ $index }})" class="inline-flex items-center rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">Remove</button>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-1 text-xs text-slate-500">Add each step on its own line item.</div>
                            @error('stepInputs')<div class="mt-1 text-xs text-red-600">{{ $message }}</div>@enderror
                            @error('stepInputs.*')<div class="mt-1 text-xs text-red-600">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-white px-4 py-3 sm:px-5 sm:py-4">
                        <x-secondary-button type="button" wire:click="closeRecipeModal">Cancel</x-secondary-button>
                        <x-primary-button type="button" wire:click="saveRecipeModal">Save Recipe Card</x-primary-button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
