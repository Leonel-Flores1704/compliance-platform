<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

trait Auditable
{
    /**
     * Wrap model save in a database transaction to ensure audit failures roll back changes.
     */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn () => parent::save($options));
    }

    /**
     * Wrap model delete in a database transaction.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn () => parent::delete());
    }
}
