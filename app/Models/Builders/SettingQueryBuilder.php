<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use App\Models\Setting;
use App\Security\SecretSettings;
use App\Exceptions\SecretStorageException;

/** A setting's key is the encryption purpose and must accompany value reads. */
final class SettingQueryBuilder extends Builder
{
    public function insert(array $values)
    {
        if ($values === []) { return true; }
        $records = array_is_list($values) ? $values : [$values];
        $sealed = array_map(function (array $record): array {
            $setting = new Setting;
            $setting->forceFill($record);
            if (array_key_exists('value', $record)) {
                $setting->setAttribute('value', $record['value']);
            }

            return $setting->getAttributes();
        }, $records);

        return $this->query->insert($sealed);
    }

    public function update(array $values)
    {
        if (array_key_exists('value', $values)) {
            $key = (string) $this->model->getAttribute('key');
            if ($key === '') {
                // A multi-row settings update cannot bind one ciphertext to more
                // than one setting purpose. Use Setting::set()/setMany() instead.
                throw new SecretStorageException;
            }
            SecretSettings::decode($key, $values['value']);
        }

        return parent::update($values);
    }

    public function get($columns = ['*'])
    {
        $selected = $this->query->columns ?? (is_array($columns) ? $columns : [$columns]);
        if (in_array('value', $selected, true) || in_array('settings.value', $selected, true)) {
            if ($this->query->columns !== null) {
                $this->addSelect('settings.key');
            } else {
                $columns = array_unique([...$selected, 'settings.key']);
            }
        }

        return parent::get($columns);
    }

    public function pluck($column, $key = null)
    {
        if (is_string($column) && Str::afterLast($column, '.') === 'value') {
            $columns = ['settings.key', 'settings.value'];
            if ($key !== null) {
                $columns[] = $key;
            }

            return $this->get(array_unique($columns))->pluck('value', $key !== null ? Str::afterLast($key, '.') : null);
        }

        return parent::pluck($column, $key);
    }
}
