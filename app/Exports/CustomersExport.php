<?php
// app/Exports/CustomersExport.php

namespace App\Exports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Carbon\Carbon;

class CustomersExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        protected string $msisdn      = '',
        protected string $fullname    = '',
        protected string $mothername  = '',
        protected string $trust_level = '',
        protected string $status      = '',
        protected string $date_debut  = '',
        protected string $date_fin    = '',
    ) {}

    public function query()
    {
        $query = Customer::query();

        if ($this->msisdn)      $query->where('MSISDN', 'like', '%'.$this->msisdn.'%');
        if ($this->fullname)    $query->where('FULL_NAME', 'like', '%'.$this->fullname.'%');
        if ($this->mothername)  $query->where('MOTHER_NAME', 'like', '%'.$this->mothername.'%');
        if ($this->trust_level) $query->where('TRUST_LEVEL', $this->trust_level);
        if ($this->status)      $query->where('STATUS', $this->status);
        if ($this->date_debut)  $query->whereDate('CREATE_TIME', '>=', $this->date_debut);
        if ($this->date_fin)    $query->whereDate('CREATE_TIME', '<=', $this->date_fin);

        return $query;
    }

    public function headings(): array
    {
        return ['#', 'Date création', 'MSISDN', 'Nom complet', 'Nom de la mère', 'Trust Level', 'Statut'];
    }

    public function map($customer): array
    {
        static $index = 0;
        $index++;

        $trustLevel = match((int) $customer->TRUST_LEVEL) {
            9 => 'Full KYC',
            3 => 'Lite Customer',
            1 => 'Unregistered Customer',
            default => 'Unknown',
        };

        $status = match($customer->STATUS) {
            '03' => 'Active',
            '06' => 'Closed',
            '02' => 'Pending Active',
            default => 'Inactif',
        };

        return [
            $index,
            Carbon::parse($customer->CREATE_TIME)->format('d/m/Y H:i'),
            $customer->MSISDN,
            $customer->FULL_NAME,
            $customer->MOTHER_NAME,
            $trustLevel,
            $status,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1B2F6E']],
            ],
        ];
    }
}