<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\DataNasabah;
use App\Models\DetailGadai;
use App\Models\GadaiHp;
use App\Models\Type;
use App\Models\HargaHp;
use App\Models\GradeHp;
use App\Models\DokumenPendukungHp;

class GadaiUlangController extends Controller
{
    const DOKUMEN_SOP_HP = [
        'Android' => ['body','imei','about','akun','admin','cam_depan','cam_belakang','rusak'],
        'Samsung' => ['body','imei','about','samsung_account','admin','cam_depan','cam_belakang','galaxy_store'],
        'iPhone'  => ['body','imei','about','icloud','battery','3utools','iunlocker','cek_pencurian'],
    ];

    public function checkNasabah(Request $request)
    {
        $nik = $request->input('nik');

        if (!$nik) {
            return response()->json(['success' => false, 'message' => 'NIK wajib diisi.'], 422);
        }

        $nasabah = DataNasabah::where('nik', $nik)->first();

        if (!$nasabah) {
            return response()->json(['success' => false, 'message' => 'Nasabah belum terdaftar.'], 404);
        }

        $riwayatGadai = DetailGadai::where('nasabah_id', $nasabah->id)
            ->with(['hp'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Nasabah ditemukan.',
            'data' => [
                'nasabah' => $nasabah,
                'total_gadai' => $riwayatGadai->count(),
                'riwayat_gadai' => $riwayatGadai
            ]
        ]);
    }

    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'nasabah.id'           => 'required|exists:data_nasabah,id', 
            'barang.type_hp_id'    => 'required|exists:type_hp,id',
            'barang.grade_type'    => 'required|string',
            'detail.tanggal_gadai' => 'required|date',
            'detail.jatuh_tempo'   => 'required|date',
            'detail.type_id'       => 'required|exists:types,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $nasabahId    = $request->input('nasabah.id');
            $detailInput  = $request->input('detail', []);
            $barangInput  = $request->input('barang', []);

            $nasabah = DataNasabah::findOrFail($nasabahId);

            // STEP 1: GENERATE NOMOR GADAI
            $tanggal = date_create($detailInput['tanggal_gadai']);
            [$day, $month, $year] = [$tanggal->format('d'), $tanggal->format('m'), $tanggal->format('Y')];

            $lastDetail = DetailGadai::lockForUpdate()->orderBy('id', 'desc')->first();
            $noNum      = $lastDetail ? (int) substr($lastDetail->no_nasabah, -4) + 1 : 1;
            $noNasabah  = $month . substr($year, 2) . str_pad($noNum, 4, '0', STR_PAD_LEFT);

            $typeMaster = Type::find($detailInput['type_id']);
            $noGadai = "SGI-$day-$month-$year-" . ($typeMaster->nomor_type ?? '00') . "-" . str_pad($noNum, 4, '0', STR_PAD_LEFT);

            $detail = DetailGadai::create([
                'no_gadai'      => $noGadai,
                'no_nasabah'    => $noNasabah,
                'nasabah_id'    => $nasabah->id,
                'tanggal_gadai' => $detailInput['tanggal_gadai'],
                'jatuh_tempo'   => $detailInput['jatuh_tempo'],
                'type_id'       => $detailInput['type_id'],
                'taksiran'      => 0, 
                'uang_pinjaman' => 0, 
                'status'        => 'proses',
            ]);


            // STEP 2: SIMPAN DATA BARANG HP
            $pureGradeType = strtolower(str_replace(['-', ' '], '_', $barangInput['grade_type']));

