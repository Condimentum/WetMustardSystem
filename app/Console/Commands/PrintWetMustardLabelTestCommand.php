<?php

namespace App\Console\Commands;

use App\Features\Pallecon\PrintPalleconLabelFeature;
use App\Models\BatchRecord;
use App\Models\PalleconRecord;
use Illuminate\Console\Command;
use Throwable;

class PrintWetMustardLabelTestCommand extends Command
{
    protected $signature = 'label:wet-mustard:test-print
        {--send : Send the request to BarTender. Without this flag, only preview the payload context.}
        {--copies=1 : Number of copies to print}
        {--label= : Override label file name}
        {--printer= : Override printer name}
        {--batch=WM-TEST : Test batch number}
        {--mo=MO-TEST : Test MO number}
        {--ticket=TICKET-TEST : Test ticket number}
        {--serial=SERIAL-TEST : Test serial number}
        {--top-seal=TOP-TEST : Test top seal number}
        {--bottom-seal=BOTTOM-TEST : Test bottom seal number}
        {--liner=LINER-TEST : Test liner number}
        {--liner-batch=LINER-BATCH-TEST : Test liner batch code}
        {--product-id= : Override ProductId used for label mapping/query prompt}
        {--fill-weight=800 : Test fill weight}
        {--production-date= : Label production date (Y-m-d). Defaults to today}
        {--shelf-life-months=5 : Months to add for Best Before End derivation}';

    protected $description = 'Dry-run or send a Wet Mustard BarTender label print against the test template.';

    public function handle(PrintPalleconLabelFeature $printLabel): int
    {
        $label = (string) ($this->option('label') ?: config('services.bartender.labels.wet_mustard_test', ''));
        $printer = (string) ($this->option('printer') ?: config('services.bartender.default_printer', ''));

        $this->info('Wet Mustard Label Test');
        $this->line('  Base URL : '.(string) config('services.bartender.base_url', '(missing)'));
        $this->line('  Label    : '.$label);
        $this->line('  Printer  : '.$printer);
        $this->line('  Enabled  : '.((bool) config('services.bartender.enabled', false) ? 'true' : 'false'));
        $this->line('  Prod Date: '.((string) ($this->option('production-date') ?: now()->toDateString())));
        $this->line('  BBE +M   : '.(int) $this->option('shelf-life-months'));

        $pallecon = new PalleconRecord([
            'mo_number' => (string) $this->option('mo'),
            'ticket_number' => (string) $this->option('ticket'),
            'serial_number' => (string) $this->option('serial'),
            'top_seal_number' => (string) $this->option('top-seal'),
            'bottom_seal_number' => (string) $this->option('bottom-seal'),
            'liner_number' => (string) $this->option('liner'),
            'liner_batch_code' => (string) $this->option('liner-batch'),
            'fill_weight' => (float) $this->option('fill-weight'),
            'checked_at' => now(),
        ]);
        $pallecon->setRelation('batchRecord', new BatchRecord(['batch_number' => (string) $this->option('batch')]));

        /** @var mixed $payloadBuilder */
        $payloadBuilder = $printLabel;
        $payload = $payloadBuilder->buildPrintPayload($pallecon, (int) $this->option('copies'), [
            'label' => $label,
            'printer' => $printer,
            'product_id' => $this->option('product-id') ?: null,
            'production_date' => $this->option('production-date') ?: now()->toDateString(),
            'shelf_life_months' => (int) $this->option('shelf-life-months'),
        ]);

        if (! (bool) $this->option('send')) {
            $this->warn('Dry run only. Add --send to call BarTender.');
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        try {
            $result = $printLabel($pallecon, (int) $this->option('copies'), [
                'label' => (string) $payload['label'],
                'printer' => $printer,
                'product_id' => $this->option('product-id') ?: null,
                'production_date' => $this->option('production-date') ?: now()->toDateString(),
                'shelf_life_months' => (int) $this->option('shelf-life-months'),
            ]);
        } catch (Throwable $e) {
            $this->error('Print failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Print request accepted.');
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
