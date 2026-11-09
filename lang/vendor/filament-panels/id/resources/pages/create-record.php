<?php

/**
 * Halaman tambah memakai kata "Tambah" agar sama dengan tombol di daftar (mis. "Tambah User"), bukan "Buat" bawaan Filament.
 * Kunci yang tidak ditulis di sini tetap memakai terjemahan Filament.
 */
return [

    'title' => 'Tambah :label',

    'breadcrumb' => 'Tambah',

    'form' => [

        'actions' => [

            'create' => [
                'label' => 'Simpan',
            ],

            'create_another' => [
                'label' => 'Simpan & tambah lagi',
            ],

        ],

    ],

    'notifications' => [

        'created' => [
            'title' => 'Data berhasil disimpan',
        ],

    ],

];
