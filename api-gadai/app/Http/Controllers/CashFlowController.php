<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Penggajian;
use Illuminate\Support\Facades\DB;

class CashFlowController extends Controller
{

    public function index(Request $request)
    {
        try {
            Carbon::setLocale('id');

            $startDate = $request->filled('tanggal_mulai')
                ? Carbon::parse($request->tanggal_mulai)->toDateString()
                : Carbon::now()->startOfWeek()->toDateString();

            $endDate = $request->filled('tanggal_selesai')
                ? Carbon::parse($request->tanggal_selesai)->toDateString()
                : Carbon::now()->endOfWeek()->toDateString();

            $laporanTabel      = [];
            $no                = 1;
            $grandTotalPemasukan   = 0;
            $grandTotalPengeluaran = 0;

            $gadaiBaru = DB::table('detail_gadai')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    DB::raw('DATE(detail_gadai.tanggal_gadai) as tanggal'),
                    'types.nama_type',
                    DB::raw('COUNT(*) as qty'),
                    DB::raw('SUM(CAST(detail_gadai.uang_pinjaman AS UNSIGNED)) as total_nominal')
                )
                ->whereBetween('detail_gadai.tanggal_gadai', [$startDate, $endDate])
                ->whereNull('detail_gadai.deleted_at')
                ->groupBy('tanggal', 'types.nama_type')
                ->orderBy('tanggal', 'asc')
                ->get();

            foreach ($gadaiBaru as $gb) {
                $nominal = (float) $gb->total_nominal;
                $laporanTabel[] = [
                    'no'          => $no++,
                    'tanggal'     => Carbon::parse($gb->tanggal)->translatedFormat('d/m/Y'),
                    'tanggal_raw' => $gb->tanggal,
                    'keterangan'  => 'Pencairan Gadai: ' . $gb->nama_type,
                    'qty'         => (int) $gb->qty,
                    'pemasukan'   => 0,
                    'pengeluaran' => $nominal,
                ];
                $grandTotalPengeluaran += $nominal;
            }

            $pelunasan = DB::table('detail_gadai')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    DB::raw('DATE(detail_gadai.tanggal_bayar) as tanggal'),
                    'types.nama_type',
                    DB::raw('COUNT(*) as qty'),
                    DB::raw('SUM(CAST(detail_gadai.nominal_bayar AS UNSIGNED)) as total_nominal')
                )
                ->where('detail_gadai.status', 'lunas')
                ->whereBetween('detail_gadai.tanggal_bayar', [$startDate, $endDate])
                ->whereNull('detail_gadai.deleted_at')
                ->groupBy('tanggal', 'types.nama_type')
                ->orderBy('tanggal', 'asc')
                ->get();

            foreach ($pelunasan as $p) {
                $nominal = (float) $p->total_nominal;
                $laporanTabel[] = [
                    'no'          => $no++,
                    'tanggal'     => Carbon::parse($p->tanggal)->translatedFormat('d/m/Y'),
                    'tanggal_raw' => $p->tanggal,
                    'keterangan'  => 'Pelunasan: ' . $p->nama_type,
                    'qty'         => (int) $p->qty,
                    'pemasukan'   => $nominal,
                    'pengeluaran' => 0,
                ];
                $grandTotalPemasukan += $nominal;
            }

