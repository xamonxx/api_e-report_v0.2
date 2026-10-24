<?php

namespace App\Support;

use App\Models\Account;

final class ActiveAccount
{
    private ?Account $account = null;

    public function set(Account $account): void
    {
        $this->account = $account;
    }

    public function id(): ?int
    {
        return $this->account?->id;
    }

    public function account(): ?Account
    {
        return $this->account;
    }
}
