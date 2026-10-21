<?php

namespace Studeo\Modular\Console\Prompts;

interface PromptConsole
{
    /**
     * Whether a prompt can be answered right now. Asked at the moment of use, never remembered.
     */
    public function canPrompt(): bool;

    /**
     * @return void
     */
    public function line(string $string);

    /**
     * A line in the warning style.
     *
     * @return void
     */
    public function warn(string $string);

    /**
     * Says which module was picked. A command whose standard output is data overrides this.
     */
    public function announceModule(string $message): void;

    /**
     * A line in the "INFO" badge style, unlike the plain info line of a command.
     */
    public function infoBadge(string $message): void;
}