            $perpanjangan = DB::table('perpanjangan_tempo')
                ->join('detail_gadai', 'perpanjangan_tempo.detail_gadai_id', '=', 'detail_gadai.id')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    DB::raw('DATE(perpanjangan_tempo.tanggal_perpanjangan) as tanggal'),
                    'types.nama_type',
                    DB::raw('COUNT(*) as qty'),
                    DB::raw('SUM(CAST(perpanjangan_tempo.nominal_admin AS UNSIGNED)) as total_admin')
                )
                ->whereBetween('perpanjangan_tempo.tanggal_perpanjangan', [$startDate, $endDate])
                ->groupBy('tanggal', 'types.nama_type')
                ->orderBy('tanggal', 'asc')
                ->get();

            foreach ($perpanjangan as $pj) {
                $nominal = (float) $pj->total_admin;
                $laporanTabel[] = [
                    'no'          => $no++,
                    'tanggal'     => Carbon::parse($pj->tanggal)->translatedFormat('d/m/Y'),
                    'tanggal_raw' => $pj->tanggal,
                    'keterangan'  => 'Admin Perpanjangan: ' . $pj->nama_type,
                    'qty'         => (int) $pj->qty,
                    'pemasukan'   => $nominal,
                    'pengeluaran' => 0,
                ];
                $grandTotalPemasukan += $nominal;
            }

            $pelelangan = DB::table('pelelangan')
                ->select(
                    DB::raw('DATE(waktu_bayar) as tanggal'),
                    'status_lelang',
                    DB::raw('COUNT(*) as qty'),
                    DB::raw('SUM(nominal_diterima) as total_nominal')
                )
                ->whereBetween('waktu_bayar', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->whereIn('status_lelang', ['terlelang', 'lunas'])
                ->groupBy('tanggal', 'status_lelang')
                ->orderBy('tanggal', 'asc')
                ->get();

            foreach ($pelelangan as $lel) {
                $nominal = (float) $lel->total_nominal;
                $label   = strtolower($lel->status_lelang) === 'lunas'
                    ? 'Pelunasan Lelang'
                    : 'Pelelangan';

                $laporanTabel[] = [
                    'no'          => $no++,
                    'tanggal'     => Carbon::parse($lel->tanggal)->translatedFormat('d/m/Y'),
                    'tanggal_raw' => $lel->tanggal,
                    'keterangan'  => $label,
                    'qty'         => (int) $lel->qty,
                    'pemasukan'   => $nominal,
                    'pengeluaran' => 0,
                ];
                $grandTotalPemasukan += $nominal;
            }

            $brankas = DB::table('transaksi_brankas')
                ->select(
                    DB::raw('DATE(created_at) as tanggal'),
                    'kategori',
                    DB::raw('COUNT(*) as qty'),
                    DB::raw('SUM(pemasukan) as total_pemasukan'),
                    DB::raw('SUM(pengeluaran) as total_pengeluaran')
                )
                ->where('status_validasi', 'tervalidasi')
                ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->groupBy('tanggal', 'kategori')
                ->orderBy('tanggal', 'asc')
                ->get();

            $labelBrankas = [
                'topup_pusat'      => 'Modal dari Pusat',
                'setor_ke_admin'   => 'Setor ke Admin',
                'operasional_toko' => 'Operasional Toko',
            ];

            foreach ($brankas as $b) {
                $masuk  = (float) $b->total_pemasukan;
                $keluar = (float) $b->total_pengeluaran;

                $laporanTabel[] = [
                    'no'          => $no++,
                    'tanggal'     => Carbon::parse($b->tanggal)->translatedFormat('d/m/Y'),
                    'tanggal_raw' => $b->tanggal,
                    'keterangan'  => $labelBrankas[$b->kategori] ?? $b->kategori,
                    'qty'         => (int) $b->qty,
                    'pemasukan'   => $masuk,
                    'pengeluaran' => $keluar,
                ];
                $grandTotalPemasukan   += $masuk;
                $grandTotalPengeluaran += $keluar;
            }

            $operasional = DB::table('operasional')
                ->select(
                    DB::raw('DATE(tanggal) as tanggal'),
                    DB::raw('COUNT(*) as qty'),
                    DB::raw('SUM(nominal) as total_nominal'),
                    DB::raw('GROUP_CONCAT(deskripsi SEPARATOR ", ") as deskripsi_list')
                )
                ->whereBetween('tanggal', [$startDate, $endDate])
                ->groupBy('tanggal')
                ->orderBy('tanggal', 'asc')
                ->get();

            foreach ($operasional as $ops) {
                $nominal = (float) $ops->total_nominal;
                $laporanTabel[] = [
                    'no'          => $no++,
                    'tanggal'     => Carbon::parse($ops->tanggal)->translatedFormat('d/m/Y'),
                    'tanggal_raw' => $ops->tanggal,
                    'keterangan'  => 'Biaya Operasional: ' . $ops->deskripsi_list,
                    'qty'         => (int) $ops->qty,
                    'pemasukan'   => 0,
                    'pengeluaran' => $nominal,
                ];
                $grandTotalPengeluaran += $nominal;
            }

            $startCarbon = Carbon::parse($startDate);
            $endCarbon   = Carbon::parse($endDate);

            $penggajian = Penggajian::where(function ($q) use ($startCarbon, $endCarbon) {
                    $q->whereRaw('CONCAT(tahun, "-", LPAD(bulan, 2, "0")) >= ?', [$startCarbon->format('Y-m')])
                      ->whereRaw('CONCAT(tahun, "-", LPAD(bulan, 2, "0")) <= ?', [$endCarbon->format('Y-m')]);
                })
                ->orderBy('tahun')->orderBy('bulan')
                ->get();

            foreach ($penggajian as $gaji) {
                $nominal  = (float) $gaji->total_gaji;
                $tglLabel = '01/' . str_pad($gaji->bulan, 2, '0', STR_PAD_LEFT) . '/' . $gaji->tahun;
                $tglRaw   = $gaji->tahun . '-' . str_pad($gaji->bulan, 2, '0', STR_PAD_LEFT) . '-01';

                $laporanTabel[] = [
                    'no'          => $no++,
                    'tanggal'     => $tglLabel,
                    'tanggal_raw' => $tglRaw,
                    'keterangan'  => 'Penggajian ' . $gaji->nama_bulan . ' ' . $gaji->tahun
                                     . ' (' . $gaji->jumlah_karyawan . ' karyawan)',
                    'qty'         => $gaji->jumlah_karyawan,
                    'pemasukan'   => 0,
                    'pengeluaran' => $nominal,
                ];
                $grandTotalPengeluaran += $nominal;
            }

            usort($laporanTabel, fn($a, $b) => strcmp($a['tanggal_raw'], $b['tanggal_raw']));
            foreach ($laporanTabel as $i => &$row) {
                $row['no'] = $i + 1;
                unset($row['tanggal_raw']);
            }
            unset($row);

            return response()->json([
                'success'  => true,
                'metadata' => [
                    'tipe_laporan'  => 'Laporan Cash Flow Gabungan',
                    'rentang_waktu' => Carbon::parse($startDate)->translatedFormat('d F Y')
                                       . ' s/d '
                                       . Carbon::parse($endDate)->translatedFormat('d F Y'),
                    'generated_at'  => now()->translatedFormat('d F Y H:i'),
                ],
                'data_tabel' => $laporanTabel,
                'summary' => [
                    'total_pemasukan'   => $grandTotalPemasukan,
                    'total_pengeluaran' => $grandTotalPengeluaran,
                    'selisih_kas'       => $grandTotalPemasukan - $grandTotalPengeluaran,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() . ' di baris ' . $e->getLine(),
            ], 500);
        }
    }
}