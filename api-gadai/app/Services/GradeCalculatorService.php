<?php

namespace App\Services;

class GradeCalculatorService
{
    const ADDITIONAL_FEE = 15000;

    const PRICE_TIERS = [
        ['min' => 200000, 'max' => 1450000, 'adjustment' => 0],
        ['min' => 1500000, 'max' => 3000000, 'adjustment' => 50000],
        ['min' => 3050000, 'max' => 6000000, 'adjustment' => 100000],
        ['min' => 6050000, 'max' => 9000000, 'adjustment' => 150000],
        ['min' => 9050000, 'max' => 12000000, 'adjustment' => 200000],
        ['min' => 12050000, 'max' => 14950000, 'adjustment' => 250000],
        ['min' => 15000000, 'max' => PHP_INT_MAX, 'adjustment' => 300000],
    ];

    public function calculateAllGrades(int $hargaBarang, string $pasarTrend = 'turun'): array
    {
        // 1. GRADE A DUS (BASE)
        // SOP: (Harga - 20%) +/- Adjustment
        $baseGradeA = $this->calculateGradeABase($hargaBarang, $pasarTrend);
        $gradeADus = $baseGradeA + self::ADDITIONAL_FEE;

        // 2. GRADE A TANPA DUS
        // SOP: Grade A - 15%
        $baseGradeATanpaDus = $this->roundPrice($baseGradeA * 0.85);
        // Validasi: Tidak boleh lebih dari Grade A
        if ($baseGradeATanpaDus >= $baseGradeA) $baseGradeATanpaDus = $baseGradeA - 50000;
        $gradeATanpaDus = $baseGradeATanpaDus + self::ADDITIONAL_FEE;

        // 3. GRADE B DUS
        // SOP: (Grade A - 10%) + 50.000
        $baseGradeBDus = $this->roundPrice(($baseGradeA * 0.90) + 50000);
        // Validasi Krusial: Grade B tidak boleh >= Grade A
        if ($baseGradeBDus >= $baseGradeA) {
            $baseGradeBDus = $baseGradeA - 50000;
        }
        $gradeBDus = $baseGradeBDus + self::ADDITIONAL_FEE;

        // 4. GRADE B TANPA DUS
        // SOP: Grade B - 20%
        $baseGradeBTanpaDus = $this->roundPrice($baseGradeBDus * 0.80);
        $gradeBTanpaDus = $baseGradeBTanpaDus + self::ADDITIONAL_FEE;

        // 5. GRADE C DUS
        // SOP: Grade B - 10%
        $baseGradeCDus = $this->roundPrice($baseGradeBDus * 0.90);
        // Validasi: Grade C harus di bawah Grade B
        if ($baseGradeCDus >= $baseGradeBDus) $baseGradeCDus = $baseGradeBDus - 50000;
        $gradeCDus = $baseGradeCDus + self::ADDITIONAL_FEE;

        // 6. GRADE C TANPA DUS
        // SOP: Grade C - 25%
        $baseGradeCTanpaDus = $this->roundPrice($baseGradeCDus * 0.75);
        $gradeCTanpaDus = $baseGradeCTanpaDus + self::ADDITIONAL_FEE;

        return [
            'grade_a_dus' => $gradeADus,
            'grade_a_tanpa_dus' => $gradeATanpaDus,
            'grade_b_dus' => $gradeBDus,
            'grade_b_tanpa_dus' => $gradeBTanpaDus,
            'grade_c_dus' => $gradeCDus,
            'grade_c_tanpa_dus' => $gradeCTanpaDus,
            
            'taksiran_a_dus' => $this->calculateTaksiran($gradeADus),
            'taksiran_a_tanpa_dus' => $this->calculateTaksiran($gradeATanpaDus),
            'taksiran_b_dus' => $this->calculateTaksiran($gradeBDus),
            'taksiran_b_tanpa_dus' => $this->calculateTaksiran($gradeBTanpaDus),
            'taksiran_c_dus' => $this->calculateTaksiran($gradeCDus),
            'taksiran_c_tanpa_dus' => $this->calculateTaksiran($gradeCTanpaDus),
        ];
    }

    private function calculateGradeABase(int $hargaBarang, string $pasarTrend): int
    {
        $basePrice = $hargaBarang * 0.80;
        $adjustment = $this->getAdjustment($hargaBarang);
        $finalPrice = ($pasarTrend === 'naik') ? ($basePrice + $adjustment) : ($basePrice - $adjustment);
        return $this->roundPrice($finalPrice);
    }

    private function calculateTaksiran(int $gradePrice): int
    {
        return (int) ($gradePrice * 1.10);
    }

    private function getAdjustment(int $hargaBarang): int
    {
        foreach (self::PRICE_TIERS as $tier) {
            if ($hargaBarang >= $tier['min'] && $hargaBarang <= $tier['max']) {
                return $tier['adjustment'];
            }
        }
        return 300000;
    }

   private function roundPrice(float $price): int
{
    if ($price <= 0) return 0;

    $ratusanRibu = floor($price / 100000) * 100000;
    $sisa = $price - $ratusanRibu;

    if ($sisa <= 12500) {
        return (int) $ratusanRibu; 
    } elseif ($sisa <= 37500) {
        return (int) ($ratusanRibu + 25000);
    } elseif ($sisa <= 62500) {
        return (int) ($ratusanRibu + 50000);
    } elseif ($sisa <= 87500) {
        return (int) ($ratusanRibu + 75000);
    } else {
        return (int) ($ratusanRibu + 100000);
    }
}
}