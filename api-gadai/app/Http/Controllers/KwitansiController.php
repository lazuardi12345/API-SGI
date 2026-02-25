<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PelunasanLog;
use App\Models\PerpanjanganTempo;
use App\Models\Pelelangan;
use App\Models\RiwayatKwitansi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KwitansiController extends Controller
{
    public function cetak($jenis, $id)
    {
        try {
            $data = [
                'norek_list' => [
                    'BJB'       => '0146332781100 - WENDRI',
                    'BCA_RIVA'  => '5726303033 - RIVA SILVIA NABABAN',
                    'BCA_RISKA' => '5725556656 - RISKA ERVIA'
                ],
                'petugas_akses' => Auth::user()->name ?? 'Guest',
            ];

            // Mapping data berdasarkan jenis (Pelunasan/Perpanjangan/Lelang)
            if ($jenis == 'pelunasan') {
                $log = PelunasanLog::with(['detailGadai.nasabah', 'user'])->findOrFail($id);
                $this->mapPelunasan($data, $log);
                $prefix = "KW-PLN-";
            } elseif ($jenis == 'perpanjangan') {
                $log = PerpanjanganTempo::with(['detailGadai.nasabah'])->findOrFail($id);
                $this->mapPerpanjangan($data, $log);
                $prefix = "KW-PPJ-";
            } else {
                $log = Pelelangan::with(['detailGadai.nasabah'])->findOrFail($id);
                $this->mapLelang($data, $log);
                $prefix = "KW-LLG-";
            }

            // Cek apakah sudah ada di riwayat_kwitansi
            $riwayat = RiwayatKwitansi::where('jenis_transaksi', $jenis)->where('transaksi_id', $id)->first();

            if ($riwayat) {
                $data['no_kwitansi'] = $riwayat->no_kwitansi;
                $data['is_copy']     = ($riwayat->jumlah_cetak > 0); // Jika sudah pernah cetak, ini salinan
                $data['cetak_ke']    = $riwayat->jumlah_cetak + 1;
                $data['audit_info']  = $riwayat->jumlah_cetak > 0 ? "Original dicetak pd " . $riwayat->tgl_cetak_pertama : "Original";
            } else {
                // Buat record baru jika belum ada
                $newKwitansi = DB::transaction(function () use ($jenis, $id, $prefix) {
                    $record = RiwayatKwitansi::create([
                        'no_kwitansi' => 'TEMP',
                        'jenis_transaksi' => $jenis,
                        'transaksi_id' => $id,
                        'user_id' => Auth::id(),
                        'jumlah_cetak' => 0
                    ]);
                    $noGenerated = $prefix . str_pad($record->id, 6, '0', STR_PAD_LEFT);
                    $record->update(['no_kwitansi' => $noGenerated]);
                    return $record;
                });

                $data['no_kwitansi'] = $newKwitansi->no_kwitansi;
                $data['is_copy'] = false;
                $data['cetak_ke'] = 1;
                $data['audit_info'] = "Original";
            }

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

public function updateAuditCetak(Request $request)
{
    $request->validate(['no_kwitansi' => 'required']);
    
    $riwayat = RiwayatKwitansi::where('no_kwitansi', $request->no_kwitansi)->first();
    
    if (!$riwayat) {
        \Log::error("❌ Kwitansi tidak ditemukan: " . $request->no_kwitansi);
        return response()->json(['success' => false, 'message' => 'Not found'], 404);
    }

    try {
        DB::transaction(function () use ($riwayat) {
            $now = now();
            
            // Jika belum pernah cetak
            if ($riwayat->jumlah_cetak == 0) {
                $riwayat->tgl_cetak_pertama = $now;
            }
            
            // Increment jumlah cetak
            $riwayat->jumlah_cetak += 1;
            $riwayat->tgl_cetak_terakhir = $now;
            
            $riwayat->save();
            
            \Log::info("✅ Update audit berhasil: {$riwayat->no_kwitansi}, Cetak ke-{$riwayat->jumlah_cetak}");
        });

        return response()->json([
            'success' => true, 
            'data' => [
                'jumlah_cetak' => $riwayat->jumlah_cetak,
                'tgl_cetak_pertama' => $riwayat->tgl_cetak_pertama,
                'tgl_cetak_terakhir' => $riwayat->tgl_cetak_terakhir
            ]
        ]);
        
    } catch (\Exception $e) {
        \Log::error("❌ Error update audit: " . $e->getMessage());
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

    public function riwayatHariIni(Request $request)
{
    $tipe = (int)$request->tipe;
    $tanggal = $request->tanggal ? Carbon::parse($request->tanggal) : Carbon::today();
    if ($tipe === 0) {
        $items = PelunasanLog::with(['detailGadai.nasabah', 'user'])
            ->whereDate('tanggal_bayar', $tanggal)
            ->latest()->get();
        $jenis = 'pelunasan';
    } elseif ($tipe === 1) {
        $items = PerpanjanganTempo::with(['detailGadai.nasabah', 'user'])
            ->whereDate('tanggal_bayar', $tanggal)
            ->where('status_bayar', 'lunas')
            ->latest()->get();
        $jenis = 'perpanjangan';
    } else {
        $items = Pelelangan::with(['detailGadai.nasabah', 'user'])
            ->whereDate('waktu_bayar', $tanggal)
            ->latest()->get();
        $jenis = 'lelang';
    }
    $res = $items->map(function($item) use ($jenis) {
        return $this->transformRiwayat($item, $jenis);
    });

    return response()->json(['success' => true, 'data' => $res]);
}

    private function mapPelunasan(&$data, $log) {
        $data['nasabah'] = $log->detailGadai->nasabah->nama_lengkap ?? 'N/A';
        $data['metode']  = $log->metode_pembayaran;
        $data['tanggal'] = Carbon::parse($log->tanggal_bayar)->format('d/m/Y H:i');
        $data['untuk']   = "PELUNASAN GADAI (" . $log->detailGadai->no_gadai . ")";
        $data['rincian'] = [
            'Uang Pinjaman (Pokok)' => (float)$log->pokok,
            'Denda Keterlambatan'   => (float)$log->denda,
            'Biaya Penalty'         => (float)$log->penalty,
        ];
        $data['total']   = (float)$log->total_bayar;
    }

    private function mapPerpanjangan(&$data, $log) {
        $data['nasabah'] = $log->detailGadai->nasabah->nama_lengkap ?? 'N/A';
        $data['metode']  = $log->metode_pembayaran;
        $data['tanggal'] = Carbon::parse($log->tanggal_bayar ?? $log->created_at)->format('d/m/Y H:i');
        $data['untuk']   = "PERPANJANGAN (Tempo Baru: " . Carbon::parse($log->jatuh_tempo_baru)->format('d/m/Y') . ")";
        $data['rincian'] = [
            'Biaya Jasa'    => (float)$log->nominal_jasa,
            'Denda'         => (float)$log->nominal_denda,
            'Administrasi'  => (float)$log->nominal_admin,
        ];
        $data['total']   = (float)$log->total_bayar;
    }

    private function mapLelang(&$data, $log) {
        $data['nasabah'] = $log->detailGadai->nasabah->nama_lengkap ?? 'N/A';
        $data['metode']  = $log->metode_pembayaran;
        $data['tanggal'] = Carbon::parse($log->waktu_bayar)->format('d/m/Y H:i');
        $data['untuk']   = ($log->status_lelang == 'lunas' ? "TEBUS LELANG" : "BELI LELANG") . " (" . $log->detailGadai->no_gadai . ")";
        $data['rincian'] = ['Nominal Diterima' => (float)$log->nominal_diterima];
        $data['total']   = (float)$log->nominal_diterima;
    }

    private function getPelunasanToday($date) {
        return PelunasanLog::with(['detailGadai.nasabah', 'user'])
            ->whereDate('tanggal_bayar', $date)
            ->latest()
            ->get()
            ->map(fn($q) => $this->transformRiwayat($q, 'pelunasan'));
    }

    private function getPerpanjanganToday($date) {
        return PerpanjanganTempo::with(['detailGadai.nasabah', 'user']) 
            ->whereDate('tanggal_bayar', $date)
            ->where('status_bayar', 'lunas')
            ->latest()
            ->get()
            ->map(fn($q) => $this->transformRiwayat($q, 'perpanjangan'));
    }

    private function getLelangToday($date) {
        return Pelelangan::with(['detailGadai.nasabah', 'user']) 
            ->whereDate('waktu_bayar', $date)
            ->whereIn('status_lelang', ['terlelang', 'lunas'])
            ->latest()
            ->get()
            ->map(fn($q) => $this->transformRiwayat($q, 'lelang'));
    }

private function transformRiwayat($item, $jenis) {
    $riwayat = \App\Models\RiwayatKwitansi::where('jenis_transaksi', $jenis)
                ->where('transaksi_id', $item->id)
                ->first();
    
    return [
        'id'          => $item->id,
        'jenis'       => $jenis,
        'no_kwitansi' => $riwayat ? $riwayat->no_kwitansi : '-', 
        'waktu'       => Carbon::parse($item->tanggal_bayar ?? $item->waktu_bayar)->format('H:i'),
        'nasabah'     => $item->detailGadai->nasabah->nama_lengkap ?? 'N/A',
        'no_gadai'    => $item->detailGadai->no_gadai,
        'total_bayar' => (float)($item->total_bayar ?? $item->nominal_diterima),
        'metode'      => $item->metode_pembayaran,
        'petugas'     => $item->user->name ?? 'System', 
        
        'jumlah_cetak'       => $riwayat ? $riwayat->jumlah_cetak : 0,
        'tgl_cetak_pertama'  => $riwayat && $riwayat->tgl_cetak_pertama 
                                ? Carbon::parse($riwayat->tgl_cetak_pertama)->format('H:i:s') 
                                : '-',
        'tgl_cetak_terakhir' => $riwayat && $riwayat->tgl_cetak_terakhir 
                                ? Carbon::parse($riwayat->tgl_cetak_terakhir)->format('H:i:s') 
                                : '-'
    ];
}
}