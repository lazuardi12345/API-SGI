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

        // Ambil semua detail gadai yang lunas pada tanggal tersebut dengan SEMUA relasi barang
        $dataLunas = \App\Models\DetailGadai::with([
                'nasabah', 
                'hp.merk', 'hp.type_hp', 'hp.kelengkapanList',
                'perhiasan', 
                'logamMulia', 
                'retro'
            ])
            ->where('status', 'lunas')
            ->whereDate('tanggal_bayar', $tanggal)
            ->get();

        $formattedData = $dataLunas->map(function ($gadai) {
            $namaBarang = '-';
            $detailSpesifik = '-';
            $kelengkapan = [];

            // 1. Cek jika Gadai HP
            if ($gadai->hp) {
                $hp = $gadai->hp;
                $namaBarang = ($hp->nama_barang ?? 'HP') . " " . ($hp->merk->nama_merk ?? '') . " " . ($hp->type_hp->nama_type ?? '');
                $detailSpesifik = "IMEI: {$hp->imei} | Warna: {$hp->warna} | PW: {$hp->kunci_password}";
                $kelengkapan = $hp->kelengkapanList->pluck('nama_kelengkapan')->toArray();
            } 
            // 2. Cek jika Perhiasan
            elseif ($gadai->perhiasan) {
                $p = $gadai->perhiasan;
                $namaBarang = "Perhiasan: " . ($p->nama_barang ?? 'Emas');
                $detailSpesifik = "Berat: {$p->berat} gr | Kadar: {$p->kadar}% | Warna: {$p->warna}";
            }
            // 3. Cek jika Logam Mulia
            elseif ($gadai->logamMulia) {
                $lm = $gadai->logamMulia;
                $namaBarang = "Logam Mulia: " . ($lm->nama_barang ?? 'LM');
                $detailSpesifik = "Berat: {$lm->berat} gr | Brand: {$lm->brand}";
            }
            // 4. Cek jika Retro
            elseif ($gadai->retro) {
                $r = $gadai->retro;
                $namaBarang = "Barang Retro: " . ($r->nama_barang ?? 'Retro');
                $detailSpesifik = "Keterangan: {$r->keterangan}";
            }

            return [
                'no_gadai' => $gadai->no_gadai,
                'nasabah' => $gadai->nasabah->nama_lengkap ?? 'Nasabah Tidak Ditemukan',
                'nama_barang' => $namaBarang,
                'detail_spesifik' => $detailSpesifik,
                'kelengkapan' => $kelengkapan,
                'nominal_lunas' => (float)$gadai->nominal_bayar,
                'tanggal_bayar' => $gadai->tanggal_bayar ? Carbon::parse($gadai->tanggal_bayar)->format('d-m-Y') : '-',
            ];
        });

        // Hitung Grand Total Pelunasan
        $totalNominal = $formattedData->sum('nominal_lunas');

        return response()->json([
            'success' => true,
            'metadata' => [
                'total_item' => $formattedData->count(),
                'grand_total_lunas' => $totalNominal,
                'tanggal_laporan' => $tanggal
            ],
            'data' => $formattedData
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat detail serah terima: ' . $e->getMessage()
        ], 500);
    }
}

public function cetakLaporanPerpanjangan(Request $request)
{
    try {
        $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();

        // Ambil data perpanjangan yang SUDAH LUNAS bayar di tanggal tersebut
        $dataPerpanjangan = \App\Models\PerpanjanganTempo::with([
                'detailGadai.nasabah',
                'detailGadai.hp.merk',
                'detailGadai.hp.type_hp',
                'detailGadai.perhiasan',
                'detailGadai.logamMulia',
                'detailGadai.retro'
            ])
            ->where('status_bayar', 'lunas')
            ->whereDate('updated_at', $tanggal) // atau tgl bayar jika ada fieldnya
            ->get();

        $formattedPerpanjangan = $dataPerpanjangan->map(function ($p) {
            $gadai = $p->detailGadai;
            $namaBarang = '-';
            $detailBarang = '-';

            // Logic deteksi barang sama seperti cetak lunas
            if ($gadai->hp) {
                $namaBarang = "HP: " . ($gadai->hp->merk->nama_merk ?? '') . " " . ($gadai->hp->type_hp->nama_type ?? '');
                $detailBarang = "IMEI: " . ($gadai->hp->imei ?? '-');
            } elseif ($gadai->perhiasan) {
                $namaBarang = "Perhiasan: " . ($gadai->perhiasan->nama_barang ?? 'Emas');
                $detailBarang = "Berat: {$gadai->perhiasan->berat} gr | Kadar: {$gadai->perhiasan->kadar}%";
            } elseif ($gadai->logamMulia) {
                $namaBarang = "LM: " . ($gadai->logamMulia->nama_barang ?? 'Logam Mulia');
                $detailBarang = "Brand: {$gadai->logamMulia->brand} | Berat: {$gadai->logamMulia->berat} gr";
            } elseif ($gadai->retro) {
                $namaBarang = "Retro: " . ($gadai->retro->nama_barang ?? 'Barang');
                $detailBarang = "Ket: " . ($gadai->retro->keterangan ?? '-');
            }

            return [
                'no_gadai' => $gadai->no_gadai,
                'nasabah' => $gadai->nasabah->nama_lengkap ?? '-',
                'barang' => $namaBarang,
                'detail' => $detailBarang,
                'jt_lama' => Carbon::parse($p->detailGadai->jatuh_tempo)->format('d/m/Y'), // JT sebelum diupdate
                'jt_baru' => Carbon::parse($p->jatuh_tempo_baru)->format('d/m/Y'),
                'nominal_pembayaran' => (float)$p->nominal_admin, // Ini total jasa+denda+admin dari store tadi
                'metode' => strtoupper($p->metode_pembayaran)
            ];
        });

        return response()->json([
            'success' => true,
            'metadata' => [
                'total_dana_masuk' => $formattedPerpanjangan->sum('nominal_pembayaran'),
                'jumlah_transaksi' => $formattedPerpanjangan->count()
            ],
            'data' => $formattedPerpanjangan
        ]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
}