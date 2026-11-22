<?php

namespace App\Filament\Resources\Translations\Pages;

use App\Enums\TranslationStatus;
use App\Filament\Resources\Translations\TranslationResource;
use App\Models\TranslationGlossary;
use Filament\Resources\Pages\EditRecord;

class EditTranslation extends EditRecord
{
    protected static string $resource = TranslationResource::class;

    public function getSubheading(): ?string
    {
        $r = $this->getRecord();
        $terms = TranslationGlossary::query()->where(fn ($q) => $q->whereNull('game_id')->orWhere('game_id', $r->game_id))->get()
            ->filter(fn ($g) => mb_stripos($r->source_text, $g->term) !== false)
            ->map(fn ($g) => $g->do_not_translate ? "«{$g->term}» ".__('do not translate') : "«{$g->term}» → «{$g->translation}»");

        return $r->status->label().($terms->isNotEmpty() ? ' · '.__('Glossary').': '.$terms->implode(' · ') : '');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Editing approved/published wording sends it back to review.
        if (($data['target_text'] ?? null) !== $this->getRecord()->target_text
            && in_array($this->getRecord()->status, [TranslationStatus::Approved, TranslationStatus::Published], true)) {
            $data['status'] = TranslationStatus::InReview->value;
        }

        return $data;
    }
}
