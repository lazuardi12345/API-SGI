<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LaporanHarianCheckerController extends Controller
{
    public function cetakLaporanHarian(Request $request)
    {
        try {
            $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();
            $laporanTabel = [];
            $no = 1;
            
            $grandTotalDebet = 0;
            $grandTotalKredit = 0;

            // 1. GADAI BARU (KREDIT/UANG KELUAR)
            $gadaiBaru = DB::table('detail_gadai')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    'types.nama_type', 
                    DB::raw('count(*) as qty'), 
                    DB::raw('SUM(CAST(detail_gadai.uang_pinjaman AS UNSIGNED)) as total_nominal')
                )
                ->whereDate('detail_gadai.tanggal_gadai', $tanggal)
                ->whereNull('detail_gadai.deleted_at')
                ->groupBy('types.nama_type')
                ->get();

            foreach ($gadaiBaru as $gb) {
                $nominal = (float)$gb->total_nominal;
                if ($nominal > 0) {
                    $laporanTabel[] = [
                        'no' => $no++,
                        'keterangan' => "Pencairan Gadai: " . $gb->nama_type,
                        'qty' => (int)$gb->qty,
                        'debet' => 0,
                        'kredit' => $nominal,
                    ];
                    $grandTotalKredit += $nominal;
                }
            }

            // 2. PELUNASAN (DEBET/UANG MASUK)
            $pelunasan = DB::table('detail_gadai')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    'types.nama_type', 
                    DB::raw('count(*) as qty'), 
                    DB::raw('SUM(CAST(detail_gadai.nominal_bayar AS UNSIGNED)) as total_nominal')
                )
                ->where('detail_gadai.status', 'lunas')
                ->whereDate('detail_gadai.tanggal_bayar', $tanggal)
                ->whereNull('detail_gadai.deleted_at')
                ->groupBy('types.nama_type')
                ->get();

            foreach ($pelunasan as $p) {
                $nominal = (float)$p->total_nominal;
                if ($nominal > 0) {
                    $laporanTabel[] = [
                        'no' => $no++,
                        'keterangan' => "Pelunasan Gadai: " . $p->nama_type,
                        'qty' => (int)$p->qty,
                        'debet' => $nominal,
                        'kredit' => 0,
                    ];
                    $grandTotalDebet += $nominal;
                }
            }

            // 3. PERPANJANGAN (DEBET/UANG MASUK)
            $perpanjangan = DB::table('perpanjangan_tempo')
                ->join('detail_gadai', 'perpanjangan_tempo.detail_gadai_id', '=', 'detail_gadai.id')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    'types.nama_type', 
                    DB::raw('count(*) as qty'), 
                    DB::raw('SUM(CAST(perpanjangan_tempo.nominal_admin AS UNSIGNED)) as total_admin')
                )
                ->whereDate('perpanjangan_tempo.tanggal_perpanjangan', $tanggal)
                ->groupBy('types.nama_type')
                ->get();

            foreach ($perpanjangan as $pj) {
                $nominal = (float)$pj->total_admin;
                if ($nominal > 0) {
                    $laporanTabel[] = [
                        'no' => $no++,
                        'keterangan' => "Admin Perpanjangan: " . $pj->nama_type,
                        'qty' => (int)$pj->qty,
                        'debet' => $nominal,
                        'kredit' => 0,
                    ];
                    $grandTotalDebet += $nominal;
                }
            }

            // 4. LELANG (DEBET/UANG MASUK)
            $lelang = DB::table('pelelangan')
                ->join('detail_gadai', 'pelelangan.detail_gadai_id', '=', 'detail_gadai.id')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    'types.nama_type', 
                    DB::raw('count(*) as qty'), 
                    DB::raw('SUM(CAST(pelelangan.nominal_diterima AS UNSIGNED)) as total_lelang')
                )
                ->where('pelelangan.status_lelang', 'terlelang')
                ->whereDate('pelelangan.waktu_bayar', $tanggal)
                ->groupBy('types.nama_type')
                ->get();

            foreach ($lelang as $l) {
                $nominal = (float)$l->total_lelang;
                if ($nominal > 0) {
                    $laporanTabel[] = [
                        'no' => $no++,
                        'keterangan' => "Lelang: " . $l->nama_type,
                        'qty' => (int)$l->qty,
                        'debet' => $nominal,
                        'kredit' => 0,
                    ];
                    $grandTotalDebet += $nominal;
                }
            }

            // AMBIL SALDO TERAKHIR DENGAN AMAN
            $lastTrans = DB::table('transaksi_brankas')
                ->orderBy('id', 'desc')
                ->first();

            // Pastikan mengambil properti saldo_akhir, bukan objeknya
            $saldoAkhir = $lastTrans ? (float)$lastTrans->saldo_akhir : 0;

            return response()->json([
                'success' => true,
                'metadata' => [
                    'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                    'checker_name' => auth()->user()->name ?? 'Petugas',
                ],
                'data_tabel' => $laporanTabel, 
                'footer_total' => [
                    'total_debet' => $grandTotalDebet,
                    'total_kredit' => $grandTotalKredit,
                    'selisih' => $grandTotalDebet - $grandTotalKredit
                ],
                'saldo_kas' => [
                    'raw' => $saldoAkhir,
                    'formatted' => 'Rp ' . number_format($saldoAkhir, 0, ',', '.')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
                'line' => $e->getLine() 
            ], 500);
        }
    }

    public function cetakLaporanSerahTerima(Request $request)
{
    try {
        $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();

        // Mengambil data gadai yang lunas pada tanggal tersebut
        // Mengasumsikan ada model DetailGadai yang berelasi dengan barang_gadai_hp atau sejenisnya
        $dataLunas = DB::table('detail_gadai')
            ->join('nasabahs', 'detail_gadai.no_nasabah', '=', 'nasabahs.no_nasabah')
            ->leftJoin('barang_gadai_hp', 'detail_gadai.id', '=', 'barang_gadai_hp.detail_gadai_id')
            ->leftJoin('merk_hp', 'barang_gadai_hp.merk_hp_id', '=', 'merk_hp.id')
            ->leftJoin('type_hp', 'barang_gadai_hp.type_hp_id', '=', 'type_hp.id')
            ->select(
                'detail_gadai.id as gadai_id',
                'detail_gadai.no_gadai',
                'nasabahs.nama_nasabah',
                'detail_gadai.tanggal_bayar',
                'detail_gadai.nominal_bayar',
                'barang_gadai_hp.nama_barang',
                'merk_hp.nama_merk',
                'type_hp.nama_type as model_hp',
                'barang_gadai_hp.imei',
                'barang_gadai_hp.warna',
                'barang_gadai_hp.ram',
                'barang_gadai_hp.rom',
                'barang_gadai_hp.kunci_password',
                'barang_gadai_hp.grade_type'
            )
            ->where('detail_gadai.status', 'lunas')
            ->whereDate('detail_gadai.tanggal_bayar', $tanggal)
            ->whereNull('detail_gadai.deleted_at')
            ->get();

        $formattedData = $dataLunas->map(function ($item) {
            $kelengkapan = DB::table('detail_kelengkapan_hp')
                ->join('kelengkapan_hp', 'detail_kelengkapan_hp.kelengkapan_id', '=', 'kelengkapan_hp.id')
                ->where('barang_hp_id', $item->gadai_id) 
                ->pluck('nama_kelengkapan')
                ->toArray();

            return [
                'no_gadai' => $item->no_gadai,
                'nasabah' => $item->nama_nasabah,
                'detail_barang' => [
                    'nama' => $item->nama_barang . " " . $item->nama_merk . " " . $item->model_hp,
                    'spesifikasi' => "RAM {$item->ram}/ROM {$item->rom} GB",
                    'imei' => $item->imei,
                    'warna' => $item->warna,
                    'password' => $item->kunci_password ?? '-',
                    'grade' => strtoupper(str_replace('_', ' ', $item->grade_type)),
                ],
                'kelengkapan' => $kelengkapan,
                'status_bayar' => [
                    'tanggal' => Carbon::parse($item->tanggal_bayar)->format('d-m-Y'),
                    'nominal' => (float)$item->nominal_bayar
                ]
            ];
        });

        return response()->json([
            'success' => true,
            'judul' => 'LAPORAN SERAH TERIMA BARANG (LUNAS)',
            'metadata' => [
                'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                'total_unit' => $dataLunas->count()
            ],
            'data' => $formattedData
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat detail: ' . $e->getMessage()
        ], 500);
    }
}
}