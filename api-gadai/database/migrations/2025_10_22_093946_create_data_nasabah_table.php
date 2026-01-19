<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::create('data_nasabah', function (Blueprint $table) {
        $table->id();
        $table->string('nama_lengkap');
        $table->string('nik')->unique();
        $table->text('alamat');
        $table->string('foto_ktp')->nullable();
        $table->string('no_hp');
        
        // List Lengkap Bank Indonesia untuk Enum
        $table->enum('bank', [
            // Bank Besar (Top Tier)
            'BCA', 'BRI', 'BNI', 'MANDIRI', 'BTN', 
            
            // Bank Digital (Populer di Fintech/Pinjol)
            'SEABANK', 'BANK_JAGO', 'NEO_COMMERCE', 'ALOO_BANK', 'BLU', 
            'LINE_BANK', 'DIGIBANK', 'TMRW', 'BANK_RAYA', 'HIBANK',
            
            // Bank Swasta Nasional
            'CIMB_NIAGA', 'PERMATA', 'DANAMON', 'PANIN', 'OCBC_NISP', 
            'MAYBANK', 'COMMONWEALTH', 'DBS', 'UOB', 'HSBC', 'STANDARD_CHARTERED',
            'ARTHA_GRAHA', 'MEGA', 'BUKOPIN', 'BTPN', 'SINARMAS', 'MESTIKA',
            
            // Bank Syariah
            'BSI', 'MUAMALAT', 'BCA_SYARIAH', 'MEGA_SYARIAH', 'PANIN_SYARIAH',
            'BUKOPIN_SYARIAH', 'BTPN_SYARIAH', 'VICTORIA_SYARIAH',
            
            // Bank Pembangunan Daerah (BPD) - Sering dipakai nasabah daerah
            'BANK_DKI', 'BANK_JABAR', 'BANK_JATENG', 'BANK_JATIM', 'BANK_DIY', 
            'BANK_JAMBI', 'BANK_SUMUT', 'BANK_RIAU_KEPRI', 'BANK_SUMSEL_BABEL', 
            'BANK_LAMPUNG', 'BANK_KALBAR', 'BANK_KALSEL', 'BANK_KALTIMTARA', 
            'BANK_KALTENG', 'BANK_SULSELBAR', 'BANK_SULUTGO', 'BANK_NTB', 
            'BANK_NTT', 'BANK_BALI', 'BANK_PAPUA', 'BANK_BENGKULU', 'BANK_SULTRA'
        ])->default('BCA');

        $table->string('no_rek')->nullable();
        $table->timestamps(); 
        $table->softDeletes(); 
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(table: 'data_nasabah');
    }
};
