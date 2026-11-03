<?php

namespace App\Support;

final class GreenMudaRoster
{
    public const GROUPS = [
        'PC' => [
            'OMEN' => ['HOME PUTRA INTERIOR', 'NACIRA INTERIOR'],
            'AGIL' => ['HEYA INTERIOR'],
            'BILAL' => ['ZODIAK INTERIOR'],
            'YONAS' => ['AKBAR INTERIOR'],
            'AZAM' => ['SAVOY INTERIOR'],
            'UNIR' => ['INTERIOR SUKABUMI', 'DEKOR INTERIOR'],
            'HASNI' => ['PORTO INTERIOR', 'KITCHENSET SOLUTION BANDUNG'],
            'ANO' => ['RAYSA INTERIOR', 'INTERHOUSE'],
            'ADI' => ['FURNITURE CIMAHI', 'MEWAH INTERIOR'],
        ],
        'INSIP' => [
            'ANNISA' => ['PUTRA MOULDING', 'RUMA FURNITURE', 'SOLUTION INTERIOR'],
        ],
        'MITRA SDK' => [
            'SANDIKA' => ['ARGO INTERIOR', 'HOME SAVOY INTERIOR', 'YUREE INTERIOR'],
        ],
        'MITRA BSR' => [
            'OCAN' => ['KEJORA INTERIOR', 'GIBRAN INTERIOR'],
        ],
        'PRIBADI' => [
            'DIAN G' => ['FULLHOME', 'NISCALA FURNITURE'],
            'HASAN' => ['HOME INTERIOR BANDUNG'],
            'OMEN' => ['PUTRA INTERIOR'],
            'YANWAR' => ['PARTNER INTERIOR', 'ELVAN INTERIOR'],
            'DENA' => ['KURNIA INTERIOR'],
            'AGUNG' => ['BROTO INTERIOR', 'MEDIAN INTERIOR'],
        ],
    ];

    public const SUPER_ADMIN = [
        'name' => 'SUPER ADMIN',
        'email' => 'superadmin@ereport.app',
    ];

    public const SURVEYORS = ['ADI', 'AGIL', 'DIKI', 'ADAM', 'BAGJA', 'LUKI', 'RAFLI'];

    public const ACCOUNT_IDS = [
        'HOME PUTRA INTERIOR' => 1,
        'RAYSA INTERIOR' => 4,
        'HOME INTERIOR BANDUNG' => 7,
        'PUTRA INTERIOR' => 9,
        'INTERHOUSE' => 10,
        'KITCHENSET SOLUTION BANDUNG' => 11,
        'HEYA INTERIOR' => 12,
        'MEDIAN INTERIOR' => 13,
        'FULLHOME' => 16,
        'NACIRA INTERIOR' => 17,
        'AKBAR INTERIOR' => 20,
        'KURNIA INTERIOR' => 36,
        'NISCALA FURNITURE' => 37,
        'PARTNER INTERIOR' => 38,
        'FURNITURE CIMAHI' => 39,
        'SAVOY INTERIOR' => 40,
        'MEWAH INTERIOR' => 41,
        'KEJORA INTERIOR' => 42,
        'ZODIAK INTERIOR' => 43,
        'DEKOR INTERIOR' => 44,
        'PORTO INTERIOR' => 45,
        'ARGO INTERIOR' => 46,
        'PUTRA MOULDING' => 47,
        'RUMA FURNITURE' => 48,
        'GIBRAN INTERIOR' => 49,
        'INTERIOR SUKABUMI' => 50,
        'SOLUTION INTERIOR' => 51,
        'BROTO INTERIOR' => 52,
        'ELVAN INTERIOR' => 53,
        'YUREE INTERIOR' => 54,
        'HOME SAVOY INTERIOR' => 55,
    ];

    public static function accounts(): array
    {
        $rows = [];
        foreach (self::GROUPS as $group => $admins) {
            foreach ($admins as $admin => $accounts) {
                foreach ($accounts as $account) {
                    $id = self::ACCOUNT_IDS[$account];
                    $rows[] = compact('id', 'group', 'admin', 'account');
                }
            }
        }

        return $rows;
    }
}
