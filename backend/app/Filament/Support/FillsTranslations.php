<?php

namespace App\Filament\Support;

/** For Edit pages: load every locale of translatable attributes into the form, not just the current one. */
trait FillsTranslations
{
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->getRecord()->translatable ?? [] as $attribute) {
            $data[$attribute] = $this->getRecord()->getTranslations($attribute);
        }

        return $data;
    }
}
