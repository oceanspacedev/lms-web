<?php

/**
 * Tombol dan modal tambah memakai kata "Tambah"/"Simpan", sama dengan halaman tambah. Kunci lain tetap dari Filament.
 */
return [

    'single' => [

        'label' => 'Tambah :label',

        'modal' => [

            'heading' => 'Tambah :label',

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

    ],

];
