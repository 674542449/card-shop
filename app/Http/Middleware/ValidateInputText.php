<?php

namespace App\Http\Middleware;

use App\Rules\Utf8Text;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Reject text PostgreSQL/JSON cannot represent without inspecting uploaded bytes. */
final class ValidateInputText
{
    public function handle(Request $request, Closure $next): Response
    {
        // Invalid JSON encoding otherwise silently becomes an empty input bag.
        // Multipart bodies may contain image bytes and must not be checked here.
        if ($request->isJson()) {
            $this->text($request->getContent());
        }
        $this->values($request->input());
        // Validate multipart field names, while UploadedFile instances stay opaque.
        $this->values($request->files->all());
        $this->text(rawurldecode($request->getPathInfo()));
        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            $this->text((string) $name);
            $this->values($value);
        }

        return $next($request);
    }

    private function values(mixed $value): void
    {
        if (is_string($value)) {
            $this->text($value);
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->text((string) $key);
                $this->values($item);
            }
        }
    }

    private function text(string $value): void
    {
        if (! Utf8Text::isValid($value)) {
            // Its bytes would make JSON serialization fail a second time.
            throw ValidationException::withMessages(['input' => '输入文本必须是有效 UTF-8，且不能包含空字符。']);
        }
    }
}
