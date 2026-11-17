<?php

namespace App\Models\Builders;

use App\Models\Card;
use App\Security\SecretCipher;
use Illuminate\Database\Eloquent\Builder;

/** Bulk inserts do not execute Eloquent mutators, so seal them at this boundary. */
final class CardQueryBuilder extends Builder
{
    public function insert(array $values)
    {
        if ($values === []) {
            return true;
        }
        $records = array_is_list($values) ? $values : [$values];

        return $this->query->insert(array_map(fn (array $record) => Card::storageAttributes($record), $records));
    }

    public function update(array $values)
    {
        if (array_key_exists('content', $values)) {
            $cipher = app(SecretCipher::class);
            // Model::save passes its already-sealed attributes; a relationship or
            // bulk update passes logical plaintext, just like insert().
            $modelWrite = $this->model->exists
                && ($this->model->getAttributes()['content'] ?? null) === $values['content'];
            $plaintext = $modelWrite ? $cipher->decrypt((string) $values['content'], 'card-content')
                : (string) $values['content'];
            if (! $modelWrite) { $values['content'] = $cipher->encrypt($plaintext, 'card-content'); }
            $values['content_fingerprint'] = $cipher->fingerprint($plaintext);
        }

        return parent::update($values);
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_string($column) && in_array($column, ['content', 'cards.content'], true)) {
            $actualOperator = func_num_args() === 2 ? '=' : $operator;
            $plaintext = func_num_args() === 2 ? $operator : $value;
            if ($actualOperator === '=' && is_string($plaintext)) {
                return parent::where('cards.content_fingerprint', '=', app(SecretCipher::class)->fingerprint($plaintext), $boolean);
            }
        }

        return parent::where(...func_get_args());
    }
}
