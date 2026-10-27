<?php

namespace App\Support;

final class GreenMudaRoster
{
    public const GROUPS = [
        'PC' => [
            'OMEN' => ['HOME PUTRA INTERIOR', 'NACIRA INTERIOR'],
            'AGIL' => ['HEYA INTERIOR'],
            'SRI' => ['FURNITURE CIMAHI'],
            'BILAL' => ['ZODIAK INTERIOR'],
            'YONAS' => ['AKBAR INTERIOR'],
            'AGUNG' => ['DEKOR INTERIOR', 'INTERHOUSE'],
            'AZAM' => ['SAVOY INTERIOR'],
            'UNIR' => ['BROTO INTERIOR', 'INTERIOR SUKABUMI'],
            'ANO' => ['RAYSA INTERIOR', 'SOLUTION INTERIOR'],
            'HASNI' => [
                'PORTO INTERIOR',
                'KITCHENSET SOLUTION BANDUNG',
                'MEDIAN INTERIOR',
                'INTERIOR KITA',
                'ALLFAR INTERIOR',
            ],
            'LISA' => ['MEWAH INTERIOR'],
            'DENA' => ['KURNIA INTERIOR'],
        ],
        'INSIP' => ['ANNISA' => ['PUTRA MOULDING', 'RUMA FURNITURE']],
        'MITRA SDK' => ['Sandika' => ['ARGO INTERIOR', 'HOME SAVOY INTERIOR', 'YUREE INTERIOR']],
        'MITRA BSR' => ['OCAN' => ['KEJORA INTERIOR', 'GIBRAN INTERIOR']],
        'PRIBADI' => [
            'DIAN G' => ['FULLHOME ID', 'NISCALA FURNITURE'],
            'HASAN' => ['HOME INTERIOR BANDUNG'],
            'OMEN' => ['PUTRA INTERIOR'],
            'YANWAR' => ['PARTNER INTERIOR', 'ELVAN INTERIOR'],
        ],
    ];

    public const SUPER_ADMIN = [
        'name' => 'SUPER ADMIN',
        'email' => 'superadmin@ereport.app',
    ];

    public const SURVEYORS = ['ADI', 'AGIL', 'DIKI', 'ADAM', 'BAGJA', 'LUKI', 'RAFLI'];

    public static function accounts(): array
    {
        $rows = [];
        foreach (self::GROUPS as $group => $admins) {
            foreach ($admins as $admin => $accounts) {
                foreach ($accounts as $account) {
                    $rows[] = compact('group', 'admin', 'account');
                }
            }
        }

        return $rows;
    }
}
