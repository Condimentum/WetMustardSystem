<?php

namespace App\Domains\WinMan\Data;

/**
 * Read-only view of an eligible WinMan manufacturing order (scope §11.2).
 *
 * winman_manufacturing_order is the internal BIGINT used for stored-procedure
 * context; winman_manufacturing_order_id is the human-readable MO reference.
 * winman_product_id is always treated as a string.
 */
final readonly class ManufacturingOrderData
{
    public function __construct(
        public int $winmanManufacturingOrder,
        public string $winmanManufacturingOrderId,
        public int $winmanProductInternal,
        public string $winmanProductId,
        public string $productDescription,
        public string $systemType,
        public float $plannedQuantity,
        public float $quantityOutstanding,
        public ?int $classification,
        public ?int $unitOfMeasure,
        public ?string $unitOfMeasureDescription,
        public ?string $dueDate,
        public ?string $lastModifiedDate,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $classification = property_exists($row, 'Classification') && $row->Classification !== null
            ? (int) $row->Classification
            : null;

        $unitOfMeasure = property_exists($row, 'UnitOfMeasure') && $row->UnitOfMeasure !== null
            ? (int) $row->UnitOfMeasure
            : null;

        $unitOfMeasureDescription = property_exists($row, 'UnitOfMeasureDescription') && $row->UnitOfMeasureDescription !== null
            ? (string) $row->UnitOfMeasureDescription
            : null;

        return new self(
            winmanManufacturingOrder: (int) $row->ManufacturingOrder,
            winmanManufacturingOrderId: (string) $row->ManufacturingOrderId,
            winmanProductInternal: (int) $row->Product,
            winmanProductId: (string) $row->ProductId,
            productDescription: (string) $row->ProductDescription,
            systemType: (string) $row->SystemType,
            plannedQuantity: (float) $row->Quantity,
            quantityOutstanding: (float) $row->QuantityOutstanding,
            classification: $classification,
            unitOfMeasure: $unitOfMeasure,
            unitOfMeasureDescription: $unitOfMeasureDescription,
            dueDate: $row->DueDate !== null ? (string) $row->DueDate : null,
            lastModifiedDate: $row->LastModifiedDate !== null ? (string) $row->LastModifiedDate : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'winman_manufacturing_order' => $this->winmanManufacturingOrder,
            'winman_manufacturing_order_id' => $this->winmanManufacturingOrderId,
            'winman_product_internal' => $this->winmanProductInternal,
            'winman_product_id' => $this->winmanProductId,
            'product_description' => $this->productDescription,
            'system_type' => $this->systemType,
            'planned_quantity' => $this->plannedQuantity,
            'quantity_outstanding' => $this->quantityOutstanding,
            'classification' => $this->classification,
            'unit_of_measure' => $this->unitOfMeasure,
            'unit_of_measure_description' => $this->unitOfMeasureDescription,
            'due_date' => $this->dueDate,
            'last_modified_date' => $this->lastModifiedDate,
        ];
    }
}
