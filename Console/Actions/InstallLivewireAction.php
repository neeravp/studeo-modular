<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class InstallLivewireAction
{
    public const COMMAND = ['composer', 'require', 'livewire/livewire:^4.0'];

    public function __construct(private readonly ?string $root = null) {}

    public function execute(): void
    {
        $result = Process::path($this->root ?? base_path())->timeout(300)->run(self::COMMAND);

        if (! $result->successful()) {
            throw new RuntimeException('Failed to install Livewire: '.trim($result->errorOutput() ?: $result->output()));
        }
    }
}
