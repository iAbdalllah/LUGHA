<?php

namespace App\Jobs;

use App\Domain\Localization\TranslationTransfer;
use App\Models\Game;
use App\Models\LocalizationStyle;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Imports an uploaded translations file in the background (a large file takes minutes — longer than a page request may
 * run) and tells the staff member who uploaded it in the admin's notifications when it is done.
 */
class ImportTranslationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    /** A failed run may already have saved part of the file; it is not retried blindly — the user re-uploads. */
    public int $tries = 1;

    public function __construct(
        public string $file, // path on the local disk
        public Game $game,
        public LocalizationStyle $style,
        public User $user,
    ) {}

    public function handle(TranslationTransfer $transfer): void
    {
        Auth::setUser($this->user); // translator_id and revision authorship
        $path = Storage::disk('local')->path($this->file);
        $format = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $format = $format === 'txt' ? 'csv' : $format;

        try {
            $stats = $transfer->import($path, $format, $this->game, $this->style);
        } catch (\Throwable $e) {
            Notification::make()->title(__('Import failed'))->body($this->game->title.': '.$e->getMessage())->danger()->sendToDatabase($this->user);

            throw $e;
        } finally {
            Storage::disk('local')->delete($this->file);
        }

        $body = $this->game->title.': '.__(':c created, :u updated, :n unchanged', ['c' => $stats['created'], 'u' => $stats['updated'], 'n' => $stats['unchanged']]);
        if ($stats['errors']) {
            $body .= "\n".collect($stats['errors'])->take(10)->map(fn ($m, $line) => __('Line :l: :m', ['l' => $line, 'm' => $m]))->implode("\n");
        }
        Notification::make()->title(__('Import finished'))->body($body)->{$stats['errors'] ? 'warning' : 'success'}()->sendToDatabase($this->user);
    }
}
