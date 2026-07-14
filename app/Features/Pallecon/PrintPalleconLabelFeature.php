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
        $label = (string) ($overrides['label'] ?? config('services.bartender.labels.wet_mustard_test', ''));

        if ($label === '') {
            throw new \RuntimeException('No Wet Mustard test label configured. Set BARTENDER_LABEL_WET_MUSTARD_TEST.');
        }

        $namedDataSources = $this->defaultNamedDataSources($pallecon, $overrides);

        if (! empty($overrides['named_data_sources']) && is_array($overrides['named_data_sources'])) {
            $namedDataSources = array_merge($namedDataSources, $overrides['named_data_sources']);
        }

        $dataEntryControls = $this->defaultDataEntryControls($pallecon, $overrides, $namedDataSources);

        if (! empty($overrides['data_entry_controls']) && is_array($overrides['data_entry_controls'])) {
            $dataEntryControls = array_merge($dataEntryControls, $overrides['data_entry_controls']);
        }

        $queryPrompts = $this->defaultQueryPrompts($namedDataSources, $overrides);

        if (! empty($overrides['query_prompts']) && is_array($overrides['query_prompts'])) {
            $queryPrompts = array_merge($queryPrompts, $overrides['query_prompts']);
        }

        return $this->client->printFromLibrary($label, [
            'copies' => $copies,
            'printer' => $overrides['printer'] ?? null,
            'serial_numbers' => $overrides['serial_numbers'] ?? 1,
            'named_data_sources' => $namedDataSources,
            'data_entry_controls' => $dataEntryControls,
            'query_prompts' => $queryPrompts,
        ]);
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
            : ($pallecon->start_time ? $pallecon->start_time->copy() : now());

        $shelfLifeMonths = max(1, (int) ($overrides['shelf_life_months'] ?? config('services.bartender.wet_mustard_shelf_life_months', 5)));
        $bestBeforeEnd = $productionDate->copy()->addMonthsNoOverflow($shelfLifeMonths)->endOfMonth();

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

        return [
            'BatchNumber' => $pallecon->batchRecord?->batch_number,
            'MoNumber' => $pallecon->mo_number,
            'ManufacturingOrder' => $pallecon->mo_number,
            'ProductID' => $resolvedProductId !== '' ? $resolvedProductId : ($labelRow['ProductId'] ?? null),
            'ProductIdParam' => $resolvedProductId !== '' ? $resolvedProductId : ($labelRow['ProductId'] ?? null),
            'DateOfProduction' => $productionDate->format('d/m/Y'),
            'BestBeforeEnd' => $bestBeforeEnd->format('d/m/Y'),
            'BestBeforeEndMonthYear' => $bestBeforeEnd->format('m/Y'),
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
            'EnergyKJ' => $labelRow['EnergyKJ'] ?? null,
            'EnergyKcal' => $labelRow['EnergyKcal'] ?? null,
            'Protein' => $labelRow['Protein'] ?? null,
            'TotalCarbohydrates' => $labelRow['TotalCarbohydrates'] ?? null,
            'OfWhichSugar' => $labelRow['OfWhichSugar'] ?? null,
            'Fibre' => $labelRow['Fibre'] ?? null,
            'Fat' => $labelRow['Fat'] ?? null,
            'Saturates' => $labelRow['Saturates'] ?? null,
            'Salt' => $labelRow['Salt'] ?? null,
            'Kosher' => $labelRow['Kosher'] ?? null,
            'Halal' => $labelRow['Halal'] ?? null,
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
            : ($pallecon->start_time ? $pallecon->start_time->copy() : now());

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
