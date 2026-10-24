<?php

declare(strict_types=1);

final class IssueLogger
{
    public function __construct(private string $path)
    {
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $encodedContext = $context === []
            ? ''
            : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents(
            $this->path,
            sprintf("%s [%s] %s%s\n", date(DATE_ATOM), $level, $message, $encodedContext),
            FILE_APPEND | LOCK_EX,
        );
    }
}
