<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use App\Models\MeterReading;
use Illuminate\Http\Request;

/**
 * Pembatas kepemilikan data pencatatan meter.
 *
 * Admin bebas akses semua. Teknisi boleh data yang ia catat sendiri ATAU
 * data pelanggan di wilayahnya (tiket yang ia tangani). Pelanggan tidak
 * pernah sampai ke controller ini karena route-nya dikunci
 * `role:admin,teknisi`.
 *
 * CATATAN KONSISTENSI
 * -------------------
 * Trait ini disamakan dengan pamsides-v2. Kalau astaloka memakai cakupan
 * lebih sempit (hanya `recorded_by`) sementara pamsides memakai cakupan
 * lebih luas (wilayah tiket), maka teknisi yang sama akan melihat
 * pelanggan yang berbeda di dua aplikasi — dan klien akan menanyakan
 * "kenapa datanya beda?".
 */
trait AuthorizesMeterReadings
{
    /**
     * Batasi query hanya ke catatan milik user saat ini (kecuali admin).
     */
    protected function scopeMeterReadingsToOwner($query, Request $request)
    {
        if ($this->isPrivileged($request)) {
            return $query;
        }

        return $query->where('recorded_by', $request->user()?->id);
    }

    /**
     * True bila user boleh menyentuh catatan milik siapa pun.
     */
    protected function isPrivileged(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }

    /**
     * Tolak akses ke catatan milik orang lain dengan 403.
     *
     * Teknisi boleh catatan yang ia catat sendiri ATAU catatan pelanggan di
     * wilayahnya (tiket yang ia tangani) — disamakan dengan admin yang
     * bebas untuk semua. Catatan milik teknisi lain untuk pelanggan DI
     * LUAR wilayahnya tetap ditolak.
     */
    protected function ensureMeterReadingOwner(Request $request, MeterReading $reading): void
    {
        if ($this->isPrivileged($request)) {
            return;
        }

        if ((int) $reading->recorded_by === (int) $request->user()?->id) {
            return;
        }

        if ($this->isWithinTechnicianArea($reading->customer, $request)) {
            return;
        }

        abort(403, 'Akses ditolak. Catatan meter ini milik teknisi lain.');
    }

    /**
     * True bila pelanggan ini berada di WILAYAH kerja teknisi.
     *
     * Sumber kebenaran: `installation_tickets.user_id` = teknisi yang
     * menangani instalasi pelanggan tersebut (relasi
     * `customers.ticket_id` → `installation_tickets.user_id`).
     *
     * Ini dasar scoping yang sama dengan yang dipakai
     * `MonthlyBillController` (index/unpaidSummary/suspended/show), jadi
     * satu pelanggan tidak bisa muncul sebagai "milik teknisi A" di satu
     * halaman lalu "milik teknisi B" di halaman lain.
     */
    protected function isWithinTechnicianArea(?Customer $customer, Request $request): bool
    {
        $user = $request->user();

        if (! $user || ! $customer) {
            return false;
        }

        return (int) $customer->ticket?->user_id === (int) $user->id;
    }
}
