<?php

namespace App\Jobs;

use App\Domain\Delivery\BuildPackageVersion;
use App\Models\PackageVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildPackageVersionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public PackageVersion $version) {}

    public function uniqueId(): string
    {
        return (string) $this->version->id;
    }

    public function handle(BuildPackageVersion $build): void
    {
        $build->handle($this->version);
    }
}
