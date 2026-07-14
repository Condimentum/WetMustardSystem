<?php

namespace App\Features\Pallecon;

use App\Domains\Printing\Support\BarTenderPrintPortalClient;
use App\Domains\WinMan\Jobs\FetchWetMustardLabelDataJob;
use App\Domains\WinMan\Jobs\ResolveWetMustardRecordPickerTokenJob;
use App\Models\PalleconRecord;
use Illuminate\Support\Carbon;

class PrintPalleconLabelFeature
{
    public function __construct(
        private readonly BarTenderPrintPortalClient $client,
        private readonly FetchWetMustardLabelDataJob $fetchWetMustardLabelData,
        private readonly ResolveWetMustardRecordPickerTokenJob $resolveRecordPickerToken,
    ) {
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function __invoke(PalleconRecord $pallecon, int $copies = 1, array $overrides = []): array
    {
        $payload = $this->buildPrintPayload($pallecon, $copies, $overrides);

        return $this->client->printFromLibrary(
            (string) $payload['label'],
            is_array($payload['options'] ?? null) ? $payload['options'] : [],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{label:string, options:array<string, mixed>}
     */
    public function buildPrintPayload(PalleconRecord $pallecon, int $copies = 1, array $overrides = []): array
    {
        $label = (string) ($overrides['label'] ?? config('services.bartender.labels.wet_mustard_test', ''));

        if ($label === '') {
            throw new \RuntimeException('No Wet Mustard test label configured. Set BARTENDER_LABEL_WET_MUSTARD_TEST.');
        }

        $namedDataSources = $this->defaultNamedDataSources($pallecon, $overrides);

        if (! empty($overrides['named_data_sources']) && is_array($overrides['named_data_sources'])) {
            $namedDataSources = array_merge($namedDataSources, $overrides['named_data_sources']);
        }

        $useDataEntryControls = array_key_exists('use_data_entry_controls', $overrides)
            ? (bool) $overrides['use_data_entry_controls']
            : (bool) config('services.bartender.use_data_entry_controls', true);

        $dataEntryControls = [];

        if ($useDataEntryControls) {
            $dataEntryControls = $this->defaultDataEntryControls($pallecon, $overrides, $namedDataSources);

            if (! empty($overrides['data_entry_controls']) && is_array($overrides['data_entry_controls'])) {
                $dataEntryControls = array_merge($dataEntryControls, $overrides['data_entry_controls']);
            }
        }

        $queryPrompts = $this->defaultQueryPrompts($namedDataSources, $overrides);

        if (! empty($overrides['query_prompts']) && is_array($overrides['query_prompts'])) {
            $queryPrompts = array_merge($queryPrompts, $overrides['query_prompts']);
        }

        $printOptions = [
            'copies' => $copies,
            'printer' => $overrides['printer'] ?? null,
            'serial_numbers' => $overrides['serial_numbers'] ?? 1,
            'named_data_sources' => $namedDataSources,
            'query_prompts' => $queryPrompts,
        ];

        if (! empty($dataEntryControls)) {
            $printOptions['data_entry_controls'] = $dataEntryControls;
        }

        return [
            'label' => $label,
            'options' => $printOptions,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function defaultNamedDataSources(PalleconRecord $pallecon, array $overrides = []): array
    {
        $mo = $pallecon->batchRecord?->manufacturingOrder;
        $batchProduct = $pallecon->batchRecord?->product;
        $productionDate = isset($overrides['production_date'])
            ? Carbon::parse((string) $overrides['production_date'])
            : now();

        $julianCode = sprintf('%02d%03d', (int) $productionDate->format('y'), $productionDate->dayOfYear);

        $resolvedProductId = trim((string) ($overrides['product_id']
            ?? $batchProduct?->winman_product_id
            ?? $mo?->winman_product_id
            ?? ''));

        // ProductId must follow WinMan Products.ProductId used on the batch header.
        $labelRow = ($this->fetchWetMustardLabelData)(
            null,
            $resolvedProductId !== '' ? $resolvedProductId : ($mo?->winman_product_id ?? null),
        );
        $bestBefore = $this->resolveBestBefore($productionDate, $labelRow, $overrides);

        return [
            'BatchNumber' => $pallecon->batchRecord?->batch_number,
            'MoNumber' => $pallecon->mo_number,
            'ManufacturingOrder' => $pallecon->mo_number,
            'ManufacturingOrderId' => $pallecon->mo_number,
            'ProductID' => $resolvedProductId !== '' ? $resolvedProductId : ($labelRow['ProductId'] ?? null),
            'ProductIdParam' => $resolvedProductId !== '' ? $resolvedProductId : ($labelRow['ProductId'] ?? null),
            'DateOfProduction' => $productionDate->format('Y-m-d'),
            'DatePacked' => $productionDate->format('Y-m-d'),
            'BestBeforeEnd' => $bestBefore['display'],
            'BestBeforeEndMonthYear' => $bestBefore['month_year'],
            'LotNumber' => $julianCode,
            'BatchCode' => $julianCode,
            'BatchCodeJulian' => $julianCode,
            'FillWeight' => $pallecon->fill_weight,
            'Product' => $labelRow['Product'] ?? null,
            'ProductId' => $resolvedProductId !== '' ? $resolvedProductId : ($labelRow['ProductId'] ?? null),
            'ProductDescription' => $labelRow['ProductDescription'] ?? null,
            'Barcode' => $labelRow['Barcode'] ?? null,
            'CountryId' => $labelRow['CountryId'] ?? null,
            'Weight' => $labelRow['Weight'] ?? null,
            'AltReferences' => $labelRow['AltReferences'] ?? null,
            'HandInstruct' => $labelRow['HandInstruct'] ?? null,
            'AllergenInfo' => $labelRow['AllergenInfo'] ?? null,
            'Ingredients' => $labelRow['Ingredients'] ?? null,
            'BBEformat' => $labelRow['BBEformat'] ?? null,
            'BBE' => $labelRow['BBE'] ?? null,
            'EnergyKJ' => $this->roundOneDecimal($labelRow['EnergyKJ'] ?? null),
            'EnergyKcal' => $this->roundOneDecimal($labelRow['EnergyKcal'] ?? null),
            'Protein' => $this->roundOneDecimal($labelRow['Protein'] ?? null),
            'TotalCarbohydrates' => $this->roundOneDecimal($labelRow['TotalCarbohydrates'] ?? null),
            'OfWhichSugar' => $this->roundOneDecimal($labelRow['OfWhichSugar'] ?? null),
            'Fibre' => $this->roundOneDecimal($labelRow['Fibre'] ?? null),
            'Fat' => $this->roundOneDecimal($labelRow['Fat'] ?? null),
            'Saturates' => $this->roundOneDecimal($labelRow['Saturates'] ?? null),
            'Saturatess' => $this->roundOneDecimal($labelRow['Saturates'] ?? null),
            'Salt' => $this->roundOneDecimal($labelRow['Salt'] ?? null),
            'Kosher' => $labelRow['Kosher'] ?? null,
            'Halal' => $labelRow['Halal'] ?? null,
        ];
    }

    /**
     * @return string|null
     */
    private function roundOneDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric((string) $value)) {
            return null;
        }

        return number_format((float) $value, 1, '.', '');
    }

    /**
     * @param  array<string, mixed>  $labelRow
     * @param  array<string, mixed>  $overrides
     * @return array{display:string, month_year:string}
     */
    private function resolveBestBefore(Carbon $productionDate, array $labelRow, array $overrides = []): array
    {
        $format = strtoupper(trim((string) ($labelRow['BBEformat'] ?? '')));
        $shelfLifeValue = isset($labelRow['BBE']) && is_numeric((string) $labelRow['BBE'])
            ? max(1, (int) $labelRow['BBE'])
            : max(1, (int) ($overrides['shelf_life_months'] ?? config('services.bartender.wet_mustard_shelf_life_months', 5)));

        if ($format === 'DDMMYYYY') {
            $bestBeforeDate = $productionDate->copy()->addDays($shelfLifeValue);

            return [
                'display' => $bestBeforeDate->format('d/m/Y'),
                'month_year' => $bestBeforeDate->format('m/Y'),
            ];
        }

        $bestBeforeDate = $productionDate->copy()->addMonthsNoOverflow($shelfLifeValue)->endOfMonth();

        return [
            'display' => $format === 'MMYYYY' ? $bestBeforeDate->format('m/Y') : $bestBeforeDate->format('d/m/Y'),
            'month_year' => $bestBeforeDate->format('m/Y'),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $namedDataSources
     * @return array<string, scalar>
     */
    private function defaultDataEntryControls(PalleconRecord $pallecon, array $overrides = [], array $namedDataSources = []): array
    {
        $productionDate = isset($overrides['production_date'])
            ? Carbon::parse((string) $overrides['production_date'])
            : now();

        $fillWeight = $pallecon->fill_weight !== null
            ? rtrim(rtrim(number_format((float) $pallecon->fill_weight, 3, '.', ''), '0'), '.')
            : '1';
        $fillWeightWithUnit = str_ends_with(strtolower($fillWeight), 'kg')
            ? $fillWeight
            : $fillWeight.' kg';
        $moSuffix = preg_match('/(\d{4})$/', (string) ($pallecon->mo_number ?? ''), $matches) === 1
            ? $matches[1]
            : 'XXXX';
        $productPickerValue = trim((string) ($overrides['product_id']
            ?? $namedDataSources['ProductId']
            ?? $namedDataSources['ProductID']
            ?? ''));
        $recordPickerToken = $this->resolveRecordPickerToken($productPickerValue, $overrides);

        return [
            'Date Picker 1' => $productionDate->copy()->startOfDay()->format('Y-m-d H:i:s').'Z',
            'Number Input Box 2' => $fillWeightWithUnit,
            'Number Input Box 1' => '1',
            'Text Input Box 1' => $moSuffix,
            'ProductIdParam' => $productPickerValue,
            'Dropdown Record Picker 1' => $recordPickerToken,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resolveRecordPickerToken(string $productId, array $overrides = []): string
    {
        if (isset($overrides['dropdown_record_picker_1']) && trim((string) $overrides['dropdown_record_picker_1']) !== '') {
            return trim((string) $overrides['dropdown_record_picker_1']);
        }

        $token = ($this->resolveRecordPickerToken)($productId);

        return $token !== null ? $token : '1...';
    }

    /**
     * @param  array<string, scalar|null>  $namedDataSources
     * @param  array<string, mixed>  $overrides
     * @return array<string, scalar>
     */
    private function defaultQueryPrompts(array $namedDataSources, array $overrides = []): array
    {
        $productId = trim((string) ($overrides['product_id']
            ?? $namedDataSources['ProductId']
            ?? $namedDataSources['ProductID']
            ?? ''));

        return [
            'ProductIdParam' => $productId,
        ];
    }
}
