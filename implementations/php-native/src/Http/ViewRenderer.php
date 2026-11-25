<?php
declare(strict_types=1);

namespace Talyvane\Http;

/**
 * Renders only trusted, application-owned PHP templates from one fixed directory.
 * Template names are logical identifiers; request input must never become a filesystem path.
 */
final class ViewRenderer
{
    private string $templateRoot;

    public function __construct(string $templateRoot)
    {
        $resolved = realpath($templateRoot);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Template directory does not exist.');
        }
        $this->templateRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /** @param array<string,mixed> $data */
    public function render(string $view, array $data = []): string
    {
        if (!preg_match('/^[a-z0-9_-]+(?:\/[a-z0-9_-]+)*$/D', $view)) {
            throw new \InvalidArgumentException('Template identifier is invalid.');
        }

        $candidate = $this->templateRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $view) . '.php';
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) ||
            !str_starts_with($resolved, $this->templateRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Template is not available.');
        }

        $escape = static fn(mixed $value): string => htmlspecialchars(
            is_scalar($value) || $value === null ? (string) $value : '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $data = $data;
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            require $resolved;
            $output = ob_get_contents();
            if (!is_string($output)) {
                throw new \RuntimeException('Template output could not be captured.');
            }
            ob_end_clean();
            return $output;
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }
}
