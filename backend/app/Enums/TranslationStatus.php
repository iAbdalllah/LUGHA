<?php

namespace App\Enums;

enum TranslationStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Published = 'published';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('status.translation.'.$this->value);
    }

    /** Allowed workflow moves. Publishing happens in bulk from Approved. */
    public function canMoveTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft => [self::InReview],
            self::InReview => [self::Approved, self::Rejected, self::Draft],
            self::Approved => [self::Published, self::InReview],
            self::Published => [self::InReview],
            self::Rejected => [self::Draft, self::InReview],
        }, true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
