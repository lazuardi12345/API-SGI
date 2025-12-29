<?php

namespace App\Services;

class GradeCalculatorService
{
    // Biaya tambahan tetap untuk setiap grade
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

    /**
     * Menghitung semua Grade dan Taksiran
     */
    public function calculateAllGrades(int $hargaBarang, string $pasarTrend = 'turun'): array
    {
        // --- 1. GRADE A DUS (BASE) ---
        // Hitung harga dasar, bulatkan, lalu tambah 15rb
        $baseGradeA = $this->calculateGradeABase($hargaBarang, $pasarTrend);
        $gradeADus = $baseGradeA + self::ADDITIONAL_FEE;
        $taksiranADus = $this->calculateTaksiran($gradeADus);

        // --- 2. GRADE A TANPA DUS ---
        // (Grade A Base - 15%) + 15rb
        $gradeATanpaDus = $this->roundPrice($baseGradeA * 0.85) + self::ADDITIONAL_FEE;
        $taksiranATanpaDus = $this->calculateTaksiran($gradeATanpaDus);

        // --- 3. GRADE B DUS ---
        // ((Grade A Base - 10%) + 50rb) + 15rb
        $baseGradeB = $this->roundPrice(($baseGradeA * 0.90) + 50000);
        $gradeBDus = $baseGradeB + self::ADDITIONAL_FEE;
        $taksiranBDus = $this->calculateTaksiran($gradeBDus);

        // --- 4. GRADE B TANPA DUS ---
        // (Grade B Base - 20%) + 15rb
        $gradeBTanpaDus = $this->roundPrice($baseGradeB * 0.80) + self::ADDITIONAL_FEE;
        $taksiranBTanpaDus = $this->calculateTaksiran($gradeBTanpaDus);

        // --- 5. GRADE C DUS ---
        // (Grade B Base - 10%) + 15rb
        $baseGradeC = $this->roundPrice($baseGradeB * 0.90);
        $gradeCDus = $baseGradeC + self::ADDITIONAL_FEE;
        $taksiranCDus = $this->calculateTaksiran($gradeCDus);

        // --- 6. GRADE C TANPA DUS ---
        // (Grade C Base - 25%) + 15rb
        $gradeCTanpaDus = $this->roundPrice($baseGradeC * 0.75) + self::ADDITIONAL_FEE;
        $taksiranCTanpaDus = $this->calculateTaksiran($gradeCTanpaDus);

        return [
            // Nilai Pinjaman (Grade)
            'grade_a_dus' => $gradeADus,
            'grade_a_tanpa_dus' => $gradeATanpaDus,
            'grade_b_dus' => $gradeBDus,
            'grade_b_tanpa_dus' => $gradeBTanpaDus,
            'grade_c_dus' => $gradeCDus,
            'grade_c_tanpa_dus' => $gradeCTanpaDus,
            
            // Nilai Taksiran (Grade + 10%)
            'taksiran_a_dus' => $taksiranADus,
            'taksiran_a_tanpa_dus' => $taksiranATanpaDus,
            'taksiran_b_dus' => $taksiranBDus,
            'taksiran_b_tanpa_dus' => $taksiranBTanpaDus,
            'taksiran_c_dus' => $taksiranCDus,
            'taksiran_c_tanpa_dus' => $taksiranCTanpaDus,
        ];
    }

    /**
     * Hitung Grade A Awal (80% Harga Barang +/- Adjustment)
     */
    private function calculateGradeABase(int $hargaBarang, string $pasarTrend): int
    {
        $basePrice = $hargaBarang * 0.80;
        $adjustment = $this->getAdjustment($hargaBarang);
        
        $finalPrice = ($pasarTrend === 'naik') 
            ? $basePrice + $adjustment 
            : $basePrice - $adjustment;
        
        return $this->roundPrice($finalPrice);
    }

    /**
     * Rumus Taksiran: (Grade + 10%)
     */
    private function calculateTaksiran(int $gradePrice): int
    {
        // Taksiran dihitung dari grade yang sudah termasuk biaya 15rb
        return (int) ($gradePrice * 1.10);
    }

    /**
     * Mendapatkan nilai penyesuaian berdasarkan tier harga
     */
    private function getAdjustment(int $hargaBarang): int
    {
        foreach (self::PRICE_TIERS as $tier) {
            if ($hargaBarang >= $tier['min'] && $hargaBarang <= $tier['max']) {
                return $tier['adjustment'];
            }
        }
        return 300000; // Default untuk harga di atas tier tertinggi
    }

    /**
     * Pembulatan harga agar rapi (ke 50rb atau 100rb terdekat)
     */
    private function roundPrice(float $price): int
    {
        $ratusanRibu = floor($price / 100000) * 100000;
        $sisa = $price - $ratusanRibu;

        if ($sisa < 25000) {
            return (int) $ratusanRibu;
        } elseif ($sisa <= 51000) {
            return (int) ($ratusanRibu + 50000);
        } else {
            return (int) ($ratusanRibu + 100000);
        }
    }
}