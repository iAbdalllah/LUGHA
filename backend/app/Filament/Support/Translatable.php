<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;

/** Side-by-side Arabic / English inputs for a spatie-translatable JSON attribute. */
final class Translatable
{
    public static function input(string $attribute, string $label, bool $required = true, bool $textarea = false): Grid
    {
        $make = fn (string $locale) => ($textarea ? Textarea::make("$attribute.$locale")->rows(3) : TextInput::make("$attribute.$locale"))
            ->label($label.' — '.($locale === 'ar' ? 'العربية' : 'English'))
            ->extraInputAttributes(['dir' => $locale === 'ar' ? 'rtl' : 'ltr', 'lang' => $locale])
            ->required($required && $locale === 'ar');

        return Grid::make(2)->schema([$make('ar'), $make('en')])->columnSpanFull();
    }
}