            $barang = GadaiHp::create([
                'detail_gadai_id' => $detail->id,
                'nama_barang'     => $barangInput['nama_barang'] ?? 'Smartphone',
                'merk_hp_id'      => $barangInput['merk_hp_id'] ?? null,
                'type_hp_id'      => $barangInput['type_hp_id'],
                'grade_hp_id'     => $barangInput['grade_hp_id'] ?? null,
                'grade_type'      => $pureGradeType,
                'grade_nominal'   => 0,
                'imei'            => $barangInput['imei'] ?? null,
                'warna'           => $barangInput['warna'] ?? null,
                'ram'             => $barangInput['ram'] ?? null,
                'rom'             => $barangInput['rom'] ?? null,
                'kunci_password'  => $barangInput['kunci_password'] ?? null,
                'kunci_pin'       => $barangInput['kunci_pin'] ?? null,
                'kunci_pola'      => $barangInput['kunci_pola'] ?? null,
            ]);

            if (!empty($barangInput['kerusakan'])) {
                $barang->kerusakanList()->sync($barangInput['kerusakan']);
            }
            if (!empty($barangInput['kelengkapan'])) {
                $barang->kelengkapanList()->sync($barangInput['kelengkapan']);
            }

            // STEP 3: KALKULASI (DENGAN PEMBULATAN RIBUAN)

            $hargaMaster = HargaHp::where('type_hp_id', $barang->type_hp_id)->first();
            if (!$hargaMaster) throw new \Exception("Harga Master untuk tipe ini belum diatur.");

            $gradeData = GradeHp::where('harga_hp_id', $hargaMaster->id)->first();
            if (!$gradeData) throw new \Exception("Data Grade HP tidak ditemukan.");

            if (!$barang->grade_hp_id) $barang->update(['grade_hp_id' => $gradeData->id]);

            $colPinjaman = 'grade_' . $pureGradeType;
            $colTaksiran = 'taksiran_' . $pureGradeType;
            $basePinjaman = (float) ($gradeData->{$colPinjaman} ?? 0);
            $baseTaksiran = (float) ($gradeData->{$colTaksiran} ?? 0);
            $totalPersenKerusakan = DB::table('gadai_hp_kerusakan')
                ->where('gadai_hp_id', $barang->id)
                ->join('kerusakan', 'kerusakan.id', '=', 'gadai_hp_kerusakan.kerusakan_id')
                ->sum('kerusakan.persen') ?: 0;
            $multiplier = max(0, min(1, (100 - (float)$totalPersenKerusakan) / 100));
            $rawPinjaman = $basePinjaman * $multiplier;
            $rawTaksiran = $baseTaksiran * $multiplier;
            $finalUangPinjaman = floor($rawPinjaman / 1000) * 1000;
            $finalTaksiran     = floor($rawTaksiran / 1000) * 1000;
            $detail->update([
                'taksiran'      => $finalTaksiran,
                'uang_pinjaman' => $finalUangPinjaman,
            ]);
            $barang->update(['grade_nominal' => $finalUangPinjaman]);

            // STEP 4: UPLOAD DOKUMEN SOP

            $this->uploadDokumenSop($request, $barang, $nasabah, $detail);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Gadai Ulang HP berhasil diproses',
                'data'    => [
                    'no_gadai'      => $detail->no_gadai,
                    'uang_pinjaman' => $finalUangPinjaman,
                    'taksiran'      => $finalTaksiran,
                    'nasabah'       => $nasabah->nama_lengkap
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 
                'message' => 'Gagal: ' . $e->getMessage()
            ], 500);
        }
    }

    private function uploadDokumenSop($request, $barang, $nasabah, $detail)
    {
        $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
        $folderBarang  = "{$folderNasabah}/handphone/{$detail->no_gadai}";
        
        $merk = $request->input('barang.merk_name', 'Android');
        $fields = self::DOKUMEN_SOP_HP[$merk] ?? self::DOKUMEN_SOP_HP['Android'];
        $paths = [];

        foreach ($fields as $field) {
            if ($request->hasFile("barang.dokumen_pendukung.$field")) {
                $file = $request->file("barang.dokumen_pendukung.$field");
                $filename = "{$field}_" . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $paths[$field] = $file->storeAs($folderBarang, $filename, 'minio');
            }
        }

        if (!empty($paths)) {
            DokumenPendukungHp::create(array_merge(['gadai_hp_id' => $barang->id], $paths));
        }
    }
}